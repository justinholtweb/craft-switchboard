<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Entry\Elements\Entry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use JustinHoltWeb\Switchboard\Dispatch\Dispatcher;
use JustinHoltWeb\Switchboard\Dispatch\Ledger;
use JustinHoltWeb\Switchboard\Rules\Rules;
use JustinHoltWeb\Switchboard\Triggers\TriggerContext;
use JustinHoltWeb\Switchboard\Triggers\Triggers;

/**
 * Craft saves nothing when a scheduled entry's post date passes, so no event
 * fires. This finds entries that went live since the last run (scheduled every
 * five minutes) and fires `entry.published` for them — once: publications that
 * already notified (from a save) are in the ledger.
 */
class ScanCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:switchboard:scan {--since= : Override the window start (any strtotime string)}';

    protected $description = 'Fires “entry is published” for entries whose post date passed since the last run';

    private const string CACHE_KEY = 'switchboard.scan.lastRun';

    public function handle(Rules $rules, Dispatcher $dispatcher, Ledger $ledger): int
    {
        $now = now();
        $since = $this->option('since') ? now()->parse((string) $this->option('since')) : Cache::get(self::CACHE_KEY, $now->copy()->subHour());
        $fired = 0;

        if ($rules->listening([Triggers::ENTRY_PUBLISHED])) {
            $entries = Entry::find()
                ->site('*')
                ->status(Entry::STATUS_LIVE)
                ->postDate(['and', '> '.$since->toIso8601String(), '<= '.$now->toIso8601String()])
                ->limit(5000)
                ->all();

            foreach ($entries as $entry) {
                if ($ledger->claim(Ledger::published((int) $entry->id, (int) $entry->siteId, $entry->postDate))) {
                    $dispatcher->trigger(TriggerContext::forElement(Triggers::ENTRY_PUBLISHED, $entry));
                    $fired++;
                }
            }
        }

        Cache::forever(self::CACHE_KEY, $now);
        $this->components->info("Fired “published” for {$fired} scheduled entries.");

        return self::SUCCESS;
    }
}
