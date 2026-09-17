<?php

namespace XD\PageCache\Extensions;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\NumericField;
use SilverStripe\Core\Extension;

class PageExtension extends Extension
{
    private static $db = [
        'EnablePageCache' => 'Boolean',
        'CacheLifetime' => 'Int',
    ];

    private static $defaults = [
        'CacheLifetime' => 0,
    ];

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
                '0 = use the default cache lifetime (900 seconds). Enter a number of seconds to override it for this page.'
            )
        ));
    }


}