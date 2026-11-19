<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Notifications;

/**
 * A rendered notification, already in the recipient's language and limited to
 * what that recipient may see. Plain text; each channel formats it.
 */
final readonly class Message
{
    /**
     * @param  array<string, mixed>  $payload  The JSON a webhook receives (event, rule, site, element snapshot).
     */
    public function __construct(
        public string $subject,
        public string $body,
        public ?string $url = null,
        public array $payload = [],
    ) {}

    /** @return array{subject: string, body: string, url: string|null, payload: array<string, mixed>} */
    public function toArray(): array
    {
        return ['subject' => $this->subject, 'body' => $this->body, 'url' => $this->url, 'payload' => $this->payload];
    }

    /** @param array{subject?: mixed, body?: mixed, url?: mixed, payload?: mixed} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['subject'] ?? ''),
            (string) ($data['body'] ?? ''),
            isset($data['url']) ? (string) $data['url'] : null,
            is_array($data['payload'] ?? null) ? $data['payload'] : [],
        );
    }
}
