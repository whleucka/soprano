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

    public function setPlaylistIndex(int $index = 0)
    {
        state()->playlist = [
            "index" => $index,
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

        $this->setPlaylistIndex($new_index);

        return $tracks[$new_index];
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
