<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Dispatch;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\Switchboard\Rules\Rule;
use JustinHoltWeb\Switchboard\Triggers\Triggers;
use Throwable;

/**
 * Who a rule notifies, and whether each of them may be told.
 */
class Recipients
{
    /**
     * The Craft users a rule names: listed users, members of its groups, admins,
     * the entry's authors, or the user the event is about. Deduplicated, at most $limit.
     *
     * @return list<User>
     */
    public function users(Rule $rule, ?ElementInterface $element, int $limit): array
    {
        $users = [];
        $add = function (iterable $found) use (&$users, $limit): void {
            foreach ($found as $user) {
                if ($user instanceof User && $user->id !== null && count($users) < $limit) {
                    $users[$user->id] ??= $user;
                }
            }
        };

        if ($ids = $rule->userIds()) {
            $add(User::find()->id($ids)->status(null)->limit($limit)->all());
        }

        $groupIds = array_values(array_filter(array_map(
            fn (string $uid) => rescue(fn () => UserGroups::getGroupByUid($uid)?->id, null, false),
            $rule->groupUids(),
        )));

        if ($groupIds !== []) {
            $add(User::find()->groupId($groupIds)->status(null)->limit($limit)->all());
        }

        if ($rule->wants('admins')) {
            $add(User::find()->admin(true)->status(null)->limit($limit)->all());
        }

        if ($rule->wants('authors') && $element instanceof Entry) {
            $add(rescue(fn () => $element->getAuthors(), [], false));
        }

        if ($rule->wants('subject') && $element instanceof User) {
            $add([$element]);
        }

        return array_values($users);
    }

    /**
     * Why this user mustn't be told about this element, or null if they may.
     *
     * - Only active users (not pending, suspended, locked or deactivated).
     * - The user must be able to view the element (Craft's own permission check),
     *   unless the event is about the user themselves.
     * - On a multi-site install, the user needs access to the element's site.
     * - Failed-job notifications go to admins only.
     */
    public function denies(User $user, Rule $rule, ?ElementInterface $element, ?Site $site): ?string
    {
        if ($user->getStatus() !== User::STATUS_ACTIVE) {
            return 'not an active user';
        }

        if ($rule->trigger === Triggers::QUEUE_FAILED) {
            return $user->admin ? null : 'failed-job notifications go to admins only';
        }

        if ($element === null) {
            return null;
        }

        if ($element instanceof User && $element->id === $user->id) {
            return null;
        }

        try {
            if (! $element->canView($user)) {
                return 'can’t view this element';
            }
        } catch (Throwable) {
            return 'can’t view this element';
        }

        if ($site !== null && Triggers::isLocalized($rule->trigger) && Sites::isMultiSite() && ! $user->admin && ! $user->can('editSite:'.$site->uid)) {
            return 'no access to this site';
        }

        return null;
    }
}
