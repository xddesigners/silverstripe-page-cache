# Silverstripe Page Cache

Opt-in **full-page cache** for Silverstripe CMS. When a page is eligible, its whole rendered HTML
is stored in a dedicated cache pool and served verbatim on later requests — skipping the template
render and most of the ORM. It's deliberately conservative: a cached page is only served to requests
that are safe to serve identically to every visitor.

- **Per-page opt-in** via an *Enable Page Cache* checkbox (Settings tab), or forced on in code.
- **Auto-invalidated on publish** (the cache key includes the page's `LastEdited`).
- **Anonymous-safe by default**; optionally serve cached pages to logged-in non-staff users too.
- **Device-variant aware** when your controllers expose mobile/phone/tablet flags (optional).
- Adds `X-Page-Cache` / `X-Page-Cache-Hit` response headers so you can see hits vs. builds.

> ⚠️ **The cached HTML is shared across visitors.** Any per-session or per-member content must be
> kept out of the server-rendered HTML and hydrated **client-side** instead (e.g. re-fetch a CSRF
> token with JS, fill a basket count from a small JSON endpoint). Don't enable caching on a page
> whose HTML legitimately differs per visitor unless those differences are hydrated on the client.

## Requirements

- PHP `^8.1`
- `silverstripe/framework` `^5 || ^6`, `silverstripe/cms` `^5 || ^6`, `silverstripe/versioned` `^2 || ^3`

Developed and tested against **Silverstripe CMS 6**; CMS 5 uses the same APIs but is currently untested.

## Installation

```bash
composer require xddesigners/silverstripe-page-cache
```

Then `dev/build?flush=all` (adds the `EnablePageCache` / `CacheLifetime` fields to `SiteTree`).

## Enabling the cache on a page

**Per page (CMS):** open a page → *Settings* tab → tick **Enable Page Cache**, optionally set a
**Cache Lifetime (seconds)** (0 = the pool default, 900s). Publish.

**Force it on in code** (e.g. for a whole page type without a DB flag) — override the field getters on
the page class or an extension of it:

```php
use SilverStripe\Core\Extension;

class ProductCachingExtension extends Extension
{
    public function getEnablePageCache(): bool
    {
        return true;
    }

    public function getCacheLifetime(): int
    {
        return 1800; // 30 min
    }
}
```

Because the extension reads `$page->EnablePageCache` / `$page->CacheLifetime` (which resolve through
`getEnablePageCache()` / `getCacheLifetime()` when present), such getters transparently force caching
on without touching the database.

## Caching for logged-in users

By default **every** logged-in user bypasses the cache (they get a fresh render). If your cached
pages are member-agnostic (no per-member content baked into the HTML — hydrate it client-side
instead), you can serve the shared cached page to logged-in non-staff users, so only CMS/admin users
bypass:

```yaml
SilverStripe\CMS\Controllers\ContentController:
  cache_for_members: true
```

Staff (`CMS_ACCESS_LeftAndMain` / `ADMIN`) always bypass, so editors never see stale pages.

## What is (and isn't) cached

A request is cached/served only when **all** of these hold:

- the page has caching enabled (field or forced getter)
- it's a plain `GET` of the page itself — **not** an AJAX request and **not** a form/sub-action
  (`$request->param('Action')` is empty)
- the current stage is **Live** (draft and the CMS preview are never cached)
- the visitor doesn't bypass the cache (see above)

The cache key varies by the full URL **including GET vars** (so each `?start=…`/`?sort=…`/filter
combination is a separate entry), the page's `LastEdited`, and — when the controller exposes
`getIsMobile()` / `getIsPhone()` / `getIsTablet()` — the device variant.

## Flushing

- `?flush=1` (or `dev/build?flush=all`) clears the entire page-cache pool.
- `?clearcache=1` on a page clears just that page's current cache entry.
- Publishing a page invalidates its entries automatically (via `LastEdited` in the key).

## Configuration reference

```yaml
# Serve cached pages to logged-in non-staff users (default: false)
SilverStripe\CMS\Controllers\ContentController:
  cache_for_members: true

# Change the default pool lifetime (seconds) used when a page's CacheLifetime is 0
SilverStripe\Core\Injector\Injector:
  Psr\SimpleCache\CacheInterface.pageCache:
    constructor:
      defaultLifetime: 900
```

The module also overrides `SilverStripe\Dev\DebugView` to suppress a noisy "Using record #N of type"
line emitted during cached renders in dev mode. If you'd rather keep the stock `DebugView`, remove
that Injector mapping in your project config.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
