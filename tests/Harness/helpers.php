<?php

// Switchboard harness helpers, loaded by check.sh's `php` function (storage/switchboard-helpers.php).

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Validation\AssetRules;
use CraftCms\Cms\Element\Drafts;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Folders;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\Switchboard\Bell\Bell;
use JustinHoltWeb\Switchboard\Channels\SafeHttp;
use JustinHoltWeb\Switchboard\Delivery\Delivery;
use JustinHoltWeb\Switchboard\Rules\Rule;

if (function_exists('switchboard_helpers_loaded')) {
    return;
}

function switchboard_helpers_loaded(): void {}

/** Removes every rule, delivery, ledger row and the fixture users' bell notifications. */
function reset_switchboard(): void
{
    foreach (['switchboardNews', 'switchboardPrivate'] as $h) {
        foreach (Entry::find()->section($h)->status(null)->site('*')->unique()->drafts(null)->trashed(null)->all() as $e) {
            Elements::deleteElement($e, true);
        }
    }
    DB::table('switchboard_deliveries')->delete();
    DB::table('switchboard_rules')->delete();
    DB::table('switchboard_ledger')->delete();
    $ids = User::find()->username('switchboard_*')->status(null)->ids();
    DB::table('notifications')->whereIn('notifiable_id', $ids ?: [0])->delete();
    DB::table('jobs')->where('queue', 'switchboard')->delete();
    echo 1;
}

/** @param array<string, mixed> $attrs */
function rule(string $handle, string $trigger, array $attrs = []): Rule
{
    Rule::query()->where('handle', $handle)->delete();
    $rule = Rule::create($attrs + ['name' => ucfirst($handle), 'handle' => $handle, 'trigger' => $trigger, 'enabled' => true]);
    echo $rule->id;

    return $rule;
}

function disable_rule(string $handle): void
{
    Rule::query()->where('handle', $handle)->update(['enabled' => false]);
    echo 1;
}

function rule_secret(string $handle): string
{
    return (string) Rule::query()->where('handle', $handle)->firstOrFail()->signingSecret;
}

function group_uid(): string
{
    return (string) UserGroups::getGroupByHandle('switchboardEditors')->uid;
}

function section_uid(string $handle): string
{
    return (string) Sections::getSectionByHandle($handle)->uid;
}

/** @param array<string, mixed> $fields */
function entry(string $section, string $title, array $fields = [], bool $enabled = true, ?string $postDate = null, ?string $site = null): Entry
{
    $s = Sections::getSectionByHandle($section);
    $e = new Entry(['sectionId' => $s->id, 'typeId' => $s->getEntryTypes()[0]->id]);
    if ($site !== null) {
        $e->siteId = Sites::getSiteByHandle($site)->id;
    }
    $e->title = $title;
    $e->enabled = $enabled;
    if ($postDate !== null) {
        $e->postDate = new DateTime($postDate);
    }
    $e->setFieldValues($fields);
    Elements::saveElement($e) || throw new RuntimeException(json_encode($e->errors()->all()));

    return $e;
}

/** @param array<string, mixed> $fields */
function save_entry(int $id, array $fields = [], ?bool $enabled = null, ?string $title = null): void
{
    $e = Entry::find()->id($id)->status(null)->one();
    $e->setFieldValues($fields);
    if ($enabled !== null) {
        $e->enabled = $enabled;
    }
    if ($title !== null) {
        $e->title = $title;
    }
    Elements::saveElement($e) || throw new RuntimeException(json_encode($e->errors()->all()));
    echo 1;
}

function draft_of(int $id): void
{
    $e = Entry::find()->id($id)->one();
    $draft = app(Drafts::class)->createDraft($e, $e->getAuthorId() ?? 1);
    $draft->title = 'Draft title';
    Elements::saveElement($draft);
    echo 1;
}

/** @param array<string, mixed> $where */
function deliveries(array $where = []): Collection
{
    return Delivery::query()->where($where)->orderBy('channel')->get();
}

/** @param array<string, mixed> $where */
function count_deliveries(array $where = []): int
{
    return deliveries($where)->count();
}

/** “database:sent,mail:sent” */
function statuses(array $where): string
{
    return deliveries($where)->map(fn ($d) => $d->channel.':'.$d->status)->implode(',');
}

function skipped(int $userId, int $elementId): string
{
    return (string) Delivery::query()->where(['userId' => $userId, 'elementId' => $elementId, 'status' => 'skipped'])->value('error');
}

function errors(array $where): string
{
    return deliveries($where)->map(fn ($d) => $d->status.':'.$d->error)->implode(' | ');
}

/** The user's Switchboard bell notifications, newest first. */
function bell(int $userId): array
{
    $user = User::find()->id($userId)->status(null)->one();

    return app(Bell::class)->forUser($user, 100);
}

function bell_titles(int $userId): string
{
    return implode(' || ', array_column(bell($userId), 'title'));
}

function bell_messages(int $userId): string
{
    return implode(' || ', array_column(bell($userId), 'message'));
}

/** Runs the plugin's SSRF check on a URL; prints "ok" or the reason. */
function ssrf(string $url, array $hosts = []): void
{
    try {
        app(SafeHttp::class)->check($url, $hosts, resolve: true);
        echo 'ok';
    } catch (RuntimeException $e) {
        echo $e->getMessage();
    }
}

/** Queues a job on Switchboard's queue that fails for good (defined in a file, so the worker can unserialize it). */
function failing_job(): void
{
    dispatch(function () {
        throw new RuntimeException('boom secret-token-123');
    })->onQueue('switchboard');
    echo 1;
}

/** Uploads a small PNG to the switchboardFiles volume; prints its ID. */
function upload(string $filename): void
{
    $volume = Volumes::getVolumeByHandle('switchboardFiles');
    $img = imagecreatetruecolor(20, 20);
    imagefill($img, 0, 0, imagecolorallocate($img, 89, 96, 113));
    $tmp = sys_get_temp_dir().'/'.$filename;
    imagepng($img, $tmp);
    $asset = new Asset;
    $asset->tempFilePath = $tmp;
    $asset->filename = $filename;
    $asset->newFolderId = Folders::getRootFolderByVolumeId($volume->id)->id;
    $asset->volumeId = $volume->id;
    $asset->title = 'Switchboard Upload';
    $asset->ruleset->useScenario(AssetRules::SCENARIO_CREATE);
    Elements::saveElement($asset) || throw new RuntimeException(json_encode($asset->errors()->all()));
    echo $asset->id;
}
