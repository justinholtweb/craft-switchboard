<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Http\Controllers;

use CraftCms\Cms\Cp\Data\ActionItem;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Ui\Nodes\Table;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule as ValidationRule;
use JustinHoltWeb\Switchboard\Channels\Channels;
use JustinHoltWeb\Switchboard\Channels\ChannelType;
use JustinHoltWeb\Switchboard\Delivery\Delivery;
use JustinHoltWeb\Switchboard\Dispatch\Conditions;
use JustinHoltWeb\Switchboard\Dispatch\Dispatcher;
use JustinHoltWeb\Switchboard\Dispatch\Tester;
use JustinHoltWeb\Switchboard\Rules\Rule;
use JustinHoltWeb\Switchboard\Rules\Rules;
use JustinHoltWeb\Switchboard\Triggers\Triggers;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\t;

/**
 * Switchboard → Rules: the list, the edit screen, save / delete / test.
 * Every route needs `switchboard:manageRules` (routes/cp.php). Ad-hoc
 * destinations are admin-only: they reach people Craft can't permission-check.
 */
class RulesController
{
    use RespondsWithFlash;

    public function __construct(private readonly Rules $rules, private readonly Channels $channels) {}

    public function index(): CpScreenResponse
    {
        $since = now()->subDay();
        $table = Table::make('switchboard-rules')
            ->columns([
                ['key' => 'name', 'label' => t('Name', category: 'switchboard')],
                ['key' => 'handle', 'label' => t('Handle', category: 'switchboard')],
                ['key' => 'trigger', 'label' => t('When', category: 'switchboard')],
                ['key' => 'channels', 'label' => t('Channels', category: 'switchboard')],
                ['key' => 'enabled', 'label' => t('Enabled', category: 'switchboard')],
                ['key' => 'recent', 'label' => t('Last 24 hours', category: 'switchboard')],
            ])
            ->rows($this->rules->all()->map(fn (Rule $rule): array => [
                'id' => $rule->id,
                'name' => ['label' => $rule->name, 'url' => Url::cpUrl('switchboard/rules/'.$rule->id)],
                'handle' => ['html' => Html::tag('code', Html::encode($rule->handle))],
                'trigger' => Triggers::label($rule->trigger),
                'channels' => implode(', ', array_unique([...$rule->userChannels(), ...array_column($rule->routeList(), 'channel')])) ?: '—',
                'enabled' => $rule->enabled ? t('Yes', category: 'switchboard') : t('No', category: 'switchboard'),
                'recent' => t('{sent} sent, {failed} failed', [
                    'sent' => Delivery::query()->where('ruleId', $rule->id)->where('status', Delivery::SENT)->where('created_at', '>=', $since)->count(),
                    'failed' => Delivery::query()->where('ruleId', $rule->id)->where('status', Delivery::FAILED)->where('created_at', '>=', $since)->count(),
                ], category: 'switchboard'),
            ])->values()->all())
            ->emptyMessage(t('No rules yet.', category: 'switchboard'))
            ->createAction(t('New rule', category: 'switchboard'), Url::cpUrl('switchboard/rules/new'))
            ->createActionInPageHeader();

        return new CpScreenResponse()
            ->title(t('Notification rules', category: 'switchboard'))
            ->ui(Ui::make([$table]));
    }

    public function create(Request $request): CpScreenResponse
    {
        return $this->screen($request, new Rule(['enabled' => true, 'trigger' => Triggers::ENTRY_PUBLISHED, 'channels' => [Channels::BELL]]));
    }

    public function edit(Request $request, int $id): CpScreenResponse
    {
        return $this->screen($request, $this->rules->get($id) ?? abort(404));
    }

    public function save(Request $request): Response
    {
        $id = (int) $request->input('id', 0);
        $rule = $id > 0 ? ($this->rules->get($id) ?? abort(404)) : new Rule;
        $isAdmin = (bool) currentUserElement()?->admin;

        // A rule that posts to ad-hoc destinations (webhooks, Slack, …) decides what leaves
        // the site, so only admins may change anything about it.
        abort_if(! $isAdmin && $rule->routeList() !== [], 403, 'Only admins can change rules with ad-hoc destinations.');
        $trigger = (string) $request->input('trigger', '');
        if ($trigger === 'custom') {
            $trigger = 'custom:'.trim((string) $request->input('customTrigger', ''));
        }
        $request->merge(['trigger' => $trigger]);
        $kind = Triggers::sourceKind($trigger);
        $sources = $kind === null ? [] : array_values(array_unique(array_map('strval', (array) $request->input('sources.'.$kind, []))));
        $userChannels = array_keys($this->channels->forUsers());
        $routeChannels = array_keys($this->channels->forRoutes());

        $validator = Validator::make(['sourceList' => $sources] + $request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'handle' => ['required', 'string', 'regex:'.Rule::HANDLE_PATTERN, function (string $a, mixed $v, \Closure $fail) use ($rule) {
                $other = $this->rules->getByHandle((string) $v);
                if ($other !== null && $other->id !== $rule->id) {
                    $fail(t('Another rule already uses this handle.', category: 'switchboard'));
                }
            }],
            'trigger' => ['required', 'string', 'max:96', function (string $a, mixed $v, \Closure $fail) {
                if (! Triggers::isValid((string) $v)) {
                    $fail(t('Choose what the rule listens for. Custom triggers look like `custom:order-shipped`.', category: 'switchboard'));
                }
            }],
            'sites' => ['nullable', 'array'],
            'sites.*' => ['integer', ValidationRule::in(Sites::getAllSiteIds()->all())],
            'sources' => ['nullable', 'array'],
            'sourceList' => ['array'],
            'sourceList.*' => ['string', ValidationRule::in(self::sourceUids($kind))],
            'conditions' => ['nullable', 'array', 'max:20'],
            'conditions.*.attribute' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'conditions.*.operator' => ['nullable', 'string', ValidationRule::in(Conditions::OPERATORS)],
            'conditions.*.value' => ['nullable', 'string', 'max:255'],
            'users' => ['nullable', 'string', 'max:5000'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['string', ValidationRule::in(array_map(fn ($g) => (string) $g->uid, UserGroups::getAllGroups()->all()))],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', ValidationRule::in($userChannels)],
            'routes' => ['nullable', 'array', 'max:20'],
            'routes.*.channel' => ['nullable', 'string', ValidationRule::in($routeChannels)],
            'routes.*.to' => ['nullable', 'string', 'max:2048'],
            'subject' => ['nullable', 'string', 'max:1000'],
            'body' => ['nullable', 'string', 'max:20000'],
            'template' => ['nullable', 'string', 'max:255', 'regex:'.Rule::TEMPLATE_PATTERN],
            'payloadFields' => ['nullable', 'string', 'max:1000', 'regex:/^[A-Za-z0-9_,\s]*$/'],
        ], [], [
            'name' => t('Name', category: 'switchboard'),
            'handle' => t('Handle', category: 'switchboard'),
            'template' => t('Body template', category: 'switchboard'),
        ]);

        $users = [];
        $validator->after(function ($validator) use ($request, &$users, $isAdmin, $rule) {
            $me = currentUserElement();
            // Without “View users”, naming anyone but yourself would reveal which accounts exist,
            // so it fails the same way whether or not the person exists.
            $canLookUp = $isAdmin || (bool) $me?->can('viewUsers');
            $existing = $rule->userIds();
            foreach (self::lines((string) $request->input('users', '')) as $who) {
                $user = ctype_digit($who) ? User::find()->id((int) $who)->status(null)->one()
                    : (User::find()->username($who)->status(null)->one() ?? User::find()->email($who)->status(null)->one());
                if (! $canLookUp && ($user === null || ($user->id !== $me?->id && ! in_array((int) $user->id, $existing, true)))) {
                    $validator->errors()->add('users', t('Without the “View users” permission you can only add yourself.', category: 'switchboard'));

                    continue;
                }
                if (! $user instanceof User) {
                    $validator->errors()->add('users', t('No user matches “{who}”.', ['who' => $who], category: 'switchboard'));
                } else {
                    $users[] = (int) $user->id;
                }
            }

            if (! $isAdmin && trim((string) $request->input('template', '')) !== (string) $rule->template) {
                $validator->errors()->add('template', t('Only admins can choose a body template.', category: 'switchboard'));
            }

            if (! $isAdmin) {
                $this->checkSourcesForNonAdmin($validator, (string) $request->input('trigger'), (array) $validator->getData()['sourceList']);
            }

            $routes = self::submittedRoutes($request);
            if ($routes !== [] && ! $isAdmin) {
                $validator->errors()->add('routes', t('Only admins can change ad-hoc destinations.', category: 'switchboard'));
            }
            foreach ($routes as $route) {
                $error = $this->channels->get($route['channel'])?->validateRoute($route['to']);
                if ($error !== null) {
                    $validator->errors()->add('routes', $error);
                }
            }
        });

        if ($validator->fails()) {
            return $this->asFailure(t('Couldn’t save the rule.', category: 'switchboard'), ['errors' => $validator->errors()->toArray()]);
        }

        $siteIds = array_values(array_map('intval', (array) $request->input('sites', [])));
        $rule->fill([
            'name' => trim((string) $request->input('name')),
            'handle' => (string) $request->input('handle'),
            'enabled' => $request->boolean('enabled'),
            'trigger' => $trigger,
            'siteIds' => $siteIds === [] || count($siteIds) === Sites::getAllSiteIds()->count() ? null : $siteIds,
            'sources' => $sources ?: null,
            'conditions' => self::submittedConditions($request) ?: null,
            'recipients' => [
                'users' => array_values(array_unique($users)),
                'groups' => array_values(array_unique(array_map('strval', (array) $request->input('groups', [])))),
                'admins' => $request->boolean('admins'),
                'authors' => $request->boolean('authors'),
                'subject' => $request->boolean('subjectUser'),
            ],
            'channels' => array_values(array_unique(array_map('strval', (array) $request->input('channels', [])))),
            'subject' => trim((string) $request->input('subject')) ?: null,
            'body' => trim((string) $request->input('body')) ?: null,
            'template' => trim((string) $request->input('template')) ?: null,
            'payloadFields' => self::handles((string) $request->input('payloadFields', '')) ?: null,
        ]);

        if ($isAdmin) {
            $rule->routes = self::submittedRoutes($request);
        }

        if ($isAdmin && $request->boolean('rotateSecret')) {
            $rule->signingSecret = Rule::newSecret();
        }

        $rule->save();
        $this->rules->forget();

        return $this->asSuccess(t('Rule saved.', category: 'switchboard'), redirect: Url::cpUrl('switchboard/rules/'.$rule->id));
    }

    public function delete(Request $request): Response
    {
        $rule = $this->rules->get((int) $request->input('id')) ?? abort(404);
        abort_if(! currentUserElement()?->admin && $rule->routeList() !== [], 403, 'Only admins can delete rules with ad-hoc destinations.');
        $rule->delete();
        $this->rules->forget();

        return $this->asSuccess(t('Rule deleted.', category: 'switchboard'), redirect: Url::cpUrl('switchboard'));
    }

    /** Sends the rule's message to the signed-in user (and, for admins who ask, its destinations). */
    public function test(Request $request, Tester $tester): Response
    {
        $rule = $this->rules->get((int) $request->input('id')) ?? abort(404);
        $user = currentUserElement() ?? abort(403);
        $elementId = $request->filled('elementId') ? (int) $request->input('elementId') : null;
        $result = $tester->send($rule, $user, $elementId, $request->boolean('destinations') && $user->admin);

        if (isset($result['error'])) {
            return $this->asFailure($result['error']);
        }

        // The same answer whatever happened, so a test can't be used to probe elements.
        return $this->asSuccess(
            t('Test sent. See Deliveries for what went where.', category: 'switchboard'),
            redirect: Url::cpUrl('switchboard/rules/'.$rule->id),
        );
    }

    private function screen(Request $request, Rule $rule): CpScreenResponse
    {
        $isNew = ! $rule->exists;
        $title = $isNew ? t('New rule', category: 'switchboard') : $rule->name;
        $isAdmin = (bool) currentUserElement()?->admin;
        $userNames = [];

        foreach ($rule->userIds() === [] ? [] : User::find()->id($rule->userIds())->status(null)->all() as $user) {
            $userNames[] = (string) $user->username;
        }

        return new CpScreenResponse()
            ->title($title)
            ->crumbs([
                new ActionItem()->label(t('Notification rules', category: 'switchboard'))->href(Url::cpUrl('switchboard')),
                new ActionItem()->label($title),
            ])
            ->contentTemplate('switchboard/_edit', [
                'rule' => $rule,
                'isNew' => $isNew,
                'isAdmin' => $isAdmin,
                'triggers' => Triggers::all(),
                'sites' => Sites::getAllSites(true)->values()->all(),
                'sources' => [
                    'sections' => self::options('sections'),
                    'volumes' => self::options('volumes'),
                    'groups' => self::options('groups'),
                ],
                'groups' => UserGroups::getAllGroups()->all(),
                'userChannels' => array_map(fn (ChannelType $t) => $t->label, $this->channels->forUsers()),
                'routeChannels' => array_map(fn (ChannelType $t) => ['label' => $t->label, 'hint' => $t->routeHint], $this->channels->forRoutes()),
                'operators' => Conditions::OPERATORS,
                'form' => $this->formValues($request, $rule, $userNames),
                'errors' => array_map(fn (array $m) => implode(' ', $m), $this->sessionErrors($request)),
                'secret' => $isAdmin && ! $isNew ? $rule->signingSecret : null,
            ]);
    }

    /**
     * @param  list<string>  $userNames
     * @return array<string, mixed>
     */
    private function formValues(Request $request, Rule $rule, array $userNames): array
    {
        $values = [
            'id' => $rule->id,
            'name' => $rule->name,
            'handle' => $rule->handle,
            'enabled' => $rule->enabled,
            'trigger' => $rule->trigger,
            'sites' => (array) $rule->siteIds,
            'sources' => (array) $rule->sources,
            'conditions' => (array) $rule->conditions,
            'users' => implode("\n", $userNames),
            'groups' => $rule->groupUids(),
            'admins' => $rule->wants('admins'),
            'authors' => $rule->wants('authors'),
            'subjectUser' => $rule->wants('subject'),
            'channels' => $rule->userChannels(),
            'routes' => currentUserElement()?->admin ? $rule->routeList() : array_map(fn (array $r) => ['channel' => $r['channel'], 'to' => Dispatcher::mask($r['channel'], $r['to'])], $rule->routeList()),
            'subject' => (string) $rule->subject,
            'body' => (string) $rule->body,
            'template' => (string) $rule->template,
            'payloadFields' => implode(', ', (array) $rule->payloadFields),
        ];

        $old = $request->hasSession() ? (array) $request->session()->getOldInput() : [];

        if ($old === []) {
            return $values;
        }

        foreach (['name', 'handle', 'trigger', 'users', 'subject', 'body', 'template', 'payloadFields'] as $key) {
            $values[$key] = is_scalar($old[$key] ?? null) ? (string) $old[$key] : $values[$key];
        }
        foreach (['sites', 'sources', 'groups', 'channels', 'conditions', 'routes'] as $key) {
            $values[$key] = is_array($old[$key] ?? null) ? array_values($old[$key]) : [];
        }
        $values['sources'] = array_values(array_merge(...array_map(fn ($v) => (array) $v, $values['sources'])));
        if (Triggers::isCustom((string) ($old['trigger'] ?? ''))) {
            $values['trigger'] = (string) $old['trigger'];
        }
        foreach (['enabled', 'admins', 'authors', 'subjectUser'] as $key) {
            $values[$key] = ! empty($old[$key]);
        }

        return $values;
    }

    /** @return array<string, list<string>> */
    private function sessionErrors(Request $request): array
    {
        $bag = $request->hasSession() ? $request->session()->get('errors') : null;

        return $bag instanceof ViewErrorBag ? $bag->getBag('default')->toArray() : [];
    }

    /**
     * Non-admins may only build rules about things they can see themselves: entry rules
     * on sections they can view, asset rules on volumes they can view, user rules if they
     * can view users — always naming the sources. Failed-job and custom triggers are
     * admin-only. So a rule can't tell its editor anything about content they can't see
     * (through conditions, the delivery log or a test).
     *
     * @param  array<array-key, mixed>  $sources
     */
    private function checkSourcesForNonAdmin(\Illuminate\Validation\Validator $validator, string $trigger, array $sources): void
    {
        $user = currentUserElement() ?? abort(403);
        $kind = Triggers::sourceKind($trigger);

        if ($kind === null) {
            $validator->errors()->add('trigger', t('Only admins can create rules for this trigger.', category: 'switchboard'));

            return;
        }

        if ($sources === []) {
            $validator->errors()->add('sources', t('Choose at least one source you can view.', category: 'switchboard'));

            return;
        }

        foreach ($sources as $uid) {
            $allowed = match ($kind) {
                'sections' => $user->can('viewEntries:'.$uid),
                'volumes' => $user->can('viewAssets:'.$uid),
                default => $user->can('viewUsers'),
            };

            if (! $allowed) {
                $validator->errors()->add('sources', t('You can only choose sources you can view.', category: 'switchboard'));

                return;
            }
        }
    }

    /** @return list<array{channel: string, to: string}> */
    private static function submittedRoutes(Request $request): array
    {
        $routes = [];

        foreach ((array) $request->input('routes', []) as $row) {
            $channel = trim((string) ($row['channel'] ?? ''));
            $to = trim((string) ($row['to'] ?? ''));

            if ($channel !== '' && $to !== '') {
                $routes[] = ['channel' => $channel, 'to' => $to];
            }
        }

        return $routes;
    }

    /** @return list<array{attribute: string, operator: string, value: string}> */
    private static function submittedConditions(Request $request): array
    {
        $conditions = [];

        foreach ((array) $request->input('conditions', []) as $row) {
            $attribute = trim((string) ($row['attribute'] ?? ''));
            $operator = (string) ($row['operator'] ?? '');

            if ($attribute !== '' && in_array($operator, Conditions::OPERATORS, true)) {
                $conditions[] = ['attribute' => $attribute, 'operator' => $operator, 'value' => trim((string) ($row['value'] ?? ''))];
            }
        }

        return $conditions;
    }

    /** @return list<string> */
    private static function sourceUids(?string $kind): array
    {
        return array_keys(self::options($kind));
    }

    /** @return array<string, string> uid → name */
    private static function options(?string $kind): array
    {
        $items = match ($kind) {
            'sections' => Sections::getAllSections()->all(),
            'volumes' => Volumes::getAllVolumes()->all(),
            'groups' => UserGroups::getAllGroups()->all(),
            default => [],
        };
        $options = [];

        foreach ($items as $item) {
            $options[(string) $item->uid] = (string) $item->name;
        }

        return $options;
    }

    /** @return list<string> */
    private static function lines(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\n,]+/', $value) ?: []), fn (string $l) => $l !== '')));
    }

    /** @return list<string> */
    private static function handles(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $value) ?: []), fn (string $h) => $h !== '')));
    }
}
