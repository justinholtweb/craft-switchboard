<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard;

use CraftCms\Cms\Plugin\PluginSettings;

/**
 * Blank-able properties are nullable: Laravel's ConvertEmptyStringsToNull middleware
 * turns an empty field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /** Delivery log retention (days). Delivery attempts older than this are pruned. */
    public int $logRetentionDays = 30;
}
