<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Models\BaseModel;

/**
 * Saved choices of the guided sign up, so they can be adjusted later
 *
 * @property int $user_id
 * @property int[] $angel_types
 * @property string[] $days Y-m-d
 */
class SignupChoice extends BaseModel
{
    protected $table = 'startseite_signup_choices'; // phpcs:ignore

    protected $primaryKey = 'user_id'; // phpcs:ignore

    public $incrementing = false; // phpcs:ignore

    public $timestamps = true; // phpcs:ignore

    /** @var array<string, mixed> */
    protected $attributes = [ // phpcs:ignore
        'angel_types' => '[]',
        'days' => '[]',
    ];

    /** @var array<string> */
    protected $fillable = ['user_id', 'angel_types', 'days']; // phpcs:ignore

    /** @var array<string, string> */
    protected $casts = [ // phpcs:ignore
        'angel_types' => 'array',
        'days' => 'array',
    ];
}
