<?php

namespace XD\PageCache\Extensions;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Caching\VersionedCacheAdapter;
use SilverStripe\Versioned\Versioned;

/**
 * Full-page cache for a page controller.
 *
 * On an eligible request the whole rendered page is stored in a dedicated cache pool and served
 * verbatim on subsequent requests, skipping the render (and most of the ORM). It is deliberately
 * conservative — it only serves a cached page to requests that are safe to serve identically to
 * every visitor:
 *   - the feature is enabled on the page ({@see PageExtension} adds the EnablePageCache field; a
 *     project can also force it on via a getEnablePageCache() override on its page/extension)
 *   - a plain GET of the page itself (no form/sub-action, not an AJAX request)
 *   - the published (Live) stage only — never draft or the CMS preview
 *   - the visitor does not bypass the cache (see {@see shouldBypassCacheForMember()})
 *
 * Because the cached HTML is shared across visitors, ANY per-session/per-member content must be kept
 * out of the server-rendered HTML and hydrated client-side instead (e.g. a CSRF token re-fetched by
 * JS, a basket count filled from a small JSON endpoint). Do not enable this on a page whose HTML
 * differs per visitor unless those differences are hydrated on the client.
 *
 * The cache key varies by full URL (incl. GET vars), the page's LastEdited (so publishing a page
 * invalidates it) and — when the controller exposes device flags (getIsMobile()/getIsPhone()/
 * getIsTablet(), e.g. from a mobile-detection extension) — by device variant. Those methods are
 * optional; without them a single variant is cached per URL.
 *
 * @property ContentController|PageControllerExtension $owner
 */
class PageControllerExtension extends Extension
{
    /**
     * When true, only members WITH CMS access (staff) bypass the cache; other logged-in users are
     * served the shared cached page. Only safe on sites whose cached pages are member-agnostic
     * (per-member bits hydrated client-side). Default false = every logged-in user bypasses the cache.
     * @config
     */
    private static bool $cache_for_members = false;

    /**
     * Whether the current logged-in user must bypass the page cache. Anonymous visitors never do.
     * By default any logged-in user bypasses; with cache_for_members on, only CMS/staff users do.
     */
    protected function shouldBypassCacheForMember(): bool
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            return false;
        }
        if (!$this->owner->config()->get('cache_for_members')) {
            return true;
        }
        return Permission::checkMember($member, ['CMS_ACCESS_LeftAndMain', 'ADMIN']);
    }

    /**
     * Device-variant portion of the cache key. Uses the controller's device flags when available
     * (e.g. a mobile-detection extension exposing getIsMobile()/getIsPhone()/getIsTablet());
     * otherwise returns an empty string so a single variant is cached per URL.
     */
    protected function cacheDeviceVariant(): string
    {
        if (!$this->owner->hasMethod('getIsMobile')) {
            return '';
        }
        return implode('-', [
            (int)$this->owner->getIsMobile(),
            (int)$this->owner->getIsPhone(),
            (int)$this->owner->getIsTablet(),
        ]);
    }

    public function onAfterInit()
    {
        $request = $this->owner->getRequest();

        // Only full-page-cache requests that are safe to serve verbatim to any visitor:
        //  - the feature is enabled on the page
        //  - a plain GET to the page itself (no sub-action, not AJAX)
        //  - the published (Live) stage only — never cache/serve draft or the CMS preview
        //  - not a bypassing member — anonymous always cacheable; logged-in users bypass unless
        //    cache_for_members is on, in which case only CMS/staff bypass (see the helper)
        if (
            !$this->owner->data()->EnablePageCache
            || !$request->isGET()
            || Director::is_ajax()
            || $request->param('Action')
            || $this->shouldBypassCacheForMember()
            || Versioned::get_stage() !== Versioned::LIVE
        ) {
            return;
        }

        $cacheLifetime = (int)$this->owner->data()->CacheLifetime;

        // Key on the FULL url incl. GET vars (pagination/sort/filter each need their own entry),
        // the page's LastEdited (auto-invalidate on publish) and the device variant.
        $cacheKey = md5(implode('-', [
            $request->getURL(true),
            $this->owner->LastEdited,
            $this->cacheDeviceVariant(),
        ]));

        /** @var VersionedCacheAdapter $cache */
        $cache = Injector::inst()->get(CacheInterface::class . '.pageCache');

        if (isset($_GET['flush'])) {
            $cache->clear();
        }
        if (isset($_GET['clearcache'])) {
            $cache->delete($cacheKey);
        }

        // Was this page already cached before this request? (distinguishes a served hit from a build.)
        $wasCached = $cache->has($cacheKey);

        // create new cache entry if not exists
        if (!$wasCached) {
            $rendered = (string)$this->owner->renderWith([$this->owner->data()->ClassName, 'Page']);
            // only cache a successful, non-empty render
            if ($rendered !== '' && $this->owner->getResponse()->getStatusCode() == 200) {
                $cache->set($cacheKey, $rendered, $cacheLifetime ?: null);
            }
        }

        $cachedPage = $cache->get($cacheKey);
        if ($cachedPage) {
            /** @var HTTPResponse $response */
            $response = $this->owner->getResponse();
            $response->setBody($cachedPage);
            $response->addHeader('X-Page-Cache', 'true');
            // true = served from an existing cache entry; false = built (and stored) on this request.
            $response->addHeader('X-Page-Cache-Hit', $wasCached ? 'true' : 'false');
            $response->addHeader('Content-Type', 'text/html');
            $response->output();
            exit(); // in dev mode debug info is suppressed by PageCacheDebugView
        }
    }
}
