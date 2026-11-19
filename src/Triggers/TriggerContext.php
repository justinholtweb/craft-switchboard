<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Triggers;

use CraftCms\Cms\Element\Contracts\ElementInterface;

/**
 * What happened: a trigger, the element it happened to (by type, ID and site),
 * and any extra scalar data (a failed job's name, a custom trigger's variables).
 * Small and serializable — the element is loaded again when the job runs.
 */
final readonly class TriggerContext
{
    /**
     * @param  class-string<ElementInterface>|null  $elementType
     * @param  array<string, scalar|null>  $extra
     */
    public function __construct(
        public string $trigger,
        public ?string $elementType = null,
        public ?int $elementId = null,
        public ?int $siteId = null,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $extra */
    public static function forElement(string $trigger, ElementInterface $element, array $extra = []): self
    {
        return new self($trigger, $element::class, (int) $element->id, (int) $element->siteId, self::scalars($extra));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, scalar|null>
     */
    public static function scalars(array $extra): array
    {
        $clean = [];

        foreach ($extra as $key => $value) {
            if (is_string($key) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $key) && ($value === null || is_scalar($value))) {
                $clean[$key] = is_string($value) ? mb_substr($value, 0, 2000) : $value;
            }
        }

        return $clean;
    }
}
