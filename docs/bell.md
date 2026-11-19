---
title: Notification bell
slug: bell
order: 70
summary: The control panel bell, a front-end bell in Twig or Blade, the JSON feed and marking notifications read.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

Bell notifications are stored by Laravel's database channel in Craft 6's control panel notification format, so
they appear in the **control panel bell** with Craft's own. For your site, the `switchboard` template global works
in Twig and Blade, always for the **signed-in user**, and lists **Switchboard's notifications only**.

| Twig | Blade | |
|---|---|---|
| `switchboard.unreadCount()` | `$switchboard->unreadCount()` | unread count |
| `switchboard.notifications(10, false)` | `$switchboard->notifications(10, false)` | newest first: `id`, `title`, `message`, `url`, `date`, `read`; second argument: unread only; at most 100 |
| `switchboard.markReadUrl()` | `$switchboard->markReadUrl()` | POST `ids[]` (or `all=1`) with a CSRF token |
| `switchboard.feedUrl()` | `$switchboard->feedUrl()` | GET: `{"unread": 3, "notifications": […]}` |

```twig
{% if currentUser %}
  <details class="bell">
    <summary>🔔 {{ switchboard.unreadCount() }}</summary>
    <ul>{% for n in switchboard.notifications(10) %}
      <li class="{{ n.read ? 'read' : 'unread' }}"><a href="{{ n.url }}">{{ n.title }}</a><br>{{ n.message }}</li>
    {% endfor %}</ul>
    <form method="post" action="{{ switchboard.markReadUrl() }}">
      {{ csrfInput() }}<input type="hidden" name="all" value="1"><button>Mark all as read</button>
    </form>
  </details>
{% endif %}
```

```blade
@auth
  <span>{{ $switchboard->unreadCount() }}</span>
  @foreach ($switchboard->notifications(10) as $n)
    <a href="{{ $n['url'] }}" @class(['unread' => ! $n['read']])>{{ $n['title'] }}</a>
  @endforeach
@endauth
```

The feed and mark-read endpoints answer **401** to signed-out visitors and only touch the signed-in user's own
notifications; an ID that isn't theirs is ignored. Send `Accept: application/json` to mark-read to get
`{"marked": 1, "unread": 2}` back instead of a redirect.
