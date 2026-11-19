<?php

// Removes everything fixtures.php and gql-fixtures.php created (all `switchboard`-prefixed),
// plus Switchboard's rules, deliveries and bell notifications for the fixture users.
// Run with: ddev artisan tinker --execute "require 'storage/switchboard-cleanup.php';"

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/switchboard-retry.php';

foreach (['switchboardNews', 'switchboardPrivate'] as $h) {
    foreach (Entry::find()->section($h)->status(null)->site('*')->unique()->trashed(null)->all() as $e) {
        Elements::deleteElement($e, true);
    }
    if ($s = Sections::getSectionByHandle($h)) {
        switchboard_retry(fn () => Sections::deleteSection($s));
    }
}
if ($t = EntryTypes::getEntryTypeByHandle('switchboardArticle')) {
    switchboard_retry(fn () => EntryTypes::deleteEntryType($t));
}
foreach (['switchboardSummary', 'switchboardSecret'] as $h) {
    if ($f = Fields::getFieldByHandle($h)) {
        switchboard_retry(fn () => Fields::deleteField($f));
    }
}
$ids = User::find()->username('switchboard_*')->status(null)->ids();
if ($ids) {
    DB::table('notifications')->whereIn('notifiable_id', $ids)->delete();
}
foreach (User::find()->id($ids ?: [0])->status(null)->all() as $u) {
    Elements::deleteElement($u, true);
}
if ($g = UserGroups::getGroupByHandle('switchboardEditors')) {
    switchboard_retry(fn () => UserGroups::deleteGroup($g));
}
foreach (Gql::getTokens() as $t) {
    if (str_starts_with((string) $t->name, 'switchboard_')) {
        Gql::deleteTokenById($t->id);
    }
}
foreach (Gql::getSchemas() as $s) {
    if (str_starts_with((string) $s->name, 'switchboard_')) {
        Gql::deleteSchemaById($s->id);
    }
}
Gql::invalidateCaches();
DB::table('switchboard_deliveries')->delete();
DB::table('switchboard_rules')->delete();
DB::table('switchboard_ledger')->delete();
echo 'cleaned';
