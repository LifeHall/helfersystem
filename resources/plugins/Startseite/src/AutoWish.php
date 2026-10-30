<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Models\BaseModel;
use Engelsystem\Models\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wish of a helper for the automatic assignment
 *
 * @property int $user_id
 * @property int[] $angel_types
 * @property array[] $windows [Y-m-d => string[]] chosen shift times per day, see timeKey(),
 *                            before version 1.10.1 a time window [Y-m-d => ['from' => int, 'to' => int]]
 * @property int $count Wanted number of shifts
 * @property-read User $user
 */
class AutoWish extends BaseModel
{
    protected $table = 'startseite_auto_wishes'; // phpcs:ignore

    protected $primaryKey = 'user_id'; // phpcs:ignore

    public $incrementing = false; // phpcs:ignore

    public $timestamps = true; // phpcs:ignore

    /** @var array<string, mixed> */
    protected $attributes = [ // phpcs:ignore
        'angel_types' => '[]',
        'windows' => '{}',
        'count' => 1,
    ];

    /** @var array<string> */
    protected $fillable = ['user_id', 'angel_types', 'windows', 'count']; // phpcs:ignore

    /** @var array<string, string> */
    protected $casts = [ // phpcs:ignore
        'angel_types' => 'array',
        'windows' => 'array',
        'count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The slot is one of the wished helper types at one of the chosen times of its day
     */
    public function matches(array $slot): bool
    {
        $times = $this->windows[$slot['day']] ?? [];
        if (!$times || !in_array($slot['type']->id, $this->angel_types)) {
            return false;
        }

        if (isset($times['from'])) {
            return $slot['from'] >= $times['from'] && $slot['to'] <= $times['to'];
        }

        return in_array(self::timeKey($slot), $times, true);
    }

    /**
     * Shift time of a slot: start and end in minutes since midnight
     */
    public static function timeKey(array $slot): string
    {
        return $slot['from'] . '-' . $slot['to'];
    }
}
