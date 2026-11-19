<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Triggers;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\Switchboard\Switchboard;

use function CraftCms\Cms\t;

/**
 * The things a rule can listen for. `custom:<name>` triggers are fired from code
 * with {@see Switchboard::fire()}.
 */
class Triggers
{
    public const string ENTRY_PUBLISHED = 'entry.published';

    public const string ENTRY_UPDATED = 'entry.updated';

    public const string ENTRY_DELETED = 'entry.deleted';

    public const string USER_REGISTERED = 'user.registered';

    public const string USER_ACTIVATED = 'user.activated';

    public const string ASSET_UPLOADED = 'asset.uploaded';

    public const string QUEUE_FAILED = 'queue.failed';

    public const string CUSTOM_PATTERN = '/^custom:[a-z][a-z0-9\-_.]{0,63}$/D';

    /**
     * @return array<string, array{label: string, elementType: class-string|null, sources: string|null, localized: bool}>
     */
    public static function all(): array
    {
        return [
            self::ENTRY_PUBLISHED => ['label' => t('An entry is published', category: 'switchboard'), 'elementType' => Entry::class, 'sources' => 'sections', 'localized' => true],
            self::ENTRY_UPDATED => ['label' => t('A live entry is updated', category: 'switchboard'), 'elementType' => Entry::class, 'sources' => 'sections', 'localized' => true],
            self::ENTRY_DELETED => ['label' => t('An entry is deleted', category: 'switchboard'), 'elementType' => Entry::class, 'sources' => 'sections', 'localized' => true],
            self::USER_REGISTERED => ['label' => t('A user account is created', category: 'switchboard'), 'elementType' => User::class, 'sources' => 'groups', 'localized' => false],
            self::USER_ACTIVATED => ['label' => t('A user is activated', category: 'switchboard'), 'elementType' => User::class, 'sources' => 'groups', 'localized' => false],
            self::ASSET_UPLOADED => ['label' => t('An asset is uploaded', category: 'switchboard'), 'elementType' => Asset::class, 'sources' => 'volumes', 'localized' => false],
            self::QUEUE_FAILED => ['label' => t('A queued job fails', category: 'switchboard'), 'elementType' => null, 'sources' => null, 'localized' => false],
        ];
    }

    public static function isValid(string $trigger): bool
    {
        return isset(self::all()[$trigger]) || (bool) preg_match(self::CUSTOM_PATTERN, $trigger);
    }

    public static function isCustom(string $trigger): bool
    {
        return str_starts_with($trigger, 'custom:');
    }

    public static function label(string $trigger): string
    {
        return self::all()[$trigger]['label']
            ?? t('Custom: {name}', ['name' => substr($trigger, 7)], category: 'switchboard');
    }

    public static function sourceKind(string $trigger): ?string
    {
        return self::all()[$trigger]['sources'] ?? null;
    }

    /** Per-site rules only filter triggers about localized content (entries, and custom triggers given a site). */
    public static function isLocalized(string $trigger): bool
    {
        return self::all()[$trigger]['localized'] ?? self::isCustom($trigger);
    }

    /** The default subject when a rule leaves it blank. Rendered in the recipient's language. */
    /** @param array<string, string> $params */
    public static function defaultSubject(string $trigger, array $params): string
    {
        return match ($trigger) {
            self::ENTRY_PUBLISHED => t('Published: {title}', $params, category: 'switchboard'),
            self::ENTRY_UPDATED => t('Updated: {title}', $params, category: 'switchboard'),
            self::ENTRY_DELETED => t('Deleted: {title}', $params, category: 'switchboard'),
            self::USER_REGISTERED => t('New account: {title}', $params, category: 'switchboard'),
            self::USER_ACTIVATED => t('Account activated: {title}', $params, category: 'switchboard'),
            self::ASSET_UPLOADED => t('New upload: {title}', $params, category: 'switchboard'),
            self::QUEUE_FAILED => t('A queued job failed: {title}', $params, category: 'switchboard'),
            default => t('Notification: {title}', $params, category: 'switchboard'),
        };
    }

    /** @param array<string, string> $params */
    public static function defaultBody(string $trigger, array $params): string
    {
        return match ($trigger) {
            self::ENTRY_PUBLISHED => t('“{title}” was published on {site}.', $params, category: 'switchboard'),
            self::ENTRY_UPDATED => t('“{title}” was updated on {site}.', $params, category: 'switchboard'),
            self::ENTRY_DELETED => t('“{title}” was deleted on {site}.', $params, category: 'switchboard'),
            self::USER_REGISTERED => t('{title} now has an account.', $params, category: 'switchboard'),
            self::USER_ACTIVATED => t('{title}’s account was activated.', $params, category: 'switchboard'),
            self::ASSET_UPLOADED => t('“{title}” was uploaded.', $params, category: 'switchboard'),
            self::QUEUE_FAILED => t('The job “{title}” failed and won’t be retried.', $params, category: 'switchboard'),
            default => t('“{title}”', $params, category: 'switchboard'),
        };
    }
}
