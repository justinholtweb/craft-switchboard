<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\Request;
use JustinHoltWeb\Switchboard\Channels\Channels;
use JustinHoltWeb\Switchboard\Delivery\Delivery;
use JustinHoltWeb\Switchboard\Rules\Rule;
use JustinHoltWeb\Switchboard\Triggers\Triggers;

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

        $rows = $query->latest('id')->limit(self::PER_PAGE)->offset(($page - 1) * self::PER_PAGE)->get()
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
                'subject' => (string) $d->subject,
                'error' => (string) $d->error,
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
                'total' => $total,
            ]);
    }
}
