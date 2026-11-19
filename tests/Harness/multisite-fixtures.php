<?php

// Switchboard harness: its own second site `switchboardfr` (French) under /switchboardfr/, with
// the switchboardNews section on it. Other agents share the harness: never touch a site this file
// didn't create. Idempotent. Run under bin/harness-lock.

use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;

require_once __DIR__.'/switchboard-retry.php';

$fr = Sites::getSiteByHandle('switchboardfr', true);

if (! $fr) {
    $primary = Sites::getPrimarySite();
    $fr = new Site;
    $fr->groupId = $primary->groupId;
    $fr->name = 'Switchboard Français';
    $fr->handle = 'switchboardfr';
    $fr->language = 'fr-FR';
    $fr->hasUrls = true;
    $fr->baseUrl = rtrim((string) config('app.url'), '/').'/switchboardfr/';
    $fr->enabled = true;
    switchboard_retry(fn () => Sites::saveSite($fr)) || throw new RuntimeException('site: '.json_encode($fr->errors()->all()));
    $fr = Sites::getSiteByHandle('switchboardfr', true);
}

$section = Sections::getSectionByHandle('switchboardNews');
$settings = $section?->getSiteSettings() ?? [];

if ($section && ! isset($settings[$fr->id])) {
    $s = new SectionSiteSettings;
    $s->siteId = $fr->id;
    $s->hasUrls = true;
    $s->uriFormat = 'switchboard-news/{slug}';
    $s->template = 'switchboard-entry';
    $s->enabledByDefault = true;
    $settings[$fr->id] = $s;
    $section->setSiteSettings($settings);
    switchboard_retry(fn () => Sections::saveSection($section)) || throw new RuntimeException('section: '.json_encode($section->errors()->all()));
}

echo json_encode(['id' => $fr->id, 'uid' => $fr->uid]);
