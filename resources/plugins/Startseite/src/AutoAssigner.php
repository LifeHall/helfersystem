<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Config\Config;
use Engelsystem\Helpers\Carbon;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\User\User;
use Engelsystem\Models\UserAngelType;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Automatic assignment of the helpers by their wishes (helper types, time window per day, number of shifts)
 *
 * Free slots are taken first. If the wish can not be fulfilled, a full slot is freed by moving an automatically
 * assigned helper to another free slot of their own wish (chains up to DEPTH moves, like an augmenting path).
 * Shift entries the helpers chose themselves or a shift coordinator made are never moved.
 *
 * The checks do not use the logged in user (the core functions do), as other helpers are moved.
 */
class AutoAssigner
{
    protected const LOCK = 'startseite_auto';

    // Maximum number of moves to free one slot
    protected const DEPTH = 3;

    /** @var array[] Slots of Slots::load() by key */
    protected array $slots = [];

    /** @var array<string, array<int, int>> Shift entries (not freeloaded) by slot key: [entry id => user id] */
    protected array $slotEntries = [];

    /** @var array<int, array<int, array>> Upcoming shift entries by user: [entry id => ['shift', 'type_id', 'key']] */
    protected array $userEntries = [];

    /** @var bool[] Automatic shift entries by entry id */
    protected array $auto = [];

    /** @var AutoWish[] By user id, oldest first */
    protected array $wishes = [];

    /** @var array<int, array<int, bool>> Memberships by user and helper type: confirmed */
    protected array $memberships = [];

    /** @var array<int, User|false> Users that can be assigned (false: freeloader, not arrived or missing) */
    protected array $users = [];

    /** @var string[][] Matching slot keys by user */
    protected array $candidates = [];

    /** @var array<int, array[]> Moves of this run by user for the mails: [['from' => array, 'to' => array]] */
    protected array $moves = [];

    protected int $nesting = 0;

    protected int $now = 0;

    public function __construct(
        protected Connection $db,
        protected Config $config,
        protected ShiftPlanMailer $mailer
    ) {
    }

    /**
     * Runs the callback in a transaction under a lock, so parallel assignments can not overbook a slot,
     * afterwards the moved helpers get a mail. Can be nested.
     */
    public function run(callable $callback): mixed
    {
        if ($this->nesting) {
            return $callback($this);
        }

        if (!$this->db->selectOne('SELECT GET_LOCK(?, 10) AS `locked`', [self::LOCK])?->locked) {
            throw new RuntimeException('The automatic assignment is busy');
        }

        $this->nesting++;
        $this->moves = [];
        try {
            $result = $this->db->transaction(fn() => $callback($this));
        } catch (\Throwable $e) {
            $this->moves = [];
            throw $e;
        } finally {
            $this->nesting--;
            $this->db->selectOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }

        $this->sendMoves();

        return $result;
    }

    /**
     * Loads the current slots, shift entries, wishes and memberships
     */
    public function load(): void
    {
        $this->now = Carbon::now()->timestamp;
        $this->slots = Slots::load()->keyBy('key')->all();

        $this->slotEntries = [];
        $this->userEntries = [];
        $entries = ShiftEntry::with('shift')
            ->whereHas('shift', fn($query) => $query->where('end', '>', Carbon::now()))
            ->get();
        foreach ($entries as $entry) {
            $key = $entry->shift_id . ':' . $entry->angel_type_id;
            $this->userEntries[$entry->user_id][$entry->id] = [
                'shift' => $entry->shift,
                'type_id' => $entry->angel_type_id,
                'key' => $key,
            ];
            if (!$entry->freeloaded_by) {
                $this->slotEntries[$key][$entry->id] = $entry->user_id;
            }
        }

        $this->auto = array_fill_keys(AutoEntry::pluck('shift_entry_id')->all(), true);
        $this->wishes = AutoWish::orderBy('created_at')->orderBy('user_id')->get()->keyBy('user_id')->all();

        $this->memberships = [];
        foreach (UserAngelType::all() as $membership) {
            $this->memberships[$membership->user_id][$membership->angel_type_id] = (bool) $membership->confirm_user_id;
        }

        $this->users = [];
        $this->candidates = [];
    }

    /**
     * Assigns the user up to the number of wished shifts, own shift entries count too
     *
     * @return array ['added' => int, 'have' => int, 'wanted' => int]
     */
    public function assign(int $userId): array
    {
        $wish = $this->wishes[$userId] ?? null;
        $have = count($this->userEntries[$userId] ?? []);
        if (!$wish) {
            return ['added' => 0, 'have' => $have, 'wanted' => 0];
        }

        $added = 0;
        while ($have < $wish->count) {
            $key = $this->bestFree($userId) ?? $this->rebookFor($userId);
            if (!$key) {
                break;
            }

            $this->create($userId, $key, true);
            $have++;
            $added++;
        }

        return ['added' => $added, 'have' => $have, 'wanted' => $wish->count];
    }

    /**
     * After the wish of the user changed: releases their automatic entries that no longer match it
     * (or exceed the number), then assigns again
     *
     * @return array See assign(), with 'removed' => int
     */
    public function reassign(int $userId): array
    {
        $wish = $this->wishes[$userId] ?? null;
        $removed = 0;

        $auto = array_filter(
            $this->userEntries[$userId] ?? [],
            fn(array $entry, int $entryId) => $this->movable($entryId, $entry['key']),
            ARRAY_FILTER_USE_BOTH
        );
        // Latest first, so a lower number keeps the earlier shifts
        uasort($auto, fn($a, $b) => $b['shift']->start->timestamp <=> $a['shift']->start->timestamp);

        foreach ($auto as $entryId => $entry) {
            $matches = $wish && isset($this->slots[$entry['key']]) && $wish->matches($this->slots[$entry['key']]);
            if ($matches && count($this->userEntries[$userId]) <= $wish->count) {
                continue;
            }

            $this->remove($entryId, true);
            $removed++;
        }

        return ['removed' => $removed] + $this->assign($userId);
    }

    /**
     * Assigns all wishes that are not fulfilled yet, oldest wish first
     *
     * @return int Added shift entries
     */
    public function fillOpen(): int
    {
        $added = 0;
        foreach (array_keys($this->wishes) as $userId) {
            $added += $this->assign($userId)['added'];
        }

        return $added;
    }

    /**
     * A full slot can be freed for the user by moving automatically assigned helpers
     *
     * @param string[] $exclude Slot keys the moved helpers must not go to
     */
    public function canFree(string $key, int $forUserId, array $exclude = []): bool
    {
        return $this->free($key) > 0 || (bool) $this->findPath($key, $forUserId, $exclude);
    }

    /**
     * Frees a place in the slot for the user by moving automatically assigned helpers
     *
     * @param string[] $exclude Slot keys the moved helpers must not go to
     */
    public function makeRoom(string $key, int $forUserId, array $exclude = []): bool
    {
        if ($this->free($key) > 0) {
            return true;
        }

        $path = $this->findPath($key, $forUserId, $exclude);
        if (!$path) {
            return false;
        }

        $this->applyPath($path);

        return true;
    }

    /**
     * Upcoming slots matching the wish of the user
     *
     * @return string[]
     */
    public function candidates(int $userId): array
    {
        $wish = $this->wishes[$userId] ?? null;
        if (!$wish) {
            return [];
        }

        return $this->candidates[$userId] ??= array_keys(array_filter(
            $this->slots,
            fn(array $slot) => $slot['shift']->start->timestamp > $this->now && $wish->matches($slot)
        ));
    }

    public function free(string $key): int
    {
        return isset($this->slots[$key])
            ? max(0, $this->slots[$key]['count'] - count($this->slotEntries[$key] ?? []))
            : 0;
    }

    /**
     * The user can take the slot (without looking at free places)
     *
     * @param string[] $ignore Slot keys of own entries to ignore (the one the user is moved away from)
     */
    public function feasible(int $userId, string $key, array $ignore = []): bool
    {
        $slot = $this->slots[$key] ?? null;
        if (!$slot) {
            return false;
        }

        $shift = $slot['shift'];
        $type = $slot['type'];
        if ($shift->start->timestamp <= $this->now || !$type->shift_self_signup) {
            return false;
        }

        $confirmed = $this->memberships[$userId][$type->id] ?? null;
        if ($type->restricted && !$confirmed) {
            return false;
        }

        if (!$this->user($userId)) {
            return false;
        }

        $advanceHours = $shift->shiftType->signup_advance_hours ?: $this->config->get('signup_advance_hours');
        if ($advanceHours && $shift->start->timestamp > $this->now + $advanceHours * 3600) {
            return false;
        }

        foreach ($this->userEntries[$userId] ?? [] as $entry) {
            if (!in_array($entry['key'], $ignore, true) && Slots::collides($shift, $type->id, $entry['shift'], $entry['type_id'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Automatic entry that can still be signed off (same limit as for the helpers in the core)
     */
    protected function movable(int $entryId, string $key): bool
    {
        $slot = $this->slots[$key] ?? null;

        return isset($this->auto[$entryId])
            && $slot
            && $slot['shift']->start->timestamp - (int) $this->config->get('last_unsubscribe') * 3600 > $this->now;
    }

    /**
     * Best free slot of the wish: next to own shifts (same location, directly before or after),
     * then the emptiest, then the earliest
     */
    protected function bestFree(int $userId): ?string
    {
        $best = null;
        $bestScore = null;
        foreach ($this->candidates($userId) as $key) {
            if ($this->free($key) <= 0 || !$this->feasible($userId, $key)) {
                continue;
            }

            $score = $this->score($userId, $key);
            if ($bestScore === null || $score < $bestScore) {
                $best = $key;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Frees the best full slot of the wish by moving others, null if there is none
     */
    protected function rebookFor(int $userId): ?string
    {
        $keys = array_filter(
            $this->candidates($userId),
            fn(string $key) => $this->free($key) <= 0 && $this->feasible($userId, $key)
        );
        usort($keys, fn($a, $b) => $this->score($userId, $a) <=> $this->score($userId, $b));

        foreach ($keys as $key) {
            $path = $this->findPath($key, $userId);
            if ($path) {
                $this->applyPath($path);
                return $key;
            }
        }

        return null;
    }

    protected function score(int $userId, string $key): array
    {
        $slot = $this->slots[$key];
        $shift = $slot['shift'];

        $adjacent = false;
        foreach ($this->userEntries[$userId] ?? [] as $entry) {
            $adjacent = $adjacent || (
                $entry['shift']->location_id == $shift->location_id
                && (
                    $entry['shift']->end->timestamp == $shift->start->timestamp
                    || $entry['shift']->start->timestamp == $shift->end->timestamp
                )
            );
        }

        return [
            $adjacent ? 0 : 1,
            count($this->slotEntries[$key] ?? []) / max(1, $slot['count']),
            $shift->start->timestamp,
        ];
    }

    /**
     * Breadth-first search for the shortest chain of moves that frees a place in the full slot:
     * an automatically assigned helper moves to another slot of their wish, which is free or freed the same way
     *
     * @param string[] $exclude Slot keys the moved helpers must not go to
     * @return array[]|null [['entry_id', 'user_id', 'from', 'to']], the last move goes to a free slot
     */
    protected function findPath(string $target, int $forUserId, array $exclude = []): ?array
    {
        $visited = array_fill_keys($exclude, true) + [$target => true];
        $queue = [[$target, [], [$forUserId]]];

        while ($queue) {
            [$key, $path, $users] = array_shift($queue);

            foreach ($this->slotEntries[$key] ?? [] as $entryId => $userId) {
                if (in_array($userId, $users, true) || !$this->movable($entryId, $key)) {
                    continue;
                }

                foreach ($this->candidates($userId) as $to) {
                    if (isset($visited[$to]) || !$this->feasible($userId, $to, [$key])) {
                        continue;
                    }

                    $move = ['entry_id' => $entryId, 'user_id' => $userId, 'from' => $key, 'to' => $to];
                    if ($this->free($to) > 0) {
                        return [...$path, $move];
                    }

                    if (count($path) + 1 < self::DEPTH) {
                        $visited[$to] = true;
                        $queue[] = [$to, [...$path, $move], [...$users, $userId]];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Moves from the end of the chain, so every helper moves into a free place
     */
    protected function applyPath(array $path): void
    {
        foreach (array_reverse($path) as $move) {
            $this->create($move['user_id'], $move['to'], true, false);
            $this->remove($move['entry_id'], false);

            $this->moves[$move['user_id']][] = [
                'from' => $this->describe($move['from']),
                'to' => $this->describe($move['to']),
            ];
            engelsystem_log(sprintf(
                'Automatic assignment: moved %s from %s to %s to make room',
                User_Nick_render(User::find($move['user_id']), true),
                $this->logName($move['from']),
                $this->logName($move['to'])
            ));
        }
    }

    /**
     * @param bool $notify Log and mail like the core (not for moves, they get an own mail)
     */
    protected function create(int $userId, string $key, bool $auto, bool $notify = true): void
    {
        $slot = $this->slots[$key];
        $user = User::findOrFail($userId);

        // Like the core: join open helper types automatically on sign up
        if (!isset($this->memberships[$userId][$slot['type']->id])) {
            $userAngelType = new UserAngelType();
            $userAngelType->user()->associate($user);
            $userAngelType->angelType()->associate($slot['type']);
            $userAngelType->save();
            $this->memberships[$userId][$slot['type']->id] = false;
        }

        $entry = new ShiftEntry();
        $entry->shift()->associate($slot['shift']);
        $entry->angelType()->associate($slot['type']);
        $entry->user()->associate($user);
        $entry->save();

        if ($auto) {
            AutoEntry::create(['shift_entry_id' => $entry->id, 'user_id' => $userId]);
            $this->auto[$entry->id] = true;
        }

        $this->slotEntries[$key][$entry->id] = $userId;
        $this->userEntries[$userId][$entry->id] = [
            'shift' => $slot['shift'],
            'type_id' => $slot['type']->id,
            'key' => $key,
        ];

        if ($notify) {
            ShiftEntry_onCreate($entry);
        }
    }

    protected function remove(int $entryId, bool $notify): void
    {
        $entry = ShiftEntry::findOrFail($entryId);
        $key = $entry->shift_id . ':' . $entry->angel_type_id;

        $entry->delete();
        if ($notify) {
            ShiftEntry_onDelete($entry);
        }

        unset(
            $this->slotEntries[$key][$entryId],
            $this->userEntries[$entry->user_id][$entryId],
            $this->auto[$entryId]
        );
    }

    /**
     * The user with everything the core checks for the helper itself, false if they can not be assigned
     */
    protected function user(int $userId): User|false
    {
        if (!isset($this->users[$userId])) {
            $user = User::with('state')->find($userId);
            $this->users[$userId] = $user
                && !$user->isFreeloader()
                && (!$this->config->get('signup_requires_arrival') || $user->state->arrived)
                ? $user
                : false;
        }

        return $this->users[$userId];
    }

    /**
     * @return string[] ['date', 'time', 'location', 'angel_type']
     */
    protected function describe(string $key): array
    {
        $slot = $this->slots[$key];
        $shift = $slot['shift'];

        return [
            'date' => $shift->start->format('d.m.Y'),
            'time' => $shift->start->format('H:i') . ' – ' . $shift->end->format('H:i'),
            'location' => $shift->location->name,
            'angel_type' => $slot['type']->name,
        ];
    }

    protected function logName(string $key): string
    {
        $info = $this->describe($key);

        return sprintf('%s %s %s (%s)', $info['date'], $info['time'], $info['location'], $info['angel_type']);
    }

    protected function sendMoves(): void
    {
        $moves = $this->moves;
        $this->moves = [];
        if (!$moves || !$this->mailer->isEnabled()) {
            return;
        }

        foreach (User::with(['contact', 'personalData', 'settings'])->findMany(array_keys($moves)) as $user) {
            $this->mailer->sendPlan(
                $user,
                'emails/startseite-rebooked',
                'startseite.rebooked.subject',
                ['moves' => $moves[$user->id]]
            );
        }
    }
}
