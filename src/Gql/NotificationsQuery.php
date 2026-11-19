<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Gql;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Queries\Query;
use CraftCms\Cms\Support\Facades\Gql;
use CraftCms\Cms\User\Elements\User;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\Switchboard\Bell\Bell;
use Throwable;

/**
 * `switchboardNotifications` and `switchboardUnreadCount`: the requesting user's
 * own Switchboard bell notifications. Read-only.
 *
 * Only offered to schemas that belong to a user — requests made with an OAuth or
 * personal access token, where Craft builds the schema for that user — and that
 * include “Read your own notifications”. A shared GraphQL token isn't a person,
 * so it never sees these queries: there's no way to read someone else's bell.
 *
 * @phpstan-import-type UnnamedFieldDefinitionConfig from FieldDefinition
 */
class NotificationsQuery extends Query
{
    public const string COMPONENT = 'switchboard.notifications';

    /** @return array<string, UnnamedFieldDefinitionConfig> */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && (! GqlHelper::canSchema(self::COMPONENT) || self::user() === null)) {
            return [];
        }

        return [
            'switchboardNotifications' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(self::type()))),
                'args' => [
                    'limit' => ['name' => 'limit', 'type' => Type::int(), 'description' => 'How many, newest first (default 10, at most 100).'],
                    'unreadOnly' => ['name' => 'unreadOnly', 'type' => Type::boolean(), 'description' => 'Only unread notifications.'],
                ],
                'resolve' => fn (mixed $source, array $args) => app(Bell::class)->forUser(self::requireUser(), (int) ($args['limit'] ?? 10), (bool) ($args['unreadOnly'] ?? false)),
                'description' => 'Your own Switchboard notifications (the bell), newest first.',
            ],
            'switchboardUnreadCount' => [
                'type' => Type::nonNull(Type::int()),
                'resolve' => fn () => app(Bell::class)->unreadCountFor(self::requireUser()),
                'description' => 'How many of your Switchboard notifications are unread.',
            ],
        ];
    }

    private static function user(): ?User
    {
        try {
            $user = Gql::getActiveSchema()->user;
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    private static function requireUser(): User
    {
        if (! GqlHelper::canSchema(self::COMPONENT) || ($user = self::user()) === null) {
            throw new UserError('Switchboard notifications can only be read with a user’s own access token.');
        }

        return $user;
    }

    private static function type(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SwitchboardNotification', fn () => new ObjectType([
            'name' => 'SwitchboardNotification',
            'description' => 'A notification in the user’s Switchboard bell.',
            'fields' => [
                'id' => ['type' => Type::nonNull(Type::string())],
                'title' => ['type' => Type::nonNull(Type::string())],
                'message' => ['type' => Type::nonNull(Type::string())],
                'url' => ['type' => Type::string()],
                'date' => ['type' => Type::nonNull(Type::string()), 'description' => 'When it was sent (ISO 8601).'],
                'read' => ['type' => Type::nonNull(Type::boolean())],
            ],
        ]));
    }
}
