<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Http\Request;
use JustinHoltWeb\Switchboard\Channels\Channels;
use JustinHoltWeb\Switchboard\Delivery\Delivery;
use JustinHoltWeb\Switchboard\Rules\Rule;
use JustinHoltWeb\Switchboard\Triggers\Triggers;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\t;

/**
 * Switchboard → Deliveries: every delivery, newest first, filterable by rule and
 * status. Destinations are shown masked; failures show why.
 */
class DeliveriesController
{
    public const int PER_PAGE = 50;

    public function __invoke(Request $request, Channels $channels): CpScreenResponse
    {
        $status = (string) $request->query('status', '');
        $status = in_array($status, Delivery::STATUSES, true) ? $status : '';
        $ruleId = max(0, (int) $request->query('rule', 0));

        $query = Delivery::query()
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($ruleId > 0, fn ($q) => $q->where('ruleId', $ruleId));
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $rules = Rule::query()->orderBy('name')->get(['id', 'name', 'handle'])->keyBy('id');

        $viewer = currentUserElement() ?? abort(403);
        $hidden = 0;
        $deliveries = $query->latest('id')->limit(self::PER_PAGE)->offset(($page - 1) * self::PER_PAGE)->get();

        // Non-admins only see deliveries about elements they can view themselves (and none
        // about failed jobs or element-less custom triggers), so the log can't be used to
        // learn about content they can't see.
        if (! $viewer->admin) {
            $ids = $deliveries->pluck('elementId')->filter()->unique()->values()->all();
            $viewable = [];
            foreach ($ids as $id) {
                $el = Elements::getElementById((int) $id);
                if ($el !== null && $el->canView($viewer)) {
                    $viewable[(int) $id] = true;
                }
            }
            $before = $deliveries->count();
            $deliveries = $deliveries->filter(fn (Delivery $d) => $d->elementId !== null && isset($viewable[$d->elementId]))->values();
            $hidden = $before - $deliveries->count();
        }

        $rows = $deliveries
            ->map(fn (Delivery $d) => [
                'id' => $d->id,
                'date' => $d->created_at?->format('Y-m-d H:i:s'),
                'rule' => $d->ruleId !== null ? ($rules[$d->ruleId]->name ?? '#'.$d->ruleId) : '—',
                'ruleId' => $d->ruleId,
                'trigger' => Triggers::label($d->trigger),
                'channel' => $channels->get($d->channel)->label ?? $d->channel,
                'recipient' => $d->recipient,
                'site' => $d->siteId ? (Sites::getSiteById($d->siteId, true)->handle ?? '') : '',
                'status' => $d->status,
                // Subjects (and errors) are rendered per recipient, so they can hold fields only
                // that recipient could see: non-admins only read their own and anonymous ones.
                'subject' => self::readable($d, $viewer) ? (string) $d->subject : '',
                'error' => self::readable($d, $viewer) ? (string) $d->error : '',
                'masked' => ! self::readable($d, $viewer),
                'attempts' => $d->attempts,
                'test' => $d->test,
            ])->all();

        return new CpScreenResponse()
            ->title(t('Deliveries', category: 'switchboard'))
            ->contentTemplate('switchboard/_deliveries', [
                'rows' => $rows,
                'rules' => $rules->values()->all(),
                'status' => $status,
                'ruleId' => $ruleId,
                'statuses' => Delivery::STATUSES,
                'page' => $page,
                'pages' => $pages,
                'total' => $viewer->admin ? $total : null,
                'hidden' => $hidden,
            ]);
    }

    /** Admins read every row; others only rows rendered for them, or for an anonymous destination. */
    private static function readable(Delivery $d, User $viewer): bool
    {
        return $viewer->admin || $d->userId === null || $d->userId === (int) $viewer->id;
    }
}
