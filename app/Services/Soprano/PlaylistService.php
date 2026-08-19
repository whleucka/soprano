<?php

namespace App\Services\Soprano;

class PlaylistService
{
    public const REPEAT_MODES = ["off", "all", "one"];

    public function getPlaylist(): array
    {
        return state()->playlist;
    }

    public function setPlaylist(array $tracks, int $index = 0, ?string $source = null)
    {
        state()->playlist = [
            "tracks" => $tracks,
            "index" => $index,
            "order" => null,
            "source" => $source,
        ];
    }

    public function clearPlaylist()
    {
        state()->playlist = [
            "tracks" => [],
            "index" => 0,
            "order" => null,
            "source" => null,
        ];
    }

    /**
     * Whether the queue was loaded from the liked playlist — likes/unlikes
     * mirror into the queue while this is the source.
     */
    public function isLikedQueue(): bool
    {
        return (state()->playlist["source"] ?? null) === "liked";
    }

    public function setSource(?string $source): void
    {
        state()->playlist = ["source" => $source];
    }

    public function getSource(): ?string
    {
        return state()->playlist["source"] ?? null;
    }

    public function hasTrack(string $hash): bool
    {
        foreach (state()->playlist["tracks"] ?? [] as $track) {
            if (($track["hash"] ?? null) === $hash) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop a track from the queue, keeping the current index and any
     * shuffle order pointing at the same tracks. Removing the currently
     * playing track leaves the index on whatever slid into its slot.
     */
    public function removeTrack(string $hash): bool
    {
        $playlist = state()->playlist;
        $tracks = $playlist["tracks"] ?? [];

        $remove_at = null;
        foreach ($tracks as $i => $track) {
            if (($track["hash"] ?? null) === $hash) {
                $remove_at = $i;
                break;
            }
        }
        if ($remove_at === null) {
            return false;
        }

        array_splice($tracks, $remove_at, 1);

        $index = (int) ($playlist["index"] ?? 0);
        if ($remove_at < $index) {
            $index--;
        }
        $index = max(0, min($index, count($tracks) - 1));

        $order = $playlist["order"] ?? null;
        if (is_array($order)) {
            $order = array_values(array_filter($order, fn ($i) => $i !== $remove_at));
            foreach ($order as &$i) {
                if ($i > $remove_at) $i--;
            }
            unset($i);
        }

        state()->playlist = ["tracks" => $tracks, "index" => $index, "order" => $order];
        return true;
    }

    /**
     * Jump the queue to a track the user picked (a queue row click). With
     * shuffle on this re-deals the order anchored at the new track: the
     * stored walk knows nothing about the jump, so keeping it would resume
     * from a stale position — replaying tracks, skipping others, and (when
     * the picked track happened to sit last in the order) ending playback
     * on the next track end with most of the queue unplayed.
     *
     * Auto advances walk the order themselves and must not re-deal it, so
     * changePlaylistTrack() writes the index directly instead.
     */
    public function setPlaylistIndex(int $index = 0)
    {
        $playlist = state()->playlist;
        $tracks = $playlist["tracks"] ?? [];

        state()->playlist = [
            "index" => $index,
            "order" => !empty($playlist["shuffle"]) && !empty($tracks)
                ? $this->buildOrder(count($tracks), $index)
                : ($playlist["order"] ?? null),
        ];
    }

    /**
     * Splice a track into the queue right after the current one ("play
     * next") or at the end ("add to queue"), keeping any shuffle order
     * walking the same tracks it was.
     */
    public function queueTrack(array $track, bool $next = false): void
    {
        $playlist = state()->playlist;
        $tracks = $playlist["tracks"] ?? [];

        if (empty($tracks)) {
            $this->setPlaylist([$track]);
            return;
        }

        $index = (int) ($playlist["index"] ?? 0);
        $insert_at = $next ? $index + 1 : count($tracks);
        array_splice($tracks, $insert_at, 0, [$track]);

        $order = $playlist["order"] ?? null;
        if (is_array($order)) {
            // Keep the shuffled walk valid: shift indices at/after the
            // insert point, then slot the new track in — right after the
            // current track for play-next, at the end of the walk otherwise.
            foreach ($order as &$i) {
                if ($i >= $insert_at) $i++;
            }
            unset($i);
            if ($next) {
                $pos = array_search($index, $order, true);
                array_splice($order, ($pos === false ? 0 : $pos) + 1, 0, [$insert_at]);
            } else {
                $order[] = $insert_at;
            }
        }

        state()->playlist = ["tracks" => $tracks, "order" => $order];
    }

    /**
     * Mirror a bulk like/unlike into the queue while it is the liked playlist:
     * freshly liked tracks land at the end, unliked ones drop out. $tracks are
     * feed rows (see MusicService). Returns true when the queue changed.
     */
    public function syncLiked(array $tracks, bool $liked): bool
    {
        if (!$this->isLikedQueue()) {
            return false;
        }

        return $liked
            ? $this->queueTracks($tracks)
            : $this->removeTracks(array_column($tracks, "hash"));
    }

    /**
     * Append every track that isn't queued yet to the end of the queue,
     * extending any shuffle walk to cover them. Returns true when something
     * was added.
     */
    public function queueTracks(array $tracks): bool
    {
        $playlist = state()->playlist;
        $queue = $playlist["tracks"] ?? [];
        $queued = array_flip(array_column($queue, "hash"));

        $new = [];
        foreach ($tracks as $track) {
            $hash = $track["hash"] ?? null;
            if (!$hash || isset($queued[$hash])) {
                continue;
            }
            $queued[$hash] = true;
            $new[] = $track;
        }
        if (!$new) {
            return false;
        }

        $order = $playlist["order"] ?? null;
        if (is_array($order)) {
            for ($i = count($queue); $i < count($queue) + count($new); $i++) {
                $order[] = $i;
            }
        }

        state()->playlist = ["tracks" => [...$queue, ...$new], "order" => $order];
        return true;
    }

    /**
     * Drop every copy of the given hashes from the queue in one pass, keeping
     * the index and any shuffle order pointing at the same tracks. Removing
     * the playing track leaves the index on whatever slid into its slot.
     * Returns true when something was removed.
     */
    public function removeTracks(array $hashes): bool
    {
        $playlist = state()->playlist;
        $tracks = $playlist["tracks"] ?? [];
        $drop = array_flip(array_filter($hashes));

        $kept = [];
        $moved = [];   // old position => new position (or the one sliding into it)
        $removed = []; // old positions that are gone
        foreach ($tracks as $i => $track) {
            $moved[$i] = count($kept);
            if (isset($drop[$track["hash"] ?? ""])) {
                $removed[$i] = true;
                continue;
            }
            $kept[] = $track;
        }
        if (!$removed) {
            return false;
        }

        $index = (int) ($playlist["index"] ?? 0);
        $index = max(0, min($moved[$index] ?? 0, count($kept) - 1));

        $order = $playlist["order"] ?? null;
        if (is_array($order)) {
            $order = array_values(array_map(
                fn($i) => $moved[$i] ?? 0,
                array_filter($order, fn($i) => !isset($removed[$i])),
            ));
        }

        state()->playlist = ["tracks" => $kept, "index" => $index, "order" => $order];
        return true;
    }

    /**
     * Seed the session queue with a client's saved player defaults. Called
     * once per session (sign-in / remember-me restore) before any queue is
     * built, so it just sets the flags — the shuffle order is dealt later
     * when tracks actually get queued.
     */
    public function applyDefaults(bool $shuffle, string $repeat): void
    {
        if (!in_array($repeat, self::REPEAT_MODES, true)) {
            $repeat = "off";
        }

        $tracks = state()->playlist["tracks"] ?? [];
        state()->playlist = [
            "shuffle" => $shuffle,
            "repeat"  => $repeat,
            "order"   => $shuffle && !empty($tracks)
                ? $this->buildOrder(count($tracks), (int) (state()->playlist["index"] ?? 0))
                : null,
        ];
    }

    public function toggleShuffle()
    {
        $playlist = state()->playlist;
        $shuffle = !($playlist["shuffle"] ?? false);
        // Turning shuffle on deals a fresh order; turning it off discards it.
        state()->playlist = [
            "shuffle" => $shuffle,
            "order" => $shuffle && !empty($playlist["tracks"])
                ? $this->buildOrder(count($playlist["tracks"]), (int) ($playlist["index"] ?? 0))
                : null,
        ];
    }

    public function cycleRepeat(): string
    {
        $current = state()->playlist["repeat"] ?? "off";
        $pos = array_search($current, self::REPEAT_MODES, true);
        $next = self::REPEAT_MODES[($pos === false ? 0 : $pos + 1) % count(self::REPEAT_MODES)];
        state()->playlist = ["repeat" => $next];
        return $next;
    }

    /**
     * Advance the queue. $auto marks a natural end-of-track advance (as
     * opposed to the user pressing next/prev): manual moves always go
     * somewhere and wrap, auto moves respect the repeat mode — 'one'
     * replays the current track, 'all' wraps, 'off' stops at the queue end.
     */
    public function changePlaylistTrack(bool $forward = true, bool $auto = false): array|false
    {
        $playlist = state()->playlist;
        $tracks = $playlist["tracks"] ?? [];
        $count = count($tracks);
        $index = (int) ($playlist["index"] ?? 0);
        $repeat = $playlist["repeat"] ?? "off";

        if ($count === 0) return false;

        if ($auto && $repeat === "one") {
            return $tracks[$index] ?? false;
        }

        if ($count < 2) {
            return $auto && $repeat === "all" ? ($tracks[$index] ?? false) : false;
        }

        if (!empty($playlist["shuffle"])) {
            // Walk the shuffled order: next/prev follow real history and no
            // track repeats until the whole order has played.
            $order = $this->shuffleOrder($playlist);
            $pos = array_search($index, $order, true);
            if ($pos === false) $pos = 0;
            $new_pos = $forward ? $pos + 1 : $pos - 1;
            if ($auto && $repeat !== "all" && ($new_pos < 0 || $new_pos >= $count)) {
                return false;
            }
            $new_index = $order[($new_pos + $count) % $count];
        } else {
            $new_index = $forward ? $index + 1 : $index - 1;
            if ($auto && $repeat !== "all" && ($new_index < 0 || $new_index >= $count)) {
                return false;
            }
            $new_index = ($new_index + $count) % $count;
        }

        if (!isset($tracks[$new_index])) return false;

        // Straight index write: the shuffle order already covers this move.
        state()->playlist = ["index" => $new_index];

        return $tracks[$new_index];
    }

    /**
     * Whether a natural end-of-track advance would land on another track —
     * i.e. the crossfade should arm. Read-only mirror of the auto/forward
     * branch of changePlaylistTrack(): it never moves the queue index.
     */
    public function hasNextAuto(): bool
    {
        $playlist = state()->playlist;
        $tracks = $playlist["tracks"] ?? [];
        $count = count($tracks);
        $index = (int) ($playlist["index"] ?? 0);
        $repeat = $playlist["repeat"] ?? "off";

        if ($count === 0) return false;

        // repeat-one replays the same track — a valid crossfade target.
        if ($repeat === "one") return isset($tracks[$index]);

        // A lone track only "advances" when repeat-all is on.
        if ($count < 2) return $repeat === "all" && isset($tracks[$index]);

        if (!empty($playlist["shuffle"])) {
            $order = $this->shuffleOrder($playlist);
            $pos = array_search($index, $order, true);
            if ($pos === false) $pos = 0;
            $new_pos = $pos + 1;
            if ($repeat !== "all" && $new_pos >= $count) return false;
            return isset($tracks[$order[($new_pos + $count) % $count]]);
        }

        $new_index = $index + 1;
        if ($repeat !== "all" && $new_index >= $count) return false;
        return isset($tracks[($new_index + $count) % $count]);
    }

    /**
     * Current shuffled order, rebuilt if the queue changed underneath it.
     */
    private function shuffleOrder(array $playlist): array
    {
        $count = count($playlist["tracks"]);
        $order = $playlist["order"] ?? null;
        if (is_array($order) && count($order) === $count) {
            return $order;
        }
        $order = $this->buildOrder($count, (int) ($playlist["index"] ?? 0));
        state()->playlist = ["order" => $order];
        return $order;
    }

    /**
     * Fisher-Yates order over track indices, anchored so the currently
     * playing track leads the walk.
     */
    private function buildOrder(int $count, int $current): array
    {
        $order = range(0, $count - 1);
        shuffle($order);
        $pos = array_search($current, $order, true);
        if ($pos !== false && $pos !== 0) {
            array_splice($order, $pos, 1);
            array_unshift($order, $current);
        }
        return $order;
    }
}
