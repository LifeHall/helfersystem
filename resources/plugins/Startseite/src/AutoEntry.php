<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Models\BaseModel;

/**
 * Marks a shift entry made by the automatic assignment, only these can be moved to another shift
 *
 * @property int $shift_entry_id
 * @property int $user_id
 */
class AutoEntry extends BaseModel
{
    protected $table = 'startseite_auto_entries'; // phpcs:ignore

    protected $primaryKey = 'shift_entry_id'; // phpcs:ignore

    public $incrementing = false; // phpcs:ignore

    /** @var array<string> */
    protected $fillable = ['shift_entry_id', 'user_id']; // phpcs:ignore
}
