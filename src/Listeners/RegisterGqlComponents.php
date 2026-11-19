<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Listeners;

use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use JustinHoltWeb\Switchboard\Gql\NotificationsQuery;

use function CraftCms\Cms\t;

/**
 * One schema permission: “Read your own notifications”. Granted to user-owned
 * (OAuth / personal access token) schemas automatically; it does nothing on a
 * shared token, which has no user.
 */
class RegisterGqlComponents
{
    public function handle(GqlSchemaComponentsResolving $event): void
    {
        $event->queries['Switchboard'] = [
            NotificationsQuery::COMPONENT.':read' => [
                'label' => t('Read your own notifications', category: 'switchboard'),
                'info' => t('Adds `switchboardNotifications` and `switchboardUnreadCount` for requests made with a user’s own access token. Shared tokens have no user, so they never see anyone’s notifications.', category: 'switchboard'),
                'authenticated' => true,
            ],
        ];
    }
}
