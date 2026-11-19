<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use JustinHoltWeb\Switchboard\Dispatch\Dispatcher;
use JustinHoltWeb\Switchboard\Triggers\TriggerContext;

/**
 * Evaluates one rule against one event, off the request: loads the element again,
 * checks conditions, resolves and permission-checks recipients, renders their
 * messages and queues one notification per recipient and channel.
 *
 * Queued after the surrounding transaction commits, so a save that rolls back
 * never notifies anyone.
 */
class ProcessTrigger implements ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $ruleId, public TriggerContext $context) {}

    public function handle(Dispatcher $dispatcher): void
    {
        $dispatcher->run($this->ruleId, $this->context);
    }

    public function displayName(): string
    {
        return 'Switchboard: '.$this->context->trigger;
    }
}
