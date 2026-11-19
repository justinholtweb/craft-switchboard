<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Dispatch;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\FieldLayout\FieldLayoutComponent;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Stringable;
use Throwable;

/**
 * What a recipient is told about an element: a plain array, built per recipient.
 *
 * Message templates and webhook payloads only ever see this array, so a field
 * the recipient can't see in the element editor (a user condition on the field or
 * its tab) never reaches them, nor does a related element they can't view.
 * Ad-hoc destinations (an email address, a Slack channel, a webhook) count as an
 * anonymous viewer: no conditional fields, no email addresses, live relations only.
 */
class Snapshot
{
    /**
     * @param  list<string>  $fieldHandles  The rule's payload fields (opt-in).
     * @return array<string, mixed>
     */
    public function build(ElementInterface $element, ?User $recipient, array $fieldHandles = []): array
    {
        $site = rescue(fn () => Sites::getSiteById((int) $element->siteId), null, false);
        $data = [
            'id' => (int) $element->id,
            'kind' => self::kind($element),
            'title' => self::title($element),
            'status' => $element->getStatus(),
            'url' => rescue(fn () => $element->getUrl(), null, false),
            'cpEditUrl' => $recipient !== null && $recipient->can('accessCp') ? rescue(fn () => $element->getCpEditUrl(), null, false) : null,
            'site' => $site ? ['handle' => $site->handle, 'name' => $site->getName(), 'language' => $site->language] : null,
            'dateCreated' => self::date($element->dateCreated ?? null),
            'dateUpdated' => self::date($element->dateUpdated ?? null),
        ];

        if ($element instanceof Entry) {
            $data['section'] = $element->getSection()?->handle;
            $data['entryType'] = rescue(fn () => $element->getType()->handle, null, false);
            $data['postDate'] = self::date($element->postDate);
            $data['author'] = rescue(fn () => $element->getAuthor()?->getName(), null, false);
        } elseif ($element instanceof Asset) {
            $data['volume'] = rescue(fn () => $element->getVolume()->handle, null, false);
            $data['filename'] = $element->getFilename();
            $data['kind'] = 'asset';
        } elseif ($element instanceof User) {
            // Personal details only for Craft users allowed to view this user.
            $canViewUser = $recipient !== null && ($recipient->id === $element->id || $element->canView($recipient));
            $data['username'] = $canViewUser ? $element->username : null;
            $data['email'] = $canViewUser ? $element->email : null;
        }

        $data['fields'] = $this->fields($element, $recipient, $fieldHandles);

        return $data;
    }

    /**
     * @param  list<string>  $handles
     * @return array<string, mixed>
     */
    public function fields(ElementInterface $element, ?User $recipient, array $handles): array
    {
        if ($handles === []) {
            return [];
        }

        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return [];
        }

        $fields = [];

        foreach ($layout->getTabs() as $tab) {
            if (! self::visible($tab, $element, $recipient)) {
                continue;
            }

            foreach ($tab->getElements() as $layoutElement) {
                if (! $layoutElement instanceof CustomField) {
                    continue;
                }

                $handle = $layoutElement->attribute();

                if ($handle === '' || ! in_array($handle, $handles, true) || array_key_exists($handle, $fields)) {
                    continue;
                }

                if (! self::visible($layoutElement, $element, $recipient)) {
                    continue;
                }

                try {
                    $fields[$handle] = $this->value($element->getFieldValue($handle), $layoutElement, $element, $recipient);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $fields;
    }

    /**
     * Whether the custom field with this handle is in the element's layout and
     * visible to $viewer (null: an anonymous viewer, who sees no conditional field).
     */
    public static function fieldVisible(ElementInterface $element, string $handle, ?User $viewer): bool
    {
        foreach ($element->getFieldLayout()?->getTabs() ?? [] as $tab) {
            foreach ($tab->getElements() as $layoutElement) {
                if ($layoutElement instanceof CustomField && $layoutElement->attribute() === $handle) {
                    return self::visible($tab, $element, $viewer) && self::visible($layoutElement, $element, $viewer);
                }
            }
        }

        return false;
    }

    /**
     * Whether a tab or field shows for this recipient in the element editor. An
     * anonymous destination never sees anything behind a user condition.
     */
    public static function visible(FieldLayoutComponent $component, ElementInterface $element, ?User $recipient): bool
    {
        $userCondition = $component->getUserCondition();

        if ($userCondition !== null && $userCondition->getConditionRules()->getRules() !== []) {
            if ($recipient === null || ! $userCondition->matchElement($recipient)) {
                return false;
            }
        }

        $elementCondition = $component->getElementCondition();

        return $elementCondition === null
            || $elementCondition->getConditionRules()->getRules() === []
            || $elementCondition->matchElement($element);
    }

    private function value(mixed $value, CustomField $layoutElement, ElementInterface $element, ?User $recipient): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof ElementQuery) {
            $value = $value->status(null)->all();
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value) && array_is_list($value) && $value !== [] && array_all($value, fn ($v) => $v instanceof ElementInterface)) {
            /** @var list<ElementInterface> $value */
            return array_values(array_map(
                fn (ElementInterface $related) => ['id' => (int) $related->id, 'title' => self::title($related)],
                array_filter($value, fn (ElementInterface $related) => self::canSee($related, $recipient)),
            ));
        }

        if ($value instanceof DateTimeInterface) {
            return self::date($value);
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        try {
            $serialized = $layoutElement->getField()->serializeValue($value, $element);
        } catch (Throwable) {
            return null;
        }

        return is_scalar($serialized) || is_array($serialized) || $serialized === null ? $serialized : null;
    }

    /** A related element: viewable by the recipient, or (anonymous) live with a public URL. */
    private static function canSee(ElementInterface $related, ?User $recipient): bool
    {
        if ($recipient !== null) {
            return rescue(fn () => $related->canView($recipient), false, false);
        }

        $status = $related->getStatus();

        return in_array($status, ['live', 'enabled', 'active'], true) && rescue(fn () => $related->getUrl(), null, false) !== null;
    }

    public static function kind(ElementInterface $element): string
    {
        return match (true) {
            $element instanceof Entry => 'entry',
            $element instanceof User => 'user',
            $element instanceof Asset => 'asset',
            default => strtolower(class_basename($element)),
        };
    }

    public static function title(ElementInterface $element): string
    {
        if ($element instanceof User) {
            return (string) $element->getName();
        }

        return (string) ($element->title ?? '') !== '' ? (string) $element->title : '#'.$element->id;
    }

    private static function date(mixed $date): ?string
    {
        return $date instanceof DateTimeInterface ? $date->format(DATE_ATOM) : null;
    }
}
