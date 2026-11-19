<?php

// Switchboard harness fixtures (idempotent). Everything is prefixed `switchboard`; cleanup.php removes it.
//   fields switchboardSummary, switchboardSecret (shown to admins only, via a user condition)
//   entry type switchboardArticle; sections switchboardNews + switchboardPrivate (primary site only)
//   group switchboardEditors (may view switchboardNews only)
//   users switchboard_editor (group, prefers French), switchboard_editor2 (group), switchboard_outsider,
//         switchboard_boss (admin), switchboard_frozen (group, suspended), switchboard_manager (CP, manages rules; not admin)
//   password for all: switchboard-pass-1
// Run with: ddev artisan tinker --execute "require 'storage/switchboard-fixtures.php';"

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\LayoutElements\TitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Support\Facades\Users;
use CraftCms\Cms\User\Conditions\AdminConditionRule;
use CraftCms\Cms\User\Conditions\UserCondition;
use CraftCms\Cms\User\Data\UserGroup;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\User\UserPermissions;

require_once __DIR__.'/switchboard-retry.php';

$fail = fn (string $what, $model) => throw new RuntimeException($what.': '.json_encode(method_exists($model, 'errors') ? $model->errors()->all() : $model));

$field = function (string $handle) use ($fail) {
    if ($f = Fields::getFieldByHandle($handle)) {
        return $f;
    }
    $f = Fields::createField(['type' => PlainText::class, 'name' => ucfirst($handle), 'handle' => $handle]);
    switchboard_retry(fn () => Fields::saveField($f)) || $fail("field {$handle}", $f);

    return Fields::getFieldByHandle($handle);
};
$summary = $field('switchboardSummary');
$secret = $field('switchboardSecret');

$type = EntryTypes::getEntryTypeByHandle('switchboardArticle');
if (! $type) {
    $type = new EntryType(['name' => 'Switchboard Article', 'handle' => 'switchboardArticle']);
    $type->setFieldLayout(FieldLayout::createFromConfig(['type' => Entry::class, 'tabs' => [['name' => 'Content', 'elements' => [
        ['type' => TitleField::class],
        ['type' => CustomField::class, 'fieldUid' => $summary->uid],
        ['type' => CustomField::class, 'fieldUid' => $secret->uid, 'userCondition' => [
            'class' => UserCondition::class,
            'conditionRules' => [['class' => AdminConditionRule::class, 'value' => true]],
        ]],
    ]]]]));
    switchboard_retry(fn () => EntryTypes::saveEntryType($type)) || $fail('entry type', $type);
    $type = EntryTypes::getEntryTypeByHandle('switchboardArticle');
}

$section = function (string $handle, string $uri) use ($type, $fail) {
    if ($s = Sections::getSectionByHandle($handle)) {
        return $s;
    }
    $s = new Section(['name' => ucfirst($handle), 'handle' => $handle, 'type' => SectionType::Channel]);
    $primary = Sites::getPrimarySite();
    $ss = new SectionSiteSettings;
    $ss->siteId = $primary->id;
    $ss->hasUrls = true;
    $ss->uriFormat = $uri.'/{slug}';
    $ss->template = 'switchboard-entry';
    $ss->enabledByDefault = true;
    $s->setSiteSettings([$primary->id => $ss]);
    $s->setEntryTypes([$type]);
    switchboard_retry(fn () => Sections::saveSection($s)) || $fail("section {$handle}", $s);

    return Sections::getSectionByHandle($handle);
};
$news = $section('switchboardNews', 'switchboard-news');
$private = $section('switchboardPrivate', 'switchboard-private');

$group = UserGroups::getGroupByHandle('switchboardEditors');
if (! $group) {
    $group = new UserGroup(['name' => 'Switchboard Editors', 'handle' => 'switchboardEditors']);
    switchboard_retry(fn () => UserGroups::saveGroup($group)) || $fail('group', $group);
    $group = UserGroups::getGroupByHandle('switchboardEditors');
}
switchboard_retry(fn () => app(UserPermissions::class)->saveGroupPermissions($group->id, [
    'accessCp', "viewEntries:{$news->uid}", "viewPeerEntries:{$news->uid}", 'editSite:'.Sites::getPrimarySite()->uid,
]));

$people = [];
foreach ([
    'editor' => [[$group->id], 'fr', false],
    'editor2' => [[$group->id], null, false],
    'outsider' => [[], null, false],
    'boss' => [[], null, true],
    'frozen' => [[$group->id], null, false],
    'manager' => [[], null, false],
] as $name => [$groups, $language, $admin]) {
    $username = "switchboard_{$name}";
    $user = User::find()->username($username)->status(null)->one() ?? new User;
    $user->username = $username;
    $user->email = "{$username}@switchboard.test";
    $user->fullName = ucfirst($name).' Switchboard';
    $user->newPassword = 'switchboard-pass-1';
    $user->active = true;
    $user->pending = false;
    $user->suspended = false;
    $user->admin = $admin;
    Elements::saveElement($user) || $fail("user {$name}", $user);
    Users::assignUserToGroups($user->id, $groups);
    Users::saveUserPreferences($user, ['language' => $language]);
    $people[$name] = $user->id;
}
Users::suspendUser(User::find()->id($people['frozen'])->status(null)->one());
app(UserPermissions::class)->saveUserPermissions($people['manager'], [
    'accessCp', 'accessPlugin-switchboard', 'switchboard:manageRules', 'switchboard:viewDeliveries', "viewEntries:{$news->uid}", 'editSite:'.Sites::getPrimarySite()->uid,
]);

echo json_encode($people + ['group' => $group->uid, 'news' => $news->uid, 'private' => $private->uid, 'newsId' => $news->id, 'privateId' => $private->id, 'type' => $type->id]);
