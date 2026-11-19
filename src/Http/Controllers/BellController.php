<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Http\Controllers;

use CraftCms\Cms\User\Elements\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JustinHoltWeb\Switchboard\Bell\Bell;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\currentUserElement;

/**
 * Front-end bell endpoints (site and CP action URLs). Signed-in users only, and
 * only ever their own notifications. Marking read is a POST (CSRF-protected).
 */
class BellController
{
    public function feed(Request $request, Bell $bell): JsonResponse
    {
        $user = self::user();

        return response()->json([
            'unread' => $bell->unreadCountFor($user),
            'notifications' => $bell->forUser($user, (int) $request->query('limit', 10), $request->boolean('unreadOnly')),
        ]);
    }

    public function markRead(Request $request, Bell $bell): Response
    {
        $user = self::user();
        $validated = $request->validate([
            'ids' => ['nullable', 'array', 'max:100'],
            'ids.*' => ['uuid'],
            'all' => ['nullable', 'boolean'],
        ]);
        $ids = $request->boolean('all') ? null : array_values(array_map('strval', $validated['ids'] ?? []));
        $count = $bell->markRead($user, $ids);

        if ($request->expectsJson()) {
            return response()->json(['marked' => $count, 'unread' => $bell->unreadCountFor($user)]);
        }

        return back();
    }

    private static function user(): User
    {
        $user = currentUserElement();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
