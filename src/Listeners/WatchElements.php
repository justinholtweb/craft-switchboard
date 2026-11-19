<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Listeners;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Element\Events\ElementSaving;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\User\Events\UserActivated;
use JustinHoltWeb\Switchboard\Dispatch\Dispatcher;
use JustinHoltWeb\Switchboard\Dispatch\Ledger;
use JustinHoltWeb\Switchboard\Rules\Rules;
use JustinHoltWeb\Switchboard\Triggers\TriggerContext;
use JustinHoltWeb\Switchboard\Triggers\Triggers;
use Throwable;

/**
 * Turns element events into triggers. Cheap when no rule listens: each handler
 * first asks the (per-request cached) rule list.
 *
 * “Published” means the entry is live on a site after a save and wasn't before
 * (status read in ElementSaving, per site — propagated saves included), and
 * that publication (entry, site, post date) hasn't notified yet. Entries that go
 * live because their post date passes are caught by `craft:switchboard:scan`.
 */
class WatchElements
{
    /** @var array<string, bool> “id:siteId” → live before this save */
    private array $wasLive = [];

    /** @var array<string, true> */
    private array $fired = [];

    public function __construct(
        private readonly Rules $rules,
        private readonly Dispatcher $dispatcher,
        private readonly Ledger $ledger,
    ) {}

    public function saving(ElementSaving $event): void
    {
        $element = $event->element;

        if (! $element instanceof Entry || $element->id === null || ! self::isCanonical($element)
            || ! $this->rules->listening([Triggers::ENTRY_PUBLISHED, Triggers::ENTRY_UPDATED])) {
            return;
        }

        $this->wasLive[$element->id.':'.$element->siteId] = Entry::find()
            ->id($element->id)->siteId($element->siteId)->status(Entry::STATUS_LIVE)->exists();
    }

    public function saved(ElementSaved $event): void
    {
        try {
            $element = $event->element;

            if ($element instanceof Entry) {
                $this->entrySaved($element);
            } elseif ($element instanceof User && $event->isNew) {
                $this->fire(Triggers::USER_REGISTERED, $element);
            } elseif ($element instanceof Asset && $event->isNew && self::isCanonical($element)) {
                $this->fire(Triggers::ASSET_UPLOADED, $element);
            }
        } catch (Throwable $e) {
            // A notification problem must never break a save.
            report($e);
        }
    }

    public function deleted(ElementDeleted $event): void
    {
        $element = $event->element;

        if ($element instanceof Entry && self::isCanonical($element)) {
            rescue(fn () => $this->fire(Triggers::ENTRY_DELETED, $element));
        }
    }

    public function activated(UserActivated $event): void
    {
        rescue(fn () => $this->fire(Triggers::USER_ACTIVATED, $event->user));
    }

    private function entrySaved(Entry $entry): void
    {
        if (! self::isCanonical($entry) || $entry->id === null) {
            return;
        }

        $key = $entry->id.':'.$entry->siteId;
        $was = $this->wasLive[$key] ?? false;
        unset($this->wasLive[$key]);

        if ($entry->getStatus() !== Entry::STATUS_LIVE) {
            return;
        }

        if (! $was) {
            if ($this->rules->listening([Triggers::ENTRY_PUBLISHED])
                && $this->ledger->claim(Ledger::published((int) $entry->id, (int) $entry->siteId, $entry->postDate))) {
                $this->fire(Triggers::ENTRY_PUBLISHED, $entry);
            }

            return;
        }

        if (! $entry->propagating && ! $entry->resaving) {
            $this->fire(Triggers::ENTRY_UPDATED, $entry);
        }
    }

    private function fire(string $trigger, ElementInterface $element): void
    {
        if (! $this->rules->listening([$trigger])) {
            return;
        }

        $key = $trigger.':'.$element->id.':'.$element->siteId;

        if (isset($this->fired[$key])) {
            return;
        }

        $this->fired[$key] = true;
        $this->dispatcher->trigger(TriggerContext::forElement($trigger, $element));
    }

    private static function isCanonical(ElementInterface $element): bool
    {
        return ! $element->getIsDraft() && ! $element->getIsRevision();
    }

    /** @return array<class-string, string> */
    public function subscribe(): array
    {
        return [
            ElementSaving::class => 'saving',
            ElementSaved::class => 'saved',
            ElementDeleted::class => 'deleted',
            UserActivated::class => 'activated',
        ];
    }
}
