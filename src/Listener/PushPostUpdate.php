<?php

/*
 * This file is part of lcoy/cipher.
 *
 * (c) Lcoy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lcoy\Cipher\Listener;

use Flarum\Extension\ExtensionManager;
use Flarum\Likes\Event\PostWasLiked;
use Flarum\Likes\Event\PostWasUnliked;
use Illuminate\Database\ConnectionInterface;
use Psr\Container\ContainerInterface;

/**
 * Pushes real-time updates over the forum's WebSocket channel (flarum-pusher)
 * so visitors see a locked card's checklist refresh without reloading.
 *
 * Deliberately scoped to avoid abusing the socket:
 *  - Only the `minlikes` condition depends on other users' actions, so only
 *    like events are broadcast — nothing fires for like/reply/follow, which
 *    are decided by the actor themselves and refresh via their own actions.
 *  - Events are only triggered for posts that actually contain a protected
 *    block with a numeric minlikes condition, and only when flarum-pusher is
 *    enabled.
 *  - Only the like that crosses a threshold is broadcast. A gate's verdict is
 *    "count >= N", so it changes exactly once — at the like that makes the
 *    count reach N (or, for an unlike, the one that drops it back below).
 *    Broadcasting every later like would make every connected visitor refetch
 *    the same, already-unlocked status.
 */
class PushPostUpdate
{
    public function __construct(
        protected ExtensionManager $extensions,
        protected ContainerInterface $container,
        protected ConnectionInterface $db
    ) {
    }

    public function handle(PostWasLiked|PostWasUnliked $event): void
    {
        if (! $this->extensions->isEnabled('flarum-pusher')) {
            return;
        }

        $post = $event->post;

        // `content` is the unparsed BBCode for editing; the stored XML lives
        // behind getParsedContentAttribute(), and that is what carries the
        // <PROTECTED> tags, so thresholds are read without rendering the post.
        $content = $post->getParsedContentAttribute();

        if (! is_string($content) || ! str_contains($content, '<PROTECTED')) {
            return;
        }

        $thresholds = $this->minlikesThresholds($content);

        if ($thresholds === []) {
            return;
        }

        // flarum/likes only raises these events when the row is actually
        // inserted or deleted, so the count read now already includes (or
        // already excludes) this actor's like.
        $count = (int) $this->db->table('post_likes')->where('post_id', $post->id)->count();

        // A like leaves the count at $count and the gate opens iff it is now at
        // the threshold; an unlike leaves it at $count with the gate having
        // closed iff the threshold was the count just above.
        $crossed = $event instanceof PostWasLiked ? $count : $count + 1;

        if (! in_array($crossed, $thresholds, true)) {
            return;
        }

        if (! $this->container->has(\Pusher\Pusher::class)) {
            return;
        }

        try {
            $pusher = $this->container->get(\Pusher\Pusher::class);

            // The client only needs to know which post to refresh; the block
            // ids and their new verdicts come from the status endpoint, so
            // there is no reason to duplicate them in the payload.
            $pusher->trigger('public', 'cipherPostUpdate', [
                'postId' => $post->id,
            ]);
        } catch (\Throwable) {
            // Never let a push failure break the request that triggered it.
        }
    }

    /**
     * The distinct minlikes values configured on the post's blocks.
     *
     * @return int[]
     */
    protected function minlikesThresholds(string $xml): array
    {
        if (! preg_match_all('~minlikes="(\d+)"~i', $xml, $matches)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $matches[1]))));
    }
}
