<?php

use CraftCms\Cms\ProjectConfig\Exceptions\BusyResourceException;
use CraftCms\Cms\ProjectConfig\Exceptions\StaleResourceException;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use Illuminate\Support\Facades\Context;

// Other agents share the harness: project config can be locked (Busy) or moved on (Stale)
// by their saves. Retry a project-config write with a fresh view of it.
if (! function_exists('switchboard_retry')) {
    function switchboard_retry(callable $fn): mixed
    {
        for ($i = 0; ; $i++) {
            try {
                return $fn();
            } catch (BusyResourceException|StaleResourceException $e) {
                if ($i >= 30) {
                    throw $e;
                }
                sleep(2);
                Context::forgetHidden('craft.info');
                ProjectConfig::reset();
            }
        }
    }
}
