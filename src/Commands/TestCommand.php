<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Console\Command;
use JustinHoltWeb\Switchboard\Dispatch\Tester;
use JustinHoltWeb\Switchboard\Rules\Rules;

/** Sends a rule's message to one user (and optionally its destinations), about its latest matching element. */
class TestCommand extends Command
{
    use CraftCommand;

    protected $signature = 'craft:switchboard:test {rule : The rule handle} {--user= : Username or email of the recipient} {--element= : An element ID to use} {--destinations : Also post to the rule’s ad-hoc destinations}';

    protected $description = 'Sends a test notification for a rule';

    public function handle(Rules $rules, Tester $tester): int
    {
        $rule = $rules->getByHandle((string) $this->argument('rule'));

        if ($rule === null) {
            $this->components->error('No rule has that handle.');

            return self::FAILURE;
        }

        $who = (string) $this->option('user');
        $user = $who === '' ? null : (User::find()->username($who)->status(null)->one() ?? User::find()->email($who)->status(null)->one());

        if (! $user instanceof User) {
            $this->components->error('Pass --user=<username or email>.');

            return self::FAILURE;
        }

        $result = $tester->send($rule, $user, $this->option('element') !== null ? (int) $this->option('element') : null, (bool) $this->option('destinations'));

        if (isset($result['error'])) {
            $this->components->error($result['error']);

            return self::FAILURE;
        }

        $this->components->info("Queued {$result['queued']} test deliveries ({$result['skipped']} skipped).");

        return self::SUCCESS;
    }
}
