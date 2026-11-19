<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Channels;

use JustinHoltWeb\Switchboard\Notifications\Message;
use JustinHoltWeb\Switchboard\Notifications\SwitchboardNotification;
use JustinHoltWeb\Switchboard\Rules\Rules;
use RuntimeException;

/**
 * Posts a notification to Slack, Discord, Microsoft Teams or a generic webhook.
 *
 * The URL isn't carried by the queued notification: it's looked up from the
 * (encrypted) rule when the job runs, so it never sits in the jobs table.
 */
class HttpChannel
{
    public function __construct(private readonly SafeHttp $http, private readonly Rules $rules) {}

    public function send(object $notifiable, SwitchboardNotification $notification): void
    {
        $rule = $notification->ruleId !== null ? $this->rules->get($notification->ruleId) : null;
        $route = $rule?->findRoute((string) $notification->routeKey);

        if ($rule === null || $route === null || $route['channel'] !== $notification->channel) {
            throw new RuntimeException('The rule or its destination no longer exists.');
        }

        $url = SafeHttp::resolveRoute($route['to']);

        if ($url === '') {
            throw new RuntimeException('The destination’s environment variable is empty.');
        }

        $message = $notification->message();
        $body = (string) json_encode(self::body($notification->channel, $message, $notification), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = [];

        if ($notification->channel === Channels::WEBHOOK) {
            $headers = self::signatureHeaders($body, (string) $rule->signingSecret, time()) + [
                'Switchboard-Event' => $notification->trigger,
                'Switchboard-Delivery' => (string) $notification->deliveryId,
            ];
        }

        $this->http->postJson($url, $body, self::hosts($notification->channel), $headers);
    }

    /** @return list<string> */
    public static function hosts(string $channel): array
    {
        return match ($channel) {
            Channels::SLACK => ['hooks.slack.com'],
            Channels::DISCORD => ['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'],
            Channels::TEAMS => ['*.logic.azure.com', '*.webhook.office.com', '*.powerplatform.com', '*.environment.api.powerplatform.com'],
            default => [],
        };
    }

    /**
     * `Switchboard-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">`, keyed
     * with the rule's signing secret. Receivers should reject old timestamps.
     *
     * @return array<string, string>
     */
    public static function signatureHeaders(string $body, string $secret, int $time): array
    {
        return ['Switchboard-Signature' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret)];
    }

    public static function verify(string $body, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if (! preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/D', $header, $m)) {
            return false;
        }

        $now ??= time();

        return abs($now - (int) $m[1]) <= $tolerance
            && hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2]);
    }

    /** @return array<string, mixed> */
    public static function body(string $channel, Message $message, SwitchboardNotification $notification): array
    {
        return match ($channel) {
            Channels::SLACK => ['text' => self::slack($message)],
            // No @everyone / role pings from content.
            Channels::DISCORD => ['content' => mb_substr(self::discord($message), 0, 2000), 'allowed_mentions' => ['parse' => []]],
            Channels::TEAMS => self::teams($message),
            default => [
                'id' => (string) $notification->deliveryId,
                'event' => $notification->trigger,
                'subject' => $message->subject,
                'message' => $message->body,
                'url' => $message->url,
                'sentAt' => gmdate(DATE_ATOM),
            ] + $message->payload,
        };
    }

    private static function slack(Message $m): string
    {
        $esc = fn (string $s) => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
        $text = '*'.$esc($m->subject).'*';
        $text .= $m->body !== '' ? "\n".$esc($m->body) : '';

        return $text.($m->url ? "\n<".$esc($m->url).'|'.$esc($m->subject).'>' : '');
    }

    private static function discord(Message $m): string
    {
        // Markdown is left alone (it's the point of Discord), but mentions are disabled above.
        return '**'.$m->subject.'**'.($m->body !== '' ? "\n".$m->body : '').($m->url ? "\n".$m->url : '');
    }

    /** @return array<string, mixed> */
    private static function teams(Message $m): array
    {
        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => [
                ['type' => 'TextBlock', 'text' => $m->subject, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
                ...($m->body !== '' ? [['type' => 'TextBlock', 'text' => $m->body, 'wrap' => true]] : []),
            ],
            'actions' => $m->url ? [['type' => 'Action.OpenUrl', 'title' => 'Open', 'url' => $m->url]] : [],
        ];

        return ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]]];
    }
}
