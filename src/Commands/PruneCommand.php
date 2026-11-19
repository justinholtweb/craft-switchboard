<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Switchboard\Delivery\DeliveryLog;
use JustinHoltWeb\Switchboard\Settings;

/** Deletes delivery records older than the retention setting (scheduled daily). */
class PruneCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:switchboard:prune {--days= : Override the retention setting}';

    protected $description = 'Deletes old delivery log records';

    public function handle(DeliveryLog $log, Settings $settings): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : $settings->logRetentionDays;
        $count = $log->prune($days);
        $this->components->info("Pruned {$count} delivery records older than {$days} days.");

        return self::SUCCESS;
    }
}
