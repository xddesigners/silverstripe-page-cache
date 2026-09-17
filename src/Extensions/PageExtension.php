<?php

namespace XD\PageCache\Extensions;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Versioned\Versioned;

class PageExtension extends Extension
{
    private static $db = [
        'EnablePageCache' => 'Boolean',
        'CacheLifetime' => 'Int',
    ];

    private static $defaults = [
        'CacheLifetime' => 0,
    ];

    /**
     * Full-pool flush strategy. Per-page freshness is automatic regardless (the cache key includes the page's
     * LastEdited), so these only govern when OTHER cached pages — which embed the shared menu — are refreshed.
     *
     * flush_all_on_menu_change (default true): clear the whole pool only when a publish/unpublish changes a
     * menu-relevant field (see MENU_FIELDS) or adds/removes a page. Plain content edits do NOT flush, so a
     * large catalogue is not rebuilt on every product publish.
     *
     * flush_all_on_publish (default false): clear the whole pool on EVERY publish/unpublish. Simple and very
     * fresh, but unsafe for large catalogues (rebuild storms); best for small sites that pair it with a long
     * cache lifetime for "cache forever, refresh on publish".
     *
     * Cross-page listings that are neither the menu nor the edited page (e.g. a category listing its products)
     * refresh within the configured cache lifetime.
     *
     * @config
     */
    private static bool $flush_all_on_menu_change = true;

    /**
     * @config
     */
    private static bool $flush_all_on_publish = false;

    /** Fields the site menu is built from; a change to any on publish invalidates every cached page. */
    private const MENU_FIELDS = ['Title', 'MenuTitle', 'URLSegment', 'ShowInMenus', 'ParentID', 'Sort'];

    public function updateCMSFields(FieldList $fields)
    {
        // EnablePageCache/CacheLifetime are $db fields, so they auto-scaffold into the content form
        // (Root.Main). They belong only in the Settings tab (see updateSettingsFields()), so remove the
        // scaffolded copies here.
        $fields->removeByName(['EnablePageCache', 'CacheLifetime']);
    }

    public function updateSettingsFields(FieldList $fields)
    {
        $fields->addFieldToTab('Root.Settings', CheckboxField::create(
            'EnablePageCache',
            _t(self::class . '.EnablePageCache', 'Enable Page Cache')
        ));
        $fields->addFieldToTab('Root.Settings', NumericField::create(
            'CacheLifetime',
            _t(self::class . '.CacheLifetime', 'Cache Lifetime (seconds)')
        )->setDescription(
            _t(
                self::class . '.CacheLifetimeDescription',
                '0 = use the site default cache lifetime. Enter a number of seconds to set a specific lifetime for this page.'
            )
        ));
    }

    public function onAfterPublish()
    {
        if ($this->owner->config()->get('flush_all_on_publish') || $this->publishChangedMenu()) {
            $this->clearPageCache();
        }
    }

    public function onAfterUnpublish()
    {
        // A removed page disappears from the menu on every other page (only relevant if it was in the menu).
        if ($this->owner->config()->get('flush_all_on_publish')
            || ($this->owner->config()->get('flush_all_on_menu_change') && $this->owner->ShowInMenus)
        ) {
            $this->clearPageCache();
        }
    }

    /**
     * True when this publish changed a field the shared menu is built from (or is the page's first publish),
     * so every cached page must be rebuilt. Plain content edits return false. Guarded — on any uncertainty it
     * returns true (flush), the safe choice.
     */
    private function publishChangedMenu(): bool
    {
        if (!$this->owner->config()->get('flush_all_on_menu_change')) {
            return false;
        }
        try {
            $versions = Versioned::get_all_versions($this->owner->baseClass(), (int) $this->owner->ID)
                ->filter('WasPublished', 1)
                ->sort('Version', 'DESC')
                ->limit(2)
                ->toArray();
            if (count($versions) < 2) {
                return true; // first publish — the page now appears in the menu
            }
            [$new, $prev] = $versions;
            foreach (self::MENU_FIELDS as $field) {
                if ((string) $new->$field !== (string) $prev->$field) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return true; // uncertain → flush (safe)
        }
    }

    protected function clearPageCache(): void
    {
        try {
            Injector::inst()->get(CacheInterface::class . '.pageCache')->clear();
        } catch (\Throwable $e) {
            // never let cache clearing break a publish/unpublish
        }
    }
}