<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Dispatch;

use Illuminate\Support\Facades\DB;

/**
 * Remembers one-off events (an entry going live with a given post date) so the
 * same publication never notifies twice — not from a save and the scheduled scan,
 * not from two propagated saves.
 */
class Ledger
{
    /** Records the fingerprint; true the first time only. */
    public function claim(string $fingerprint): bool
    {
        return DB::table('switchboard_ledger')->insertOrIgnore([
            'fingerprint' => mb_substr($fingerprint, 0, 191),
            'created_at' => now(),
        ]) > 0;
    }

    public function has(string $fingerprint): bool
    {
        return DB::table('switchboard_ledger')->where('fingerprint', mb_substr($fingerprint, 0, 191))->exists();
    }

    public static function published(int $elementId, int $siteId, ?\DateTimeInterface $postDate): string
    {
        return 'entry.published:'.$elementId.':'.$siteId.':'.($postDate?->getTimestamp() ?? 0);
    }
}
