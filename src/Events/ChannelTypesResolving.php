<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Events;

use JustinHoltWeb\Switchboard\Channels\ChannelType;

/**
 * Fired when Switchboard collects its channel types. Add a {@see ChannelType} for
 * any Laravel notification channel package (`laravel-notification-channels/*`):
 *
 * ```php
 * Event::listen(ChannelTypesResolving::class, fn ($e) => $e->channels['telegram'] = new ChannelType(
 *     key: 'telegram', label: 'Telegram', forUsers: false, forRoutes: true,
 *     via: \NotificationChannels\Telegram\TelegramChannel::class,
 *     message: fn (Message $m, object $to) => TelegramMessage::create($m->subject."\n".$m->body),
 * ));
 * ```
 */
class ChannelTypesResolving
{
    /** @param array<string, ChannelType> $channels */
    public function __construct(public array $channels) {}
}
