<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Dispatch;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\User\Elements\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use JustinHoltWeb\Switchboard\Rules\Rule;
use Stringable;
use Throwable;

/**
 * A rule's filters: its sources (sections, volumes or user groups) and its
 * conditions (attribute / operator / value rows, all of which must match).
 */
class Conditions
{
    public const array OPERATORS = ['equals', 'notEquals', 'contains', 'notContains', 'empty', 'notEmpty'];

    /** Element attributes conditions can test, besides custom fields. */
    public const array ATTRIBUTES = ['title', 'slug', 'uri', 'status', 'section', 'type', 'volume', 'kind', 'filename', 'username', 'email'];

    public static function sourcesMatch(Rule $rule, ?ElementInterface $element): bool
    {
        $sources = array_values(array_filter(array_map('strval', (array) $rule->sources)));

        if ($sources === [] || $element === null) {
            return true;
        }

        try {
            return match (true) {
                $element instanceof Entry => in_array((string) $element->getSection()?->uid, $sources, true),
                $element instanceof Asset => in_array((string) $element->getVolume()->uid, $sources, true),
                $element instanceof User => array_any($element->getGroups(), fn ($g) => in_array((string) $g->uid, $sources, true)),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $extra */
    public static function match(Rule $rule, ?ElementInterface $element, array $extra = []): bool
    {
        foreach ((array) $rule->conditions as $condition) {
            if (! is_array($condition) || ! isset($condition['attribute'], $condition['operator'])) {
                continue;
            }

            $actual = self::value($element, (string) $condition['attribute'], $extra);

            if (! self::test($actual, (string) $condition['operator'], (string) ($condition['value'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    public static function test(?string $actual, string $operator, string $expected): bool
    {
        $a = mb_strtolower(trim((string) $actual));
        $e = mb_strtolower(trim($expected));

        return match ($operator) {
            'equals' => $a === $e,
            'notEquals' => $a !== $e,
            'contains' => $e === '' || str_contains($a, $e),
            'notContains' => $e === '' || ! str_contains($a, $e),
            'empty' => $a === '',
            'notEmpty' => $a !== '',
            default => false,
        };
    }

    /**
     * An attribute or custom field as a string (lists joined with commas, related
     * elements by title). Without an element, the trigger's own data is used
     * (e.g. `job` or `queue` for a failed job).
     *
     * @param  array<string, mixed>  $extra
     */
    public static function value(?ElementInterface $element, string $attribute, array $extra = []): ?string
    {
        if ($element === null) {
            $value = $extra[$attribute] ?? null;

            return is_scalar($value) ? (string) $value : null;
        }

        try {
            $value = match ($attribute) {
                'section' => $element instanceof Entry ? $element->getSection()?->handle : null,
                'type' => $element instanceof Entry ? $element->getType()->handle : null,
                'volume' => $element instanceof Asset ? $element->getVolume()->handle : null,
                'kind' => Snapshot::kind($element),
                'status' => $element->getStatus(),
                'filename' => $element instanceof Asset ? $element->getFilename() : null,
                'title' => Snapshot::title($element),
                'slug', 'uri' => $element->{$attribute} ?? null,
                'username', 'email' => $element instanceof User ? $element->{$attribute} : null,
                default => $element->getFieldLayout()?->getFieldByHandle($attribute) !== null ? $element->getFieldValue($attribute) : null,
            };
        } catch (Throwable) {
            return null;
        }

        return self::stringify($value);
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value instanceof ElementQuery) {
            $value = $value->status(null)->all();
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof ElementInterface => Snapshot::title($value),
            $value instanceof Stringable => (string) $value,
            is_array($value) => implode(', ', array_filter(array_map(fn ($v) => self::stringify($v), $value), fn ($v) => $v !== null && $v !== '')),
            default => null,
        };
    }
}
