<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Models\BaseModel;

/**
 * Position of a pinned news on the start page
 *
 * @property int $news_id
 * @property int $position
 */
class NewsOrder extends BaseModel
{
    protected $table = 'startseite_news_order'; // phpcs:ignore

    protected $primaryKey = 'news_id'; // phpcs:ignore

    public $incrementing = false; // phpcs:ignore

    /** @var array<string> */
    protected $fillable = ['news_id', 'position']; // phpcs:ignore
}
