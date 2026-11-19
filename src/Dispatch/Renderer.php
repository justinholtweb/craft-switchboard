<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Dispatch;

use Closure;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Template;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\View\TemplateMode;
use JustinHoltWeb\Switchboard\Notifications\Message;
use JustinHoltWeb\Switchboard\Rules\Rule;
use JustinHoltWeb\Switchboard\Triggers\Triggers;

/**
 * Renders a rule's message for one recipient, in their language.
 *
 * - Subject and body typed in the CP are Twig, rendered in Craft's **sandbox**
 *   (no `craft.*` queries, no includes of arbitrary templates, no PHP).
 * - A body template file (`template`) is a site template in Twig or Blade
 *   (picked by extension), written by a developer, so it isn't sandboxed and
 *   also gets the real element as `object`.
 *
 * Both see `element` — the per-recipient {@see Snapshot} — plus `event`, `rule`,
 * `site`, `recipient` and `data`. Output is plain text.
 */
class Renderer
{
    public function __construct(private readonly Snapshot $snapshots) {}

    /** @param array<string, mixed> $extra */
    public function render(Rule $rule, string $trigger, ?ElementInterface $element, ?User $recipient, Site $site, string $locale, array $extra = []): Message
    {
        return self::withLocale($locale, function () use ($rule, $trigger, $element, $recipient, $site, $extra): Message {
            $snapshot = $element !== null ? $this->snapshots->build($element, $recipient, array_values((array) $rule->payloadFields)) : null;
            $data = self::data($trigger, $extra, $recipient);
            $vars = [
                'element' => $snapshot,
                'event' => ['key' => $trigger, 'label' => Triggers::label($trigger)],
                'rule' => ['name' => $rule->name, 'handle' => $rule->handle],
                'site' => ['handle' => $site->handle, 'name' => $site->getName(), 'language' => $site->language],
                'recipient' => $recipient === null ? null : ['name' => $recipient->getName(), 'friendlyName' => $recipient->getFriendlyName()],
                'data' => $data,
            ];
            $params = [
                'title' => (string) ($snapshot['title'] ?? $data['title'] ?? $data['job'] ?? ''),
                'site' => $site->getName(),
            ];

            $subject = trim((string) $rule->subject) !== ''
                ? Template::renderSandboxedString((string) $rule->subject, $vars, TemplateMode::Site)
                : Triggers::defaultSubject($trigger, $params);

            if (trim((string) $rule->template) !== '') {
                $body = Template::renderTemplate((string) $rule->template, $vars + ['object' => $element], TemplateMode::Site);
            } elseif (trim((string) $rule->body) !== '') {
                $body = Template::renderSandboxedString((string) $rule->body, $vars, TemplateMode::Site);
            } else {
                $body = Triggers::defaultBody($trigger, $params);
            }

            $url = null;
            if ($snapshot !== null) {
                $url = $recipient !== null && $snapshot['cpEditUrl'] !== null ? $snapshot['cpEditUrl'] : $snapshot['url'];
            }

            return new Message(
                subject: mb_substr(self::text($subject, singleLine: true), 0, 255),
                body: mb_substr(self::text($body), 0, 10000),
                url: is_string($url) ? $url : null,
                payload: [
                    'rule' => ['handle' => $rule->handle, 'name' => $rule->name],
                    'site' => $site->handle,
                    'element' => $snapshot,
                    'data' => $data,
                ],
            );
        });
    }

    /**
     * A trigger's own data. A failed job's error message is for admins (Craft
     * users) only: ad-hoc destinations get the job's name and the error class.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, scalar|null>
     */
    public static function data(string $trigger, array $extra, ?User $recipient): array
    {
        $data = array_filter($extra, fn ($v) => $v === null || is_scalar($v));

        if ($trigger === Triggers::QUEUE_FAILED && $recipient === null) {
            unset($data['message']);
        }

        return $data;
    }

    public static function text(string $value, bool $singleLine = false): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace("/[ \t]+\n/", "\n", str_replace("\r\n", "\n", $value)) ?? '';
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

        return $singleLine ? trim((string) preg_replace('/\s+/', ' ', $value)) : trim($value);
    }

    /**
     * Runs $callback in $locale (Craft's `t()` / Twig's `|t` follow the app locale).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withLocale(string $locale, Closure $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
