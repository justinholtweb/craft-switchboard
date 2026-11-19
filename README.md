# Switchboard

Route Craft events to Laravel notification channels — the control panel bell, email, Slack, Discord, Microsoft
Teams, signed webhooks and SMS — with rules you build in the control panel.

> [!WARNING]
> **Craft 6 only, and in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is still in beta,
> and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites first and **use caution on
> production**: keep backups, and expect changes between betas. It does not run on Craft 5.

Docs: [justinholt.com/plugins/craft-switchboard](https://justinholt.com/plugins/craft-switchboard/docs/). See [PLAN.md](PLAN.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later (native Craft 6 plugin, no Yii 2 adapter)
- PHP 8.5
- A queue worker (`php artisan queue:work`) and Laravel's scheduler — every delivery is queued
- Optional: `laravel/vonage-notification-channel` or `laravel-notification-channels/twilio` for SMS

Switchboard is free and uses the [Craft License](LICENSE.md).

## What it does

Make a rule in **Switchboard → Rules**: *when* something happens, *who* hears about it, *how*, and *what it says*.

| When | |
|---|---|
| An entry is published | including entries whose post date passes (`craft:switchboard:scan`, every five minutes) |
| A live entry is updated | not on propagation or bulk resaves |
| An entry is deleted | |
| A user account is created / activated | |
| An asset is uploaded | |
| A queued job fails | after its last retry; Switchboard's own jobs are ignored |
| Custom | `app(Switchboard::class)->fire('order-shipped', $element, $data)` |

Narrow a rule to **sites**, **sources** (sections, volumes, user groups) and **conditions** (`title contains urgent`,
`summary notEmpty`, …). Notify **users**, **groups**, **admins**, the entry's **authors** or the user the event is
about, through the **bell** (Craft's control panel notifications, plus a front-end bell), **email** or **broadcast**;
admins can add **ad-hoc destinations**: email addresses, Slack / Discord / Teams webhooks, signed JSON webhooks and
phone numbers.

## Built on Craft 6 and Laravel

- Every delivery is a Laravel notification (`ShouldQueue`, three tries with backoff) logged in **Switchboard →
  Deliveries**; failures are counted on the sidebar badge.
- Bell notifications use Craft 6's own control panel notification shape (`CpNotification`), so they appear in the
  CP bell next to Craft's, and in your front-end bell.
- Users get messages in **their preferred language** (Craft's user preference, via Laravel's `HasLocalePreference`),
  else the site's. Default messages are translated (`lang/en`, `lang/fr`); your own use `|t` and site translations.
- Rule processing is queued *after the save commits*, so a rolled-back save never notifies anyone.

## Permissions and privacy

- Craft users are only told about elements **they can view**, on sites they can access. Failed-job notifications go
  to admins only. Suspended, pending and locked users are skipped (and the skip is logged).
- Messages are rendered **per recipient** from a snapshot of the element: a field behind a user condition (on the field
  or its tab) never reaches someone who couldn't see it in the element editor, nor does a related element they can't
  view. Ad-hoc destinations are anonymous viewers: no conditional fields, no email addresses, no CP links.
- Conditions only read what anyone could see, so a rule can't be used to learn hidden values.
- Rule subjects and bodies are **sandboxed Twig**. Body templates (Twig or Blade files in `templates/_switchboard/`)
  and ad-hoc destinations are **admin-only**; so is changing a rule that has destinations. People with “Manage
  notification rules” build rules on sources they can view.

## Webhooks are SSRF-safe

Webhook, Slack, Discord and Teams requests go to **public HTTPS addresses on port 443** only: the host is resolved
once, every address must be globally routable (no loopback, private, link-local, CGNAT, multicast, reserved or
IPv4-mapped/NAT64 tricks), the request is pinned to the checked address (no DNS rebinding), redirects aren't followed,
and timeouts are short. Slack, Discord and Teams URLs must be on their services' hosts. A `host:port` in the
`allowedHosts` config setting lifts this for a receiver you run in development.

Webhook bodies are signed: `Switchboard-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">` with the
rule's secret (shown to admins on the rule). Destinations are encrypted in the database, may be `$SWITCHBOARD_*`
environment variables, never sit in the queue's job payloads, and are masked in the delivery log.

## Front-end bell (Twig and Blade)

```twig
{% if currentUser %}
  <span>{{ switchboard.unreadCount() }}</span>
  {% for n in switchboard.notifications(10) %}
    <a href="{{ n.url }}" class="{{ n.read ? '' : 'unread' }}">{{ n.title }}</a>
  {% endfor %}
  <form method="post" action="{{ switchboard.markReadUrl() }}">{{ csrfInput() }}<input type="hidden" name="all" value="1"><button>Mark all read</button></form>
{% endif %}
```

```blade
@foreach ($switchboard->notifications(10) as $n)
  <a href="{{ $n['url'] }}">{{ $n['title'] }}</a>
@endforeach
```

`switchboard.feedUrl()` returns the same as JSON for a bell that polls. Only the signed-in user's Switchboard
notifications are ever listed or marked read.

## GraphQL

`switchboardNotifications(limit, unreadOnly)` and `switchboardUnreadCount`, for requests made with a **user's own**
access token (OAuth or a personal access token), when the schema includes *Switchboard → Read your own
notifications*. Shared GraphQL tokens have no user, so they never see these queries.

## Console

```bash
php artisan craft:switchboard:rules                      # rules and their last-24-hour counts
php artisan craft:switchboard:test <rule> --user=<username> [--element=<id>] [--destinations]
php artisan craft:switchboard:scan [--since=]            # scheduled every five minutes
php artisan craft:switchboard:prune [--days=]            # scheduled daily
```

## Configuration

`config/craft/switchboard.php` overrides the settings page:

```php
return [
    'queue' => 'notifications',        // default: Craft's queue
    'webhookTimeout' => 5,             // seconds
    'maxRecipients' => 500,            // per rule, per event
    'logRetentionDays' => 30,
    'allowedHosts' => [],              // e.g. ['127.0.0.1:8080'] for a local receiver — never in production
];
```

## Extending: any Laravel notification channel

```php
use JustinHoltWeb\Switchboard\Channels\ChannelType;
use JustinHoltWeb\Switchboard\Events\ChannelTypesResolving;
use JustinHoltWeb\Switchboard\Notifications\Message;

Event::listen(ChannelTypesResolving::class, function (ChannelTypesResolving $e) {
    $e->channels['telegram'] = new ChannelType(
        key: 'telegram', label: 'Telegram', forUsers: false, forRoutes: true,
        via: \NotificationChannels\Telegram\TelegramChannel::class,
        message: fn (Message $m) => \NotificationChannels\Telegram\TelegramMessage::create($m->subject."\n".$m->body),
    );
});
```

## Development

```bash
composer install
composer test        # Pest
composer phpstan     # Larastan level 6
composer check-cs    # Pint
tests/Harness/check.sh   # live checks in the Craft 6 harness (~/Sites/plugin-testing-craft6)
```
