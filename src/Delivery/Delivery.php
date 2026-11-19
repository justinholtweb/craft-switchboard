<?php

declare(strict_types=1);

namespace JustinHoltWeb\Switchboard\Delivery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use JustinHoltWeb\Switchboard\Rules\Rule;

/**
 * One delivery attempt record: a rule, a recipient, a channel.
 *
 * @property int $id
 * @property int|null $ruleId
 * @property string $trigger
 * @property int|null $elementId
 * @property int|null $siteId
 * @property string $channel
 * @property string $recipient
 * @property int|null $userId
 * @property string $status
 * @property string|null $subject
 * @property string|null $error
 * @property int $attempts
 * @property bool $test
 * @property Carbon|null $sentAt
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Delivery extends Model
{
    public const string QUEUED = 'queued';

    public const string SENDING = 'sending';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    public const array STATUSES = [self::QUEUED, self::SENDING, self::SENT, self::FAILED, self::SKIPPED];

    protected $table = 'switchboard_deliveries';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['test' => 'boolean', 'sentAt' => 'datetime', 'attempts' => 'integer'];
    }

    /** @return BelongsTo<Rule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'ruleId');
    }
}
