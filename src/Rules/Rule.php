<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A notification rule: when {trigger} happens to an element matching {sources}
 * and {conditions} on one of {siteIds}, tell {recipients} through {channels},
 * and post to the ad-hoc {routes}.
 *
 * @property int $id
 * @property string $uid
 * @property string $name
 * @property string $handle
 * @property bool $enabled
 * @property string $trigger
 * @property list<int>|null $siteIds
 * @property list<string>|null $sources
 * @property list<array{attribute: string, operator: string, value?: string|null}>|null $conditions
 * @property array{users?: list<int>, groups?: list<string>, admins?: bool, authors?: bool, subject?: bool}|null $recipients
 * @property list<string>|null $channels
 * @property list<array{channel: string, to: string}>|null $routes
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $template
 * @property list<string>|null $payloadFields
 * @property string|null $signingSecret
 */
class Rule extends Model
{
    public const string HANDLE_PATTERN = '/^[a-z][a-zA-Z0-9_\-]{0,63}$/D';

    protected $table = 'switchboard_rules';

    protected $guarded = ['id'];

    protected $hidden = ['routes', 'signingSecret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'siteIds' => 'array',
            'sources' => 'array',
            'conditions' => 'array',
            'recipients' => 'array',
            'channels' => 'array',
            'routes' => 'encrypted:array',
            'payloadFields' => 'array',
            'signingSecret' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Rule $rule): void {
            $rule->uid = $rule->uid ?: (string) Str::uuid();
            $rule->signingSecret = $rule->signingSecret ?: self::newSecret();
        });
    }

    public static function newSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(24));
    }

    /** @return list<int> */
    public function userIds(): array
    {
        return array_values(array_map('intval', (array) ($this->recipients['users'] ?? [])));
    }

    /** @return list<string> */
    public function groupUids(): array
    {
        return array_values(array_map('strval', (array) ($this->recipients['groups'] ?? [])));
    }

    public function wants(string $who): bool
    {
        return (bool) ($this->recipients[$who] ?? false);
    }

    /** @return list<string> */
    public function userChannels(): array
    {
        return array_values(array_map('strval', (array) $this->channels));
    }

    /** @return list<array{channel: string, to: string}> */
    public function routeList(): array
    {
        $routes = [];

        foreach ((array) $this->routes as $route) {
            if (is_array($route) && isset($route['channel'], $route['to'])) {
                $routes[] = ['channel' => (string) $route['channel'], 'to' => (string) $route['to']];
            }
        }

        return $routes;
    }

    /** A stable key for one route, so a queued delivery can find it again without carrying the secret. */
    public static function routeKey(string $channel, string $to): string
    {
        return substr(hash('sha256', $channel."\0".$to), 0, 16);
    }

    /** @return array{channel: string, to: string}|null */
    public function findRoute(string $key): ?array
    {
        foreach ($this->routeList() as $route) {
            if (hash_equals(self::routeKey($route['channel'], $route['to']), $key)) {
                return $route;
            }
        }

        return null;
    }

    public function appliesToSite(?int $siteId): bool
    {
        $sites = $this->siteIds;

        return $sites === null || $sites === [] || ($siteId !== null && in_array($siteId, array_map('intval', $sites), true));
    }
}
