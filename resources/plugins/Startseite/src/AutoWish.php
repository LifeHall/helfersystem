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
 * @property array[] $windows [Y-m-d => ['from' => int, 'to' => int]] in minutes since midnight
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
     * The slot is one of the wished helper types and lies completely in the time window of its day
     */
    public function matches(array $slot): bool
    {
        $window = $this->windows[$slot['day']] ?? null;

        return $window
            && in_array($slot['type']->id, $this->angel_types)
            && $slot['from'] >= $window['from']
            && $slot['to'] <= $window['to'];
    }
}
