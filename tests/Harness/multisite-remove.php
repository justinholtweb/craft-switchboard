<?php

// Removes `switchboardfr` (and only it). Run under bin/harness-lock.

use CraftCms\Cms\Support\Facades\Sites;

require_once __DIR__.'/switchboard-retry.php';

$fr = Sites::getSiteByHandle('switchboardfr', true);
echo $fr ? (switchboard_retry(fn () => Sites::deleteSite($fr)) ? 'removed' : 'failed') : 'absent';
