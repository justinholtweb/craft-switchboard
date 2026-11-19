<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Cp\Icons;
use CraftCms\Cms\Cp\IconSets;
use CraftCms\Cms\Plugin\PluginSettings;

/**
 * Blank-able properties are nullable: Laravel's ConvertEmptyStringsToNull middleware
 * turns an empty field into null before Craft assigns it.
 *
 * Rules aren't settings: they're managed in the CP (Switchboard → Rules).
 */
class Settings extends PluginSettings
{
    /** Delivery log retention (days). Delivery records older than this are pruned daily. */
    public int $logRetentionDays = 30;

    /** The queue Switchboard's jobs go on. Null: Craft's queue (`queueName` in general config). */
    public ?string $queue = null;

    /** Seconds before a webhook / Slack / Discord / Teams request gives up. */
    public int $webhookTimeout = 5;

    /** The most people one rule notifies about one event. */
    public int $maxRecipients = 500;

    /**
     * Hosts (`hooks.internal.test`) or host:port pairs (`127.0.0.1:8080`) that
     * webhooks may reach even though they're private, loopback or plain HTTP.
     * Only for receivers you control; leave empty in production.
     *
     * @var list<string>|string|null
     */
    public array|string|null $allowedHosts = [];

    /** Whether a sidebar link shows. */
    public bool $showInCpNav = true;

    /** What the sidebar link says. Null uses “Switchboard”. */
    public ?string $cpNavLabel = null;

    /** The sidebar icon (a Craft icon or `set:name`). Null uses Switchboard's own. */
    public ?string $cpNavIcon = null;

    public function queueName(): string
    {
        $queue = trim((string) $this->queue);

        return $queue !== '' ? $queue : (string) Cms::config()->queueName;
    }

    /** @return list<string> */
    public function allowedHostList(): array
    {
        $hosts = is_array($this->allowedHosts) ? $this->allowedHosts : (preg_split('/[\s,]+/', (string) $this->allowedHosts) ?: []);

        return array_values(array_filter(array_map(fn ($h) => strtolower(trim((string) $h)), $hosts), fn (string $h) => $h !== ''));
    }

    public function navLabel(): ?string
    {
        $label = trim((string) $this->cpNavLabel);

        return $label === '' ? null : $label;
    }

    public function navIcon(): ?string
    {
        $icon = trim((string) $this->cpNavIcon);

        return $icon !== '' && self::isIcon($icon) ? $icon : null;
    }

    public static function isIcon(string $icon): bool
    {
        if ($custom = IconSets::parse($icon)) {
            $svg = rescue(fn () => app(IconSets::class)->getIconSetByHandle($custom[0])?->getSvg($custom[1]), null, false);

            return is_string($svg) && stripos($svg, '<svg') !== false;
        }

        return (bool) preg_match('/^[\da-z\-]+$/D', $icon) && is_file(Icons::resolveIconPath($icon));
    }

    /** @return array<string, list<string>> */
    public function getRules(): array
    {
        return [
            'logRetentionDays' => ['required', 'integer', 'min:1', 'max:3650'],
            'webhookTimeout' => ['required', 'integer', 'min:1', 'max:30'],
            'maxRecipients' => ['required', 'integer', 'min:1', 'max:100000'],
            'queue' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-.]+$/'],
            'cpNavLabel' => ['nullable', 'string', 'max:100'],
            'cpNavIcon' => ['nullable', 'string', 'max:100', 'regex:/^([a-zA-Z][\w\-]*:)?[\w\-]+$/'],
        ];
    }
}
