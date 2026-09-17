<?php

namespace XD\PageCache\Injector;

use SilverStripe\Dev\DebugView;

class PageCacheDebugView extends DebugView
{
    public function renderMessage($message, $caller, $showHeader = true)
    {
        // Suppress specific debug messages
        if (preg_match('/Using record #\d+ of type/', $message)) {
            return;
        }

        parent::renderMessage($message, $caller, $showHeader);
    }
}