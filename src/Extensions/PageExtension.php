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

    public function updateSettingsFields(FieldList $fields)
    {
        $fields->addFieldToTab('Root.Settings', CheckboxField::create('EnablePageCache', 'Enable Page Cache'));
        $fields->addFieldToTab('Root.Settings', NumericField::create('CacheLifetime', 'Cache Lifetime (seconds)'));
    }


}