<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Channels;

use Illuminate\Notifications\Messages\VonageMessage;
use JustinHoltWeb\Switchboard\Events\ChannelTypesResolving;
use JustinHoltWeb\Switchboard\Notifications\Message;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

use function CraftCms\Cms\t;

/**
 * The channel types this install can deliver through.
 */
class Channels
{
    public const string MAIL = 'mail';

    public const string BELL = 'database';

    public const string BROADCAST = 'broadcast';

    public const string SLACK = 'slack';

    public const string DISCORD = 'discord';

    public const string TEAMS = 'teams';

    public const string WEBHOOK = 'webhook';

    public const string SMS = 'sms';

    public const string KEY_PATTERN = '/^[a-z][a-z0-9\-_]{0,31}$/D';

    /** The HTTP channel types, which only post to a URL. */
    public const array HTTP = [self::SLACK, self::DISCORD, self::TEAMS, self::WEBHOOK];

    /** @var array<string, ChannelType>|null */
    private ?array $types = null;

    public function __construct(private readonly SafeHttp $http) {}

    /** @return array<string, ChannelType> */
    public function all(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $url = fn (array $hosts, string $what) => function (string $to) use ($hosts, $what): ?string {
            return $this->http->validateConfigured($to, $hosts, $what);
        };

        $types = [
            self::MAIL => new ChannelType(self::MAIL, t('Email', category: 'switchboard'), true, true, 'mail',
                validate: fn (string $to) => filter_var($to, FILTER_VALIDATE_EMAIL) !== false || SafeHttp::isEnvReference($to)
                    ? null : t('Enter an email address.', category: 'switchboard'),
                routeHint: 'editor@example.com'),
            self::BELL => new ChannelType(self::BELL, t('Notification bell (control panel and front end)', category: 'switchboard'), true, false, 'database'),
            self::BROADCAST => new ChannelType(self::BROADCAST, t('Broadcast (real time, to the user’s private channel)', category: 'switchboard'), true, false, 'broadcast'),
            self::SLACK => new ChannelType(self::SLACK, t('Slack (incoming webhook)', category: 'switchboard'), false, true, HttpChannel::class,
                validate: $url(['hooks.slack.com'], 'Slack'), routeHint: 'https://hooks.slack.com/services/…'),
            self::DISCORD => new ChannelType(self::DISCORD, t('Discord (webhook)', category: 'switchboard'), false, true, HttpChannel::class,
                validate: $url(['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'], 'Discord'), routeHint: 'https://discord.com/api/webhooks/…'),
            self::TEAMS => new ChannelType(self::TEAMS, t('Microsoft Teams (Workflows webhook)', category: 'switchboard'), false, true, HttpChannel::class,
                validate: $url(['*.logic.azure.com', '*.webhook.office.com', '*.powerplatform.com', '*.environment.api.powerplatform.com'], 'Teams'),
                routeHint: 'https://…logic.azure.com/workflows/…'),
            self::WEBHOOK => new ChannelType(self::WEBHOOK, t('Webhook (signed JSON POST)', category: 'switchboard'), false, true, HttpChannel::class,
                validate: $url([], 'webhook'), routeHint: 'https://example.com/hooks/craft'),
        ];

        if ($sms = self::smsType()) {
            $types[self::SMS] = $sms;
        }

        event($event = new ChannelTypesResolving($types));

        $this->types = [];

        foreach ($event->channels as $key => $type) {
            if ($type instanceof ChannelType && $key === $type->key && preg_match(self::KEY_PATTERN, $key)) {
                $this->types[$key] = $type;
            }
        }

        return $this->types;
    }

    public function get(string $key): ?ChannelType
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, ChannelType> */
    public function forUsers(): array
    {
        return array_filter($this->all(), fn (ChannelType $t) => $t->forUsers);
    }

    /** @return array<string, ChannelType> */
    public function forRoutes(): array
    {
        return array_filter($this->all(), fn (ChannelType $t) => $t->forRoutes);
    }

    /**
     * SMS through whichever supported package is installed: Laravel's Vonage
     * channel (`laravel/vonage-notification-channel`), else Twilio
     * (`laravel-notification-channels/twilio`). Configure the package as usual.
     */
    private static function smsType(): ?ChannelType
    {
        $validate = fn (string $to) => preg_match('/^\+[1-9]\d{6,14}$/D', $to) || SafeHttp::isEnvReference($to)
            ? null : t('Enter a phone number in international format, like +14155550123.', category: 'switchboard');
        $text = fn (Message $m): string => mb_substr(trim($m->subject."\n".$m->body.($m->url ? "\n".$m->url : '')), 0, 1000);

        if (class_exists(VonageMessage::class)) {
            return new ChannelType(self::SMS, t('SMS (Vonage)', category: 'switchboard'), false, true, 'vonage',
                validate: $validate,
                message: fn (Message $m) => new VonageMessage($text($m)),
                routeHint: '+14155550123');
        }

        if (class_exists(TwilioChannel::class) && class_exists(TwilioSmsMessage::class)) {
            return new ChannelType(self::SMS, t('SMS (Twilio)', category: 'switchboard'), false, true, TwilioChannel::class,
                validate: $validate,
                message: fn (Message $m) => new TwilioSmsMessage($text($m)),
                routeHint: '+14155550123');
        }

        return null;
    }
}
