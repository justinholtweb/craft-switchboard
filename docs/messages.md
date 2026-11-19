---
title: Messages and languages
slug: messages
order: 40
summary: Sandboxed Twig subjects and bodies, Twig or Blade body templates, variables, and per-recipient languages.
---

> **Craft 6 only, and still in beta.** Switchboard is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Switchboard (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

Each recipient gets a message rendered just for them: in their language, from what they're allowed to see.

## Subject and body

Typed on the rule, in **sandboxed Twig** (no `craft.*` queries, no includes, no PHP). Plain text: HTML tags are
stripped. Leave them blank for a translated default (“Published: {title}”, “« {title} » a été publié sur {site}.”).

```twig
{{ element.title }} is live on {{ site.name }}{% if element.fields.summary ?? false %}: {{ element.fields.summary }}{% endif %}
```

## Variables

| Variable | What it holds |
|---|---|
| `element` | the element, as this recipient may see it: `id`, `kind`, `title`, `status`, `url`, `cpEditUrl` (users with CP access), `site`, `dateCreated`, `dateUpdated`; entries add `section`, `entryType`, `postDate`, `author`; assets `volume`, `filename`; users `username` and `email` (only for people who may view that user); and `fields` |
| `element.fields` | the custom fields you list under **Fields to include**, left out for anyone who couldn't see them in the element editor; related elements as `{id, title}`, only those the recipient can view |
| `event` | `key` and `label` of the trigger |
| `rule` | `name`, `handle` |
| `site` | `handle`, `name`, `language` |
| `recipient` | `name`, `friendlyName` (null for ad-hoc destinations) |
| `data` | a custom trigger's data, or a failed job's `job`, `queue`, `connection`, `exception` (and `message`, for admins only) |

## Body templates (Twig or Blade)

Admins can point a rule at a file in your site templates' **`_switchboard/`** folder — `_switchboard/published.twig`
or `_switchboard/published.blade.php` — instead of typing a body. Craft picks the engine from the extension. Templates
get the same variables, plus `object`: the element itself, unfiltered, so only show what every recipient may see.

```blade
{{ $element['title'] }} — {{ \CraftCms\Cms\t('Read it here', category: 'site') }}: {{ $element['url'] }}
```

## Languages

- A **Craft user** gets the message in their **preferred language** (their account preference), else the site's.
- An **ad-hoc destination** gets the language of the site the event happened on.

Rendering runs in that language, so `{{ 'Read more'|t }}` uses your site translations (`translations/fr/site.php`),
and Switchboard's defaults come from `lang/<language>/switchboard.php` (English and French ship with it).
