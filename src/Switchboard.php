<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Facades\Sites;
use InvalidArgumentException;
use JustinHoltWeb\Switchboard\Dispatch\Dispatcher;
use JustinHoltWeb\Switchboard\Triggers\TriggerContext;
use JustinHoltWeb\Switchboard\Triggers\Triggers;

/**
 * Switchboard's PHP API.
 *
 * ```php
 * app(Switchboard::class)->fire('order-shipped', $order, ['tracking' => $code]);
 * ```
 *
 * fires every enabled rule whose trigger is `custom:order-shipped`. Recipients
 * are permission-checked against the element as usual; the data is available to
 * message templates as `data`.
 */
class Switchboard
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    /**
     * @param  array<string, mixed>  $data  Scalars only; other values are dropped.
     * @return int How many rules were queued.
     */
    public function fire(string $name, ?ElementInterface $element = null, array $data = [], ?string $siteHandle = null): int
    {
        $trigger = 'custom:'.$name;

        if (! preg_match(Triggers::CUSTOM_PATTERN, $trigger)) {
            throw new InvalidArgumentException("“{$name}” isn’t a valid custom trigger name (lowercase letters, numbers, - _ .).");
        }

        $siteId = null;

        if ($siteHandle !== null) {
            $site = Sites::getSiteByHandle($siteHandle) ?? throw new InvalidArgumentException("Unknown site “{$siteHandle}”.");
            $siteId = (int) $site->id;
        }

        $context = $element !== null
            ? new TriggerContext($trigger, $element::class, (int) $element->id, $siteId ?? (int) $element->siteId, TriggerContext::scalars($data))
            : new TriggerContext($trigger, siteId: $siteId, extra: TriggerContext::scalars($data));

        return $this->dispatcher->trigger($context);
    }
}
