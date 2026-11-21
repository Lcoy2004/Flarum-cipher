<?php

/*
 * This file is part of lcoy/cipher.
 *
 * (c) Lcoy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lcoy\Cipher;

use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Evaluates the visibility conditions attached to a [protected] block.
 *
 * Conditions (all optional, combined with the password):
 *  - like="1"     visitor must have liked the post
 *  - reply="1"    visitor must have replied in the discussion
 *  - follow="1"   visitor must follow the post author
 *  - minlikes="N" the post must have at least N likes
 *  - time="..."   content is only visible after this time (absolute date or
 *                 relative offset such as "2d", "12h", "30m")
 */
class Conditions
{
    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    protected ?bool $hasFollowersTable = null;
    protected ?bool $hasLikesTable = null;
    protected ?bool $hasDiscussionFollowTable = null;

    /**
     * Request-scoped query results.
     *
     * A single render can evaluate the same condition for several protected
     * blocks of the same post (and the same actor), so cache each result per
     * post+actor key to avoid re-issuing the same SQL over and over. The
     * container gives every consumer its own instance per request, so these
     * never leak across requests.
     *
     * @var array<string,bool|int>
     */
    protected array $memo = [];

    /**
     * Request-scoped time parse results, keyed by the raw attribute value.
     *
     * The same block's time gate is evaluated multiple times per request
     * (statuses(), met/unmet messages, unlock checks, render) — all of them
     * must agree on a single instant, and re-running the regex/strtotime for
     * each is pure waste.
     *
     * @var array<string,int|null>
     */
    protected array $timeMemo = [];

    /**
     * Request-scoped like aggregates, keyed by post + actor.
     *
     * @var array<string, array{count: int, liked: bool}>
     */
    protected array $likeStatsMemo = [];

    protected function remember(string $key, callable $callback): bool|int
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $callback();
        }

        return $this->memo[$key];
    }

    /**
     * Parse a time attribute into a unix timestamp.
     */
    public function parseTime(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (array_key_exists($value, $this->timeMemo)) {
            return $this->timeMemo[$value];
        }

        // Bare unix timestamps (as stored by ProtectedFilter for relative times).
        if (preg_match('/^\d{9,10}$/', $value)) {
            return $this->timeMemo[$value] = (int) $value;
        }

        // Relative offsets such as "+1h", "2d", "30m", "45s" — computed
        // explicitly because strtotime() misparses the ambiguous "h"/"d"
        // shorthands.
        if (($seconds = self::relativeSeconds($value)) !== null) {
            return $this->timeMemo[$value] = time() + $seconds;
        }

        $timestamp = strtotime($value);

        return $this->timeMemo[$value] = $timestamp === false ? null : $timestamp;
    }

    /**
     * Seconds represented by a relative offset such as "+1h", "2d", "30m",
     * "45s", or null if the value isn't one.
     *
     * Shared with ProtectedFilter: parse time normalizes relative offsets to
     * absolute timestamps anchored to the moment the post was written, and the
     * render-time fallback (legacy blocks) must interpret them identically.
     */
    public static function relativeSeconds(string $value): ?int
    {
        if (preg_match('/^\+?(\d+)\s*(d|h|m|i|s)\b/i', $value, $m)) {
            $seconds = ['d' => 86400, 'h' => 3600, 'm' => 60, 'i' => 60, 's' => 1][strtolower($m[2])];

            return (int) $m[1] * $seconds;
        }

        return null;
    }

    /**
     * Resolve the visibility timestamp of a block's attributes.
     */
    public function timeTarget(array $attrs): ?int
    {
        return $this->parseTime((string) ($attrs['time'] ?? ''));
    }

    /**
     * The post's total like count and whether the actor is among the likers.
     *
     * `like` (has the actor liked it?) and `minlikes` (has the post reached N
     * likes?) read the same table, so one aggregate answers both instead of two
     * queries per block. Guests are counted in the total but can never be a
     * liker.
     *
     * @return array{count: int, liked: bool}
     */
    public function likeStats(Post $post, User $actor): array
    {
        if (! $this->hasLikesTable()) {
            return ['count' => 0, 'liked' => false];
        }

        $key = $post->id.'.'.$actor->id;

        if (isset($this->likeStatsMemo[$key])) {
            return $this->likeStatsMemo[$key];
        }

        // post_likes is keyed by (post_id, user_id), so this is one index range
        // scan: the aggregate never needs a second pass over the rows.
        $row = $this->db->table('post_likes')
            ->where('post_id', $post->id)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS mine', [(int) $actor->id])
            ->first();

        return $this->likeStatsMemo[$key] = [
            'count' => (int) ($row->total ?? 0),
            'liked' => ! $actor->isGuest() && (int) ($row->mine ?? 0) > 0,
        ];
    }

    /**
     * @return bool whether the actor has liked the post
     *
     * Guests cannot like posts, so skip the query entirely for them.
     */
    public function isLikedBy(Post $post, User $actor): bool
    {
        if ($actor->isGuest() || ! $this->hasLikesTable()) {
            return false;
        }

        return $this->likeStats($post, $actor)['liked'];
    }

    /**
     * @return bool whether the actor has replied in the discussion
     *
     * Hidden/deleted replies don't count — only visible comments. Guests
     * cannot reply, so skip the query entirely for them.
     */
    public function hasReplied(Post $post, User $actor): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        return $this->remember('reply.'.$post->id.'.'.$actor->id, fn () => $this->db->table('posts')
            ->where('discussion_id', $post->discussion_id)
            ->where('user_id', $actor->id)
            ->where('type', 'comment')
            ->where('id', '!=', $post->id)
            ->whereNull('hidden_at')
            ->exists());
    }

    /**
     * @return bool whether the actor follows the post author
     *
     * Guests cannot follow anyone, so skip the query entirely for them.
     */
    public function followsAuthor(Post $post, User $actor): bool
    {
        if ($actor->isGuest() || ! $post->user_id || ! $this->hasFollowersTable()) {
            return false;
        }

        return $this->remember('follow.'.$post->id.'.'.$actor->id, fn () => $this->db->table('user_followers')
            ->where('user_id', $actor->id)
            ->where('followed_user_id', $post->user_id)
            ->exists());
    }

    /**
     * @return bool whether the actor follows the post's discussion
     *
     * Requires the flarum-subscriptions extension, which stores the state in
     * discussion_user.subscription ('follow' | 'ignore'). If the extension
     * isn't installed the table doesn't exist, so the condition can never be
     * met. Guests cannot follow, so skip the query entirely for them.
     */
    public function hasFollowedDiscussion(Post $post, User $actor): bool
    {
        if ($actor->isGuest() || ! $this->hasDiscussionFollowTable()) {
            return false;
        }

        return $this->remember('discussion.'.$post->id.'.'.$actor->id, fn () => $this->db->table('discussion_user')
            ->where('user_id', $actor->id)
            ->where('discussion_id', $post->discussion_id)
            ->where('subscription', 'follow')
            ->exists());
    }

    /**
     * @return int total number of likes on the post
     */
    public function likeCount(Post $post, User $actor): int
    {
        return $this->likeStats($post, $actor)['count'];
    }

    /**
     * Whether each configured condition is satisfied, in evaluation order.
     *
     * The order is the short-circuit order: the time gate is a plain comparison
     * on a pre-parsed timestamp, the actor-scoped relations come next, and the
     * aggregate like count — the only check that looks at other users' actions
     * — goes last. The keys double as the labels reported to callers, so
     * unmet() and firstUnmet() cannot drift apart.
     *
     * The checks are lazy: building the map costs nothing, and a caller that
     * stops early never issues the queries of the conditions it skipped.
     *
     * @param  array<string,string> $attrs
     * @return array<string, callable(): bool>
     */
    protected function checks(array $attrs, Post $post, User $actor): array
    {
        $checks = [];

        $target = $this->timeTarget($attrs);

        if ($target !== null) {
            $checks['time'] = static fn (): bool => time() >= $target;
        }

        if ($attrs['like'] ?? '') {
            $checks['like'] = fn (): bool => $this->isLikedBy($post, $actor);
        }

        if ($attrs['reply'] ?? '') {
            $checks['reply'] = fn (): bool => $this->hasReplied($post, $actor);
        }

        if ($attrs['follow'] ?? '') {
            $checks['follow'] = fn (): bool => $this->followsAuthor($post, $actor);
        }

        // s9e lowercases attribute names, so read the discussion-follow flag
        // with its lowercase key.
        if ($attrs['followdiscussion'] ?? '') {
            $checks['followDiscussion'] = fn (): bool => $this->hasFollowedDiscussion($post, $actor);
        }

        if (($minlikes = (int) ($attrs['minlikes'] ?? 0)) > 0) {
            $checks['minlikes'] = fn (): bool => $this->likeCount($post, $actor) >= $minlikes;
        }

        return $checks;
    }

    /**
     * The list of condition keys currently not satisfied for this actor.
     *
     * Every condition has to be evaluated here: the locked card lists each one
     * with its own tick, so stopping at the first failure would hide the rest.
     *
     * @param  array<string,string> $attrs
     * @return string[] e.g. ['time', 'like', 'reply', 'follow', 'followDiscussion', 'minlikes']
     */
    public function unmet(array $attrs, Post $post, User $actor): array
    {
        $unmet = [];

        foreach ($this->checks($attrs, $post, $actor) as $key => $met) {
            if (! $met()) {
                $unmet[] = $key;
            }
        }

        return $unmet;
    }

    /**
     * The first condition that fails, or null when all of them are satisfied.
     *
     * Callers that only need the verdict (the unlock endpoint) use this instead
     * of unmet(): a block gated on a future date or a missing like never reaches
     * the reply/follow queries.
     *
     * @param  array<string,string> $attrs
     */
    public function firstUnmet(array $attrs, Post $post, User $actor): ?string
    {
        foreach ($this->checks($attrs, $post, $actor) as $key => $met) {
            if (! $met()) {
                return $key;
            }
        }

        return null;
    }

    protected function hasFollowersTable(): bool
    {
        return $this->hasFollowersTable ??= $this->db->getSchemaBuilder()->hasTable('user_followers');
    }

    protected function hasLikesTable(): bool
    {
        return $this->hasLikesTable ??= $this->db->getSchemaBuilder()->hasTable('post_likes');
    }

    protected function hasDiscussionFollowTable(): bool
    {
        return $this->hasDiscussionFollowTable ??= $this->db->getSchemaBuilder()->hasTable('discussion_user');
    }
}
