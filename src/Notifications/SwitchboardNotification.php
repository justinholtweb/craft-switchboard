<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Notifications;

use CraftCms\Cms\Cp\Notifications\CpNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use JustinHoltWeb\Switchboard\Channels\Channels;
use JustinHoltWeb\Switchboard\Delivery\DeliveryLog;
use LogicException;
use Throwable;

/**
 * One delivery: one rendered message, to one recipient, through one channel.
 * Queued, retried, and logged (Switchboard → Deliveries).
 *
 * The message was rendered before queueing, in the recipient's language and
 * limited to what they may see; the notification only formats it per channel.
 */
class SwitchboardNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const string KIND = 'switchboard';

    public int $tries = 3;

    /** @var array{subject: string, body: string, url: string|null, payload: array<string, mixed>} */
    public array $data;

    public function __construct(
        public string $channel,
        Message $message,
        public string $trigger,
        public ?int $ruleId = null,
        public ?string $ruleHandle = null,
        public ?int $deliveryId = null,
        public ?string $routeKey = null,
    ) {
        $this->data = $message->toArray();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function message(): Message
    {
        return Message::fromArray($this->data);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $type = app(Channels::class)->get($this->channel);

        return $type === null ? [] : [$type->via];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = $this->message();
        $mail = new MailMessage()->subject($m->subject)->greeting($m->subject);

        foreach (preg_split('/\R{2,}/', trim($m->body)) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $mail->line(trim($paragraph));
            }
        }

        if ($m->url !== null) {
            $mail->action($m->subject, $m->url);
        }

        return $mail->salutation(' ');
    }

    /**
     * The shape Craft's control panel bell reads ({@see CpNotification}), with
     * `kind: switchboard` so front-end bells can list Switchboard's alone.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $m = $this->message();

        return [
            'kind' => self::KIND,
            'title' => $m->subject,
            'message' => $m->body !== '' ? $m->body : $m->subject,
            'icon' => 'bell',
            'url' => $m->url,
            'buttons' => [],
            'rule' => $this->ruleHandle,
            'trigger' => $this->trigger,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return CpNotification::TYPE;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $m = $this->message();

        return new BroadcastMessage(['title' => $m->subject, 'message' => $m->body, 'url' => $m->url, 'trigger' => $this->trigger]);
    }

    public function broadcastType(): string
    {
        return 'switchboard.notification';
    }

    /**
     * `toVonage()`, `toTwilio()`, … for SMS and channel types added by other packages.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $type = app(Channels::class)->get($this->channel);

        if (str_starts_with($method, 'to') && $type?->message !== null) {
            return ($type->message)($this->message(), $arguments[0] ?? null);
        }

        throw new LogicException("Switchboard notifications can't {$method}() for the {$this->channel} channel.");
    }

    /** Every retry failed. */
    public function failed(Throwable $e): void
    {
        if ($this->deliveryId !== null) {
            app(DeliveryLog::class)->failed($this->deliveryId, $e);
        }
    }
}
