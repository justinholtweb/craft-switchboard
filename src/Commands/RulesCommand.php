<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Switchboard\Delivery\Delivery;
use JustinHoltWeb\Switchboard\Rules\Rule;
use JustinHoltWeb\Switchboard\Rules\Rules;

/** Lists rules with their trigger, channels and recent delivery counts. Never prints destinations. */
class RulesCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:switchboard:rules';

    protected $description = 'Lists notification rules and their recent deliveries';

    public function handle(Rules $rules): int
    {
        $since = now()->subDay();
        $rows = $rules->all()->map(fn (Rule $rule) => [
            $rule->handle,
            $rule->enabled ? 'yes' : 'no',
            $rule->trigger,
            implode(', ', $rule->userChannels()) ?: '—',
            (string) count($rule->routeList()),
            (string) Delivery::query()->where('ruleId', $rule->id)->where('status', Delivery::SENT)->where('created_at', '>=', $since)->count(),
            (string) Delivery::query()->where('ruleId', $rule->id)->where('status', Delivery::FAILED)->where('created_at', '>=', $since)->count(),
        ])->all();

        $this->table(['Handle', 'Enabled', 'Trigger', 'User channels', 'Destinations', 'Sent (24h)', 'Failed (24h)'], $rows);

        return self::SUCCESS;
    }
}
