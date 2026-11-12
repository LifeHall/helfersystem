<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Helpers\Carbon;
use Engelsystem\Models\BaseModel;
use Engelsystem\Models\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mail to many people, sent in batches of a few seconds each by the page of the job:
 * the hosting ends requests after about two minutes
 *
 * The position is saved after every mail, so an aborted job continues with the next person
 *
 * @property int $id
 * @property int|null $user_id Who started it
 * @property string $handler Class implementing MailJobHandler
 * @property string $privilege Needed to see and continue the job
 * @property string $title
 * @property array $payload
 * @property int[] $recipients User ids in sending order
 * @property int $position Number of recipients done
 * @property int $sent
 * @property string[] $failed Names of the recipients the mail failed for
 * @property Carbon|null $locked_until A batch is running
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 *
 * @property-read User|null $user
 */
class MailJob extends BaseModel
{
    // Time per batch, well below the limit of the hosting
    public const BATCH_SECONDS = 20;

    // A batch that did not finish (e.g. ended by the hosting) unlocks the job after this time
    protected const LOCK_SECONDS = 180;

    protected $table = 'startseite_mail_jobs'; // phpcs:ignore

    public $timestamps = true; // phpcs:ignore

    /** @var array<string, mixed> */
    protected $attributes = [ // phpcs:ignore
        'payload' => '[]',
        'recipients' => '[]',
        'failed' => '[]',
        'position' => 0,
        'sent' => 0,
    ];

    /** @var array<string> */
    protected $fillable = ['user_id', 'handler', 'privilege', 'title', 'payload', 'recipients']; // phpcs:ignore

    /** @var array<string, string> */
    protected $casts = [ // phpcs:ignore
        'payload' => 'array',
        'recipients' => 'array',
        'failed' => 'array',
        'locked_until' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * @param Builder $recipients Users to send to, sent in the order of their id
     */
    public static function start(string $handler, string $privilege, string $title, array $payload, Builder $recipients): self
    {
        $job = static::create([
            'user_id' => auth()->user()?->id,
            'handler' => $handler,
            'privilege' => $privilege,
            'title' => $title,
            'payload' => $payload,
            'recipients' => $recipients->orderBy('users.id')->pluck('users.id')->all(),
        ]);

        engelsystem_log(sprintf('Mail job #%d "%s": started for %d people', $job->id, $title, count($job->recipients)));

        return $job;
    }

    /**
     * Unfinished jobs the current user may continue
     *
     * @return Collection|self[]
     */
    public static function unfinished(): Collection
    {
        return static::whereNull('finished_at')
            ->orderBy('id')
            ->get()
            ->filter(fn(self $job) => auth()->can($job->privilege))
            ->values();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function total(): int
    {
        return count($this->recipients);
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    public function isRunning(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    /**
     * Sends to the next recipients for up to BATCH_SECONDS
     *
     * @return bool false: another batch is running
     */
    public function runBatch(): bool
    {
        // A lock left over: the last batch was ended (e.g. by the hosting) before it could log its progress
        $interrupted = static::whereKey($this->id)->whereNotNull('locked_until')->exists();

        // Only one batch at a time, e.g. if the page is open twice
        $locked = static::whereKey($this->id)
            ->whereNull('finished_at')
            ->where(fn($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', Carbon::now()))
            ->update(['locked_until' => Carbon::now()->addSeconds(self::LOCK_SECONDS)]);
        if (!$locked) {
            return false;
        }
        $this->refresh();

        if ($interrupted) {
            engelsystem_log(sprintf(
                'Mail job #%d "%s": interrupted, continued after %d of %d',
                $this->id,
                $this->title,
                $this->position,
                $this->total()
            ));
        }

        /** @var MailJobHandler $handler */
        $handler = app()->make($this->handler);
        $start = microtime(true);
        $from = $this->position;
        $failed = $this->failed;

        while ($this->position < $this->total() && microtime(true) - $start < self::BATCH_SECONDS) {
            $user = User::with(['contact', 'personalData', 'settings'])->find($this->recipients[$this->position]);
            // Deleted users are skipped
            if ($user) {
                if ($handler->sendTo($user, $this->payload)) {
                    $this->sent++;
                } else {
                    $failed[] = User_Nick_render($user, true);
                }
            }

            $this->position++;
            $this->failed = $failed;
            $this->save();
        }

        $this->locked_until = null;
        if ($this->position >= $this->total()) {
            $this->finished_at = Carbon::now();
        }
        $this->save();

        $this->log($from);

        return true;
    }

    /**
     * Progress after every batch, so an aborted job shows up in the log
     */
    protected function log(int $from): void
    {
        if (!$this->isFinished()) {
            engelsystem_log(sprintf(
                'Mail job #%d "%s": %d of %d done (%d failed)',
                $this->id,
                $this->title,
                $this->position,
                $this->total(),
                count($this->failed)
            ));
            return;
        }

        engelsystem_log(sprintf(
            'Mail job #%d "%s" finished: %d mails, %d failed%s',
            $this->id,
            $this->title,
            $this->sent,
            count($this->failed),
            $this->failed ? ': ' . implode(', ', $this->failed) : ''
        ));
    }

    /**
     * @return array Progress for the page of the job
     */
    public function progress(): array
    {
        return [
            'position' => $this->position,
            'total' => $this->total(),
            'sent' => $this->sent,
            'failed' => $this->failed,
            'finished' => $this->isFinished(),
        ];
    }
}
