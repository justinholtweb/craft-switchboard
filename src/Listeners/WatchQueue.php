<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Listeners;

use Illuminate\Queue\Events\JobFailed;
use JustinHoltWeb\Switchboard\Dispatch\Dispatcher;
use JustinHoltWeb\Switchboard\Rules\Rules;
use JustinHoltWeb\Switchboard\Triggers\TriggerContext;
use JustinHoltWeb\Switchboard\Triggers\Triggers;
use Throwable;

/**
 * `queue.failed`: a queued job ran out of retries. Switchboard's own jobs are
 * ignored, so a failing notification can't set off another one.
 */
class WatchQueue
{
    public function __construct(private readonly Rules $rules, private readonly Dispatcher $dispatcher) {}

    public function handle(JobFailed $event): void
    {
        try {
            if (! $this->rules->listening([Triggers::QUEUE_FAILED])) {
                return;
            }

            $name = (string) $event->job->resolveName();

            if (str_contains($name, 'JustinHoltWeb\\Switchboard\\') || str_starts_with($name, 'Switchboard')) {
                return;
            }

            $this->dispatcher->trigger(new TriggerContext(Triggers::QUEUE_FAILED, extra: TriggerContext::scalars([
                'title' => $name,
                'job' => $name,
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'exception' => $event->exception::class,
                'message' => mb_substr($event->exception->getMessage(), 0, 500),
            ])));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
