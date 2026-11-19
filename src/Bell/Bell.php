<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Bell;

use Carbon\CarbonInterface;
use CraftCms\Cms\Cp\Notifications\CpNotification;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use JustinHoltWeb\Switchboard\Notifications\SwitchboardNotification;
use Throwable;

use function CraftCms\Cms\action_url;
use function CraftCms\Cms\currentUserElement;

/**
 * A notifications bell for the signed-in user, for front-end templates (Twig
 * `switchboard.*`, Blade `$switchboard->…`), the JSON endpoint and GraphQL.
 *
 * Only Switchboard's own notifications are listed — never other control panel
 * notifications — and only ever the signed-in user's.
 */
class Bell
{
    public const int MAX = 100;

    /**
     * @return list<array{id: string, title: string, message: string, url: string|null, date: string, read: bool}>
     */
    public function notifications(int $limit = 10, bool $unreadOnly = false): array
    {
        $user = currentUserElement();

        return $user instanceof User ? $this->forUser($user, $limit, $unreadOnly) : [];
    }

    public function unreadCount(): int
    {
        $user = currentUserElement();

        return $user instanceof User ? $this->unreadCountFor($user) : 0;
    }

    /** Where a “mark as read” form posts (`ids[]`, or `all=1`). */
    public function markReadUrl(): string
    {
        return action_url('switchboard/notifications/mark-read');
    }

    /** JSON for a bell that polls (GET, signed-in users only). */
    public function feedUrl(): string
    {
        return action_url('switchboard/notifications');
    }

    /**
     * @return list<array{id: string, title: string, message: string, url: string|null, date: string, read: bool}>
     */
    public function forUser(User $user, int $limit = 10, bool $unreadOnly = false): array
    {
        $query = $this->query($user);

        if ($query === null) {
            return [];
        }

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->reorder()->latest()->limit(max(1, min(self::MAX, $limit)))->get()
            ->map(fn (DatabaseNotification $n): array => self::present($n))
            ->values()
            ->all();
    }

    public function unreadCountFor(User $user): int
    {
        return $this->query($user)?->whereNull('read_at')->count() ?? 0;
    }

    /**
     * Marks the user's own notifications read: the given IDs, or all of them (null).
     * IDs that aren't theirs are ignored.
     *
     * @param  list<string>|null  $ids
     */
    public function markRead(User $user, ?array $ids): int
    {
        $query = $this->query($user);

        if ($query === null || $ids === []) {
            return 0;
        }

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** @return MorphMany<DatabaseNotification, Model>|null */
    private function query(User $user): ?MorphMany
    {
        try {
            /** @var MorphMany<DatabaseNotification, Model> $relation */
            $relation = $user->notifications();
        } catch (Throwable) {
            return null;
        }

        // DatabaseChannel stores `data` with json_encode(), so the marker is always written this way.
        return $relation
            ->where('type', CpNotification::TYPE)
            ->where('data', 'like', '%"kind":"'.SwitchboardNotification::KIND.'"%');
    }

    /** @return array{id: string, title: string, message: string, url: string|null, date: string, read: bool} */
    public static function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = (array) $notification->getAttribute('data');
        $created = $notification->getAttribute('created_at');

        return [
            'id' => (string) $notification->getKey(),
            'title' => (string) ($data['title'] ?? ''),
            'message' => (string) ($data['message'] ?? ''),
            'url' => isset($data['url']) && is_string($data['url']) ? $data['url'] : null,
            'date' => $created instanceof CarbonInterface ? $created->toIso8601String() : '',
            'read' => $notification->getAttribute('read_at') !== null,
        ];
    }
}
