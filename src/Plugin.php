<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Override;

use function CraftCms\Cms\t;

/**
 * Switchboard — Route Craft events to any Laravel notification channel — Slack, SMS, Discord, Teams, mail — with rules you build in the CP.
 *
 * See PLAN.md for the build phases.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public bool $hasCpSettings = true;

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
                ->instructions(t('Delivery attempts older than this are pruned.', category: 'switchboard')),
        ]);
    }
}
