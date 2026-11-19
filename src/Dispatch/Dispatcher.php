<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Dispatch;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use JustinHoltWeb\Switchboard\Channels\Channels;
use JustinHoltWeb\Switchboard\Channels\ChannelType;
use JustinHoltWeb\Switchboard\Channels\SafeHttp;
use JustinHoltWeb\Switchboard\Delivery\Delivery;
use JustinHoltWeb\Switchboard\Delivery\DeliveryLog;
use JustinHoltWeb\Switchboard\Jobs\ProcessTrigger;
use JustinHoltWeb\Switchboard\Notifications\Message;
use JustinHoltWeb\Switchboard\Notifications\SwitchboardNotification;
use JustinHoltWeb\Switchboard\Rules\Rule;
use JustinHoltWeb\Switchboard\Rules\Rules;
use JustinHoltWeb\Switchboard\Settings;
use JustinHoltWeb\Switchboard\Triggers\TriggerContext;
use JustinHoltWeb\Switchboard\Triggers\Triggers;
use Throwable;

/**
 * Turns events into deliveries.
 *
 * {@see trigger()} runs in the request: it only finds the rules listening for the
 * event and queues a {@see ProcessTrigger} job per rule. {@see deliver()} runs in
 * that job and does the real work.
 */
class Dispatcher
{
    public function __construct(
        private readonly Rules $rules,
        private readonly Recipients $recipients,
        private readonly Renderer $renderer,
        private readonly Channels $channels,
        private readonly DeliveryLog $log,
        private readonly Settings $settings,
    ) {}

    /** Queues the rules listening for this event. Returns how many. */
    public function trigger(TriggerContext $context): int
    {
        $count = 0;

        foreach ($this->rules->forTrigger($context->trigger) as $rule) {
            if (Triggers::isLocalized($context->trigger) && ! $rule->appliesToSite($context->siteId)) {
                continue;
            }

            ProcessTrigger::dispatch($rule->id, $context)->onQueue($this->settings->queueName());
            $count++;
        }

        return $count;
    }

    /** @return array{queued: int, skipped: int} */
    public function run(int $ruleId, TriggerContext $context): array
    {
        $rule = $this->rules->get($ruleId);

        if ($rule === null || ! $rule->enabled) {
            return ['queued' => 0, 'skipped' => 0];
        }

        return $this->deliver($rule, $context);
    }

    /**
     * @param  User|null  $only  A test send: notify this user alone, whatever the rule's recipients.
     * @param  bool  $routes  Also deliver to the rule's ad-hoc destinations.
     * @return array{queued: int, skipped: int}
     */
    public function deliver(Rule $rule, TriggerContext $context, ?User $only = null, bool $routes = true, bool $test = false): array
    {
        $result = ['queued' => 0, 'skipped' => 0];
        $element = $this->element($context);

        if ($context->elementType !== null && $element === null) {
            return $result; // gone (hard-deleted) before the job ran
        }

        if (! $test && ! $this->stillApplies($rule, $context, $element)) {
            return $result;
        }

        // A test send ignores sources and conditions: it's about the message, and must not
        // reveal whether an element matches.
        if (! $test && (! Conditions::sourcesMatch($rule, $element) || ! Conditions::match($rule, $element, $context->extra))) {
            return $result;
        }

        $site = $this->site($element, $context);
        $users = $only !== null ? [$only] : $this->recipients->users($rule, $element, $this->settings->maxRecipients);
        $seen = [];

        foreach ($users as $user) {
            $seen[(int) $user->id] = true;
            $this->toUser($rule, $context, $element, $site, $user, $rule->userChannels(), $test, $result);
        }

        foreach ($routes ? $rule->routeList() : [] as $route) {
            $type = $this->channels->get($route['channel']);

            if ($type === null || ! $type->forRoutes) {
                continue;
            }

            // An address that belongs to a Craft user is that user: their permissions apply.
            if ($type->key === Channels::MAIL) {
                $owner = User::find()->email(SafeHttp::resolveRoute($route['to']))->status(null)->one();

                if ($owner instanceof User) {
                    if (! isset($seen[(int) $owner->id])) {
                        $seen[(int) $owner->id] = true;
                        $this->toUser($rule, $context, $element, $site, $owner, [Channels::MAIL], $test, $result);
                    }

                    continue;
                }
            }

            $this->toRoute($rule, $context, $element, $site, $type, $route['to'], $test, $result);
        }

        return $result;
    }

    /**
     * @param  list<string>  $channelKeys
     * @param  array{queued: int, skipped: int}  $result
     */
    private function toUser(Rule $rule, TriggerContext $context, ?ElementInterface $element, Site $site, User $user, array $channelKeys, bool $test, array &$result): void
    {
        $base = $this->base($rule, $context, $element, $site, $test) + ['recipient' => 'user:'.$user->id, 'userId' => (int) $user->id];
        $types = array_values(array_filter(array_map(fn (string $k) => $this->channels->get($k), $channelKeys), fn (?ChannelType $t) => $t !== null && $t->forUsers));

        if ($types === []) {
            return;
        }

        if (($reason = $this->recipients->denies($user, $rule, $element, $site)) !== null) {
            $this->log->create($base + ['channel' => $types[0]->key, 'status' => Delivery::SKIPPED, 'error' => $reason]);
            $result['skipped']++;

            return;
        }

        $locale = $user->getPreferredLanguage() ?? $site->language;
        $message = $this->renderOrLog($rule, $context, $element, $user, $site, $locale, $base, $types[0]->key);

        if ($message === null) {
            $result['skipped']++;

            return;
        }

        foreach ($types as $type) {
            $delivery = $this->log->create($base + ['channel' => $type->key, 'subject' => $message->subject]);
            $notification = $this->notification($rule, $context, $type, $message, $delivery->id, null)->locale($locale);
            $this->send(fn () => Notification::send($user, $notification), $delivery);
            $result['queued']++;
        }
    }

    /** @param array{queued: int, skipped: int} $result */
    private function toRoute(Rule $rule, TriggerContext $context, ?ElementInterface $element, Site $site, ChannelType $type, string $to, bool $test, array &$result): void
    {
        $base = $this->base($rule, $context, $element, $site, $test) + ['channel' => $type->key, 'recipient' => self::mask($type->key, $to)];
        $message = $this->renderOrLog($rule, $context, $element, null, $site, $site->language, $base, $type->key);

        if ($message === null) {
            $result['skipped']++;

            return;
        }

        $delivery = $this->log->create($base + ['subject' => $message->subject]);
        $isHttp = in_array($type->key, Channels::HTTP, true);
        $notification = $this->notification($rule, $context, $type, $message, $delivery->id, $isHttp ? Rule::routeKey($type->key, $to) : null)
            ->locale($site->language);
        // HTTP destinations are looked up from the rule when the job runs (the URL stays out of the jobs table).
        $notifiable = Notification::route($type->via, $isHttp ? $type->key : SafeHttp::resolveRoute($to));
        $this->send(fn () => $notifiable->notify($notification), $delivery);
        $result['queued']++;
    }

    private function notification(Rule $rule, TriggerContext $context, ChannelType $type, Message $message, int $deliveryId, ?string $routeKey): SwitchboardNotification
    {
        $notification = new SwitchboardNotification($type->key, $message, $context->trigger, $rule->id, $rule->handle, $deliveryId, $routeKey);
        $notification->onQueue($this->settings->queueName());

        return $notification;
    }

    /** @param callable(): void $send */
    private function send(callable $send, Delivery $delivery): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
            $this->log->failed($delivery->id, $e);
        }
    }

    /** @param array<string, mixed> $base */
    private function renderOrLog(Rule $rule, TriggerContext $context, ?ElementInterface $element, ?User $user, Site $site, string $locale, array $base, string $channel): ?Message
    {
        try {
            return $this->renderer->render($rule, $context->trigger, $element, $user, $site, $locale, $context->extra);
        } catch (Throwable $e) {
            Log::warning('Switchboard couldn’t render rule “'.$rule->handle.'”: '.$e->getMessage());
            $this->log->create($base + ['channel' => $channel, 'status' => Delivery::FAILED, 'error' => 'Template error: '.mb_substr(DeliveryLog::describe($e), 0, 400)]);

            return null;
        }
    }

    /** @return array<string, mixed> */
    private function base(Rule $rule, TriggerContext $context, ?ElementInterface $element, Site $site, bool $test): array
    {
        return [
            'ruleId' => $rule->id,
            'trigger' => $context->trigger,
            'elementId' => $element?->id,
            'siteId' => $site->id,
            'test' => $test,
        ];
    }

    /** Things may have changed between the event and the job (e.g. unpublished again). */
    private function stillApplies(Rule $rule, TriggerContext $context, ?ElementInterface $element): bool
    {
        if (in_array($context->trigger, [Triggers::ENTRY_PUBLISHED, Triggers::ENTRY_UPDATED], true)) {
            return $element instanceof Entry && $element->getStatus() === Entry::STATUS_LIVE;
        }

        return true;
    }

    private function element(TriggerContext $context): ?ElementInterface
    {
        $type = $context->elementType;

        if ($type === null || $context->elementId === null || ! is_subclass_of($type, ElementInterface::class)) {
            return null;
        }

        $query = $type::find()->id($context->elementId)->status(null)->siteId($context->siteId ?? Sites::getPrimarySite()->id);

        if ($context->trigger === Triggers::ENTRY_DELETED) {
            $query->trashed(null);
        }

        $element = $query->one();

        return $element instanceof ElementInterface ? $element : null;
    }

    private function site(?ElementInterface $element, TriggerContext $context): Site
    {
        $id = $element !== null ? $element->siteId : $context->siteId;

        return ($id !== null ? Sites::getSiteById((int) $id, true) : null) ?? Sites::getPrimarySite();
    }

    /** How a destination appears in the delivery log: never the whole address or URL. */
    public static function mask(string $channel, string $to): string
    {
        if (SafeHttp::isEnvReference($to)) {
            return $to;
        }

        if ($channel === Channels::MAIL && str_contains($to, '@')) {
            [$local, $domain] = explode('@', $to, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        if ($channel === Channels::SMS || preg_match('/^\+\d+$/', $to)) {
            return '***'.substr($to, -3);
        }

        $host = parse_url($to, PHP_URL_HOST);

        return is_string($host) ? $host : '***';
    }
}
