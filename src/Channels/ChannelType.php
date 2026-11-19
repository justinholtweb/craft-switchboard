<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Channels;

use Closure;
use JustinHoltWeb\Switchboard\Events\ChannelTypesResolving;
use JustinHoltWeb\Switchboard\Notifications\Message;

/**
 * One way of delivering a notification. Built-in types cover mail, the control
 * panel bell, broadcasting, Slack, Discord, Microsoft Teams, signed webhooks and
 * SMS (Vonage or Twilio, when installed). Add your own from the
 * {@see ChannelTypesResolving} event.
 */
final readonly class ChannelType
{
    /**
     * @param  string  $key  Stored on rules and deliveries (`slack`, `my-channel`).
     * @param  bool  $forUsers  Can deliver to Craft users (mail, bell, broadcast).
     * @param  bool  $forRoutes  Can deliver to an ad-hoc address (an email, a URL, a phone number).
     * @param  string  $via  The Laravel channel: a name (`mail`, `database`, `vonage`) or a channel class.
     * @param  (Closure(string): ?string)|null  $validate  Checks an ad-hoc route; returns an error message or null.
     * @param  (Closure(Message, object): mixed)|null  $message  Builds the channel's message (`toVonage()` etc.) for third-party channels.
     * @param  string  $routeHint  Placeholder for the route field in the CP.
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $forUsers,
        public bool $forRoutes,
        public string $via,
        public ?Closure $validate = null,
        public ?Closure $message = null,
        public string $routeHint = '',
    ) {}

    public function validateRoute(string $to): ?string
    {
        return $this->validate !== null ? ($this->validate)($to) : null;
    }
}
