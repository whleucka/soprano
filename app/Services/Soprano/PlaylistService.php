<?php

namespace App\Services\Soprano;

class PlaylistService
{
    public const REPEAT_MODES = ["off", "all", "one"];

    public function getPlaylist(): array
    {
        return state()->playlist;
    }

    public function setPlaylist(array $tracks, int $index = 0)
    {
        state()->playlist = [
            "tracks" => $tracks,
            "index" => $index,
            "order" => null,
        ];
    }

    public function clearPlaylist()
    {
        state()->playlist = [
            "tracks" => [],
            "index" => 0,
            "order" => null,
        ];
    }

    public function setPlaylistIndex(int $index = 0)
    {
        state()->playlist = [
            "index" => $index,
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
