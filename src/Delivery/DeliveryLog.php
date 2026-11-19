<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Delivery;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\Switchboard\Notifications\SwitchboardNotification;
use Throwable;

/**
 * The delivery log. Rows are written when a delivery is queued and updated as
 * Laravel sends it (NotificationSending / NotificationSent) or gives up on it.
 * Errors are stored without URLs, so a webhook's secret path never lands here.
 */
class DeliveryLog
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Delivery
    {
        return Delivery::query()->create($attributes + ['status' => Delivery::QUEUED, 'attempts' => 0]);
    }

    public function sending(NotificationSending $event): void
    {
        if ($event->notification instanceof SwitchboardNotification && $event->notification->deliveryId !== null) {
            Delivery::query()->whereKey($event->notification->deliveryId)->update([
                'status' => Delivery::SENDING,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
        }
    }

    public function sent(NotificationSent $event): void
    {
        if ($event->notification instanceof SwitchboardNotification && $event->notification->deliveryId !== null) {
            Delivery::query()->whereKey($event->notification->deliveryId)->update([
                'status' => Delivery::SENT,
                'error' => null,
                'sentAt' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function failed(int $id, Throwable $e): void
    {
        Delivery::query()->whereKey($id)->update([
            'status' => Delivery::FAILED,
            'error' => self::describe($e),
            'updated_at' => now(),
        ]);
    }

    public static function describe(Throwable $e): string
    {
        $message = preg_replace('#[a-z][a-z0-9+.\-]*://\S+#i', '[url]', $e->getMessage()) ?? '';

        return mb_substr(class_basename($e).': '.$message, 0, 500);
    }

    /** Failed deliveries in the last day (the sidebar badge). */
    public function recentFailures(): int
    {
        return Delivery::query()->where('status', Delivery::FAILED)->where('updated_at', '>=', now()->subDay())->count();
    }

    public function prune(int $days): int
    {
        $before = now()->subDays(max(1, $days));
        $count = Delivery::query()->where('created_at', '<', $before)->delete();
        DB::table('switchboard_ledger')->where('created_at', '<', $before->copy()->subDays(30))->delete();

        return (int) $count;
    }

    /** @return array<string, string> */
    public function subscribe(): array
    {
        return [
            NotificationSending::class => 'sending',
            NotificationSent::class => 'sent',
        ];
    }
}
