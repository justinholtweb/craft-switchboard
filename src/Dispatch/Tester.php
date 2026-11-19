<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Dispatch;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\Switchboard\Rules\Rule;
use JustinHoltWeb\Switchboard\Triggers\TriggerContext;
use JustinHoltWeb\Switchboard\Triggers\Triggers;

use function CraftCms\Cms\t;

/**
 * “Send test”: the rule's message, rendered for one user about a real element
 * (the one given, or the newest from the rule's sources that user may view).
 * The rule's recipients and conditions are ignored, so a test never reveals
 * whether an element matches; its channels are used. Ad-hoc destinations only
 * when asked.
 */
class Tester
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    /** @return array{queued: int, skipped: int}|array{error: string} */
    public function send(Rule $rule, User $user, ?int $elementId = null, bool $destinations = false): array
    {
        $type = Triggers::all()[$rule->trigger]['elementType'] ?? null;

        if ($type === null && ! Triggers::isCustom($rule->trigger)) {
            $context = new TriggerContext($rule->trigger, extra: [
                'title' => 'App\\Jobs\\ExampleJob', 'job' => 'App\\Jobs\\ExampleJob', 'queue' => 'default',
                'connection' => 'database', 'exception' => 'RuntimeException', 'message' => 'This is a test.',
            ]);

            return $this->dispatcher->deliver($rule, $context, $user, $destinations, test: true);
        }

        $element = $elementId !== null ? $this->given($elementId, $type) : $this->latest($rule, $type, $user);

        if ($element === null) {
            return ['error' => t('No element to test with: there’s none of this kind that you can view.', category: 'switchboard')];
        }

        if (! $element->canView($user)) {
            return ['error' => t('You can’t view that element.', category: 'switchboard')];
        }

        $context = TriggerContext::forElement($rule->trigger, $element, Triggers::isCustom($rule->trigger) ? ['test' => true] : []);

        return $this->dispatcher->deliver($rule, $context, $user, $destinations, test: true);
    }

    /** @param class-string|null $type */
    private function given(int $id, ?string $type): ?ElementInterface
    {
        $element = Elements::getElementById($id, $type);

        return $element instanceof ElementInterface && ! $element->getIsDraft() && ! $element->getIsRevision() ? $element : null;
    }

    /** @param class-string|null $type */
    private function latest(Rule $rule, ?string $type, User $user): ?ElementInterface
    {
        // The rule's sources narrow the pick (they're configuration, not content); its conditions don't run.
        $sources = array_values(array_filter(array_map('strval', (array) $rule->sources)));
        $query = match ($type) {
            Entry::class => Entry::find()->status(null)->section($sources === [] ? null : array_values(array_filter(array_map(fn ($uid) => Sections::getSectionByUid($uid)?->handle, $sources)))),
            Asset::class => Asset::find()->volume($sources === [] ? null : array_values(array_filter(array_map(fn ($uid) => Volumes::getVolumeByUid($uid)?->handle, $sources)))),
            User::class => User::find()->status(null)->groupId($sources === [] ? null : array_values(array_filter(array_map(fn ($uid) => UserGroups::getGroupByUid($uid)?->id, $sources)))),
            default => null,
        };

        if ($query === null) {
            return null;
        }

        foreach ($query->orderBy('elements.dateCreated desc')->limit(25)->all() as $element) {
            if ($element instanceof ElementInterface && $element->canView($user)) {
                return $element;
            }
        }

        return null;
    }
}
