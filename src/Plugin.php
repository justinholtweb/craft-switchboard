<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\Gql\GqlQueries;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Ui\Controls\IconPicker;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Data\Permission;
use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use JustinHoltWeb\Switchboard\Bell\Bell;
use JustinHoltWeb\Switchboard\Delivery\DeliveryLog;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Switchboard — route Craft events to Laravel notification channels (mail, the
 * control panel bell, Slack, Discord, Teams, signed webhooks, SMS) with rules
 * built in the control panel.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const string PERMISSION_MANAGE = 'switchboard:manageRules';

    public const string PERMISSION_DELIVERIES = 'switchboard:viewDeliveries';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    protected array $events = [
        GqlSchemaComponentsResolving::class => Listeners\RegisterGqlComponents::class,
        JobFailed::class => Listeners\WatchQueue::class,
    ];

    protected array $commands = [
        Commands\ScanCommand::class,
        Commands\PruneCommand::class,
        Commands\RulesCommand::class,
        Commands\TestCommand::class,
    ];

    public function boot(): void
    {
        $this->app->bind(Settings::class, fn (): Settings => $this->getSettings());
        $this->hasCpSection = $this->getSettings()->showInCpNav;
        $this->app->scoped(Rules\Rules::class);
        $this->app->scoped(Channels\Channels::class);
        $this->app->scoped(Listeners\WatchElements::class);
        Event::subscribe(Listeners\WatchElements::class);
        Event::subscribe(DeliveryLog::class);

        Event::listen(TemplateGlobalsResolving::class, function (TemplateGlobalsResolving $event): void {
            $event->globals['switchboard'] = app(Bell::class);
        });

        $this->app->make(GqlQueries::class)->register(Gql\NotificationsQuery::class);
    }

    #[Override]
    protected function getPermissions(): array
    {
        return [
            new Permission(self::PERMISSION_MANAGE, t('Manage notification rules', category: 'switchboard'), t('Create, edit, test and delete rules. Only admins can add ad-hoc destinations (email addresses, webhooks, Slack, phone numbers).', category: 'switchboard')),
            new Permission(self::PERMISSION_DELIVERIES, t('View the delivery log', category: 'switchboard')),
        ];
    }

    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        if (! Gate::check('accessPlugin-switchboard')) {
            return null;
        }

        $canManage = Gate::check(self::PERMISSION_MANAGE);
        $canLog = Gate::check(self::PERMISSION_DELIVERIES);

        if (! $canManage && ! $canLog) {
            return null;
        }

        $settings = $this->getSettings();
        $subnav = [];

        if ($canManage) {
            $subnav[] = new NavItem()->label(t('Rules', category: 'switchboard'))->href(Url::cpUrl('switchboard'));
        }

        $failures = $canLog ? rescue(fn () => app(DeliveryLog::class)->recentFailures(), 0, false) : 0;

        if ($canLog) {
            $deliveries = new NavItem()->label(t('Deliveries', category: 'switchboard'))->href(Url::cpUrl('switchboard/deliveries'));
            $subnav[] = $failures > 0 ? $deliveries->badgeCount($failures) : $deliveries;
        }

        $item = new NavItem()
            ->label($settings->navLabel() ?? $this->name)
            ->href($canManage ? 'switchboard' : 'switchboard/deliveries')
            ->icon($settings->navIcon() ?? $this->cpNavIconPath())
            ->subnav($subnav);

        return $failures > 0 ? $item->badgeCount($failures) : $item;
    }

    #[Override]
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('craft:switchboard:scan')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('craft:switchboard:prune')->daily();
    }

    #[Override]
    protected function afterUninstall(): void
    {
        // Bell notifications Switchboard wrote would otherwise linger in Craft's notifications table.
        try {
            DB::table('notifications')
                ->where('data', 'like', '%"kind":"'.Notifications\SwitchboardNotification::KIND.'"%')
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        return Ui::make([
            Field::make(t('Delivery log retention (days)', category: 'switchboard'), Number::make('logRetentionDays'))
                ->instructions(t('Delivery records older than this are pruned daily.', category: 'switchboard')),
            Field::make(t('Queue', category: 'switchboard'), Text::make('queue')->placeholder('default'))
                ->instructions(t('The queue Switchboard’s jobs go on. Leave blank for Craft’s queue.', category: 'switchboard')),
            Field::make(t('Webhook timeout (seconds)', category: 'switchboard'), Number::make('webhookTimeout'))
                ->instructions(t('How long a webhook, Slack, Discord or Teams request may take.', category: 'switchboard')),
            Field::make(t('Most recipients per event', category: 'switchboard'), Number::make('maxRecipients'))
                ->instructions(t('A safety limit on how many people one rule notifies about one event.', category: 'switchboard')),
            Field::make(t('Show in the sidebar', category: 'switchboard'), Lightswitch::make('showInCpNav')),
            Field::make(t('Sidebar label', category: 'switchboard'), Text::make('cpNavLabel')->placeholder('Switchboard'))
                ->instructions(t('What the sidebar link says. Leave blank for “Switchboard”.', category: 'switchboard')),
            Field::make(t('Sidebar icon', category: 'switchboard'), IconPicker::make('cpNavIcon')->sets('*'))
                ->instructions(t('One of Craft’s icons, or one from a custom icon set. Leave blank for Switchboard’s own icon.', category: 'switchboard')),
        ]);
    }
}
