<?php

namespace App\Services\Soprano;

use App\Models\Client;

/**
 * All-time listening stats and account settings for the current client.
 * Stats are computed from track_plays the same way WrappedService does it,
 * just without the calendar-year window: skip-aware counts (a confirmed skip
 * isn't a listen), while listened time uses ms_played so even a skipped play
 * contributes the seconds actually heard.
 */
class ProfileService
{
    /**
     * Headline listening stats over the client's whole history.
     *
     * @return array<string,mixed>
     */
    public function getStats(): array
    {
        $ns = MusicService::NOT_SKIPPED;

        // ms_played fallback: rows predating skip-tracking (NULL, never
        // finalized) count their full length; skipped rows always carry
        // ms_played, so the length fallback can't inflate them.
        $row = db()->fetch(
            "SELECT SUM(CASE WHEN $ns THEN 1 ELSE 0 END) AS plays,
                    COALESCE(SUM(COALESCE(tp.ms_played, tm.length_ms)), 0) AS listened_ms,
                    COUNT(DISTINCT CASE WHEN $ns THEN tp.track_id END) AS tracks,
                    COUNT(DISTINCT CASE WHEN $ns THEN t.track_artist_id END) AS artists,
                    COUNT(DISTINCT CASE WHEN $ns THEN t.album_id END) AS albums,
                    COUNT(DISTINCT CASE WHEN $ns THEN DATE(tp.created_at) END) AS days,
                    MIN(tp.created_at) AS first_play
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             LEFT JOIN track_meta tm ON tm.track_id = t.id
             WHERE tp.client_id = ?",
            [client()->id],
        );

        $minutes = intdiv((int) ($row['listened_ms'] ?? 0), 60000);

        return [
            'plays'       => (int) ($row['plays'] ?? 0),
            'minutes'     => $minutes,
            'hours'       => intdiv($minutes, 60),
            'tracks'      => (int) ($row['tracks'] ?? 0),
            'artists'     => (int) ($row['artists'] ?? 0),
            'albums'      => (int) ($row['albums'] ?? 0),
            'days'        => (int) ($row['days'] ?? 0),
            'likes'       => $this->likedCount(),
            'playlists'   => $this->playlistCount(),
            'top_artist'  => $this->topArtist(),
            'top_genre'   => $this->topGenre(),
            'first_play'  => $row['first_play'] ?? null,
        ];
    }

    private function likedCount(): int
    {
        $row = db()->fetch(
            "SELECT COUNT(*) AS c FROM track_likes WHERE client_id = ?",
            [client()->id],
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Only playlists the client made themselves. Generated mixes carry a
     * `slot` and live on the home rail, so counting them here inflates the
     * total past what the playlists page shows.
     */
    private function playlistCount(): int
    {
        $row = db()->fetch(
            "SELECT COUNT(*) AS c FROM playlists WHERE client_id = ? AND slot IS NULL",
            [client()->id],
        );
        return (int) ($row['c'] ?? 0);
    }

    /** @return array{name:string,hash:string,plays:int}|null */
    private function topArtist(): ?array
    {
        $row = db()->fetch(
            "SELECT ar.name AS name, ar.hash AS hash, COUNT(*) AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN artists ar ON ar.id = t.track_artist_id
             WHERE tp.client_id = ? AND " . MusicService::NOT_SKIPPED . "
             GROUP BY ar.id
             ORDER BY plays DESC
             LIMIT 1",
            [client()->id],
        );

        if (!$row) {
            return null;
        }

        return [
            'name'  => (string) $row['name'],
            'hash'  => (string) $row['hash'],
            'plays' => (int) $row['plays'],
        ];
    }

    /** @return array{genre:string,plays:int}|null */
    private function topGenre(): ?array
    {
        $row = db()->fetch(
            "SELECT al.genre AS genre, COUNT(*) AS plays
             FROM track_plays tp
             JOIN tracks t ON t.id = tp.track_id
             JOIN albums al ON al.id = t.album_id
             WHERE tp.client_id = ? AND " . MusicService::NOT_SKIPPED . "
               AND al.genre <> '' AND al.genre <> 'Unknown'
             GROUP BY al.genre
             ORDER BY plays DESC
             LIMIT 1",
            [client()->id],
        );

        if (!$row) {
            return null;
        }

        return [
            'genre' => (string) $row['genre'],
            'plays' => (int) $row['plays'],
        ];
    }

    /**
     * Current player defaults for the signed-in client, normalised so the
     * template always has usable values even on rows predating the columns.
     *
     * @return array{shuffle:bool,repeat:string,crossfade:bool}
     */
    public function getSettings(): array
    {
        $client = client();
        $repeat = $client->default_repeat ?? 'off';
        if (!in_array($repeat, PlaylistService::REPEAT_MODES, true)) {
            $repeat = 'off';
        }

        return [
            'shuffle'   => (bool) ($client->default_shuffle ?? false),
            'repeat'    => $repeat,
            'crossfade' => (bool) ($client->crossfade ?? false),
        ];
    }

    /**
     * Persist the client's player defaults and apply them to the live queue
     * so the change takes effect immediately.
     */
    public function saveSettings(bool $shuffle, string $repeat, bool $crossfade): void
    {
        if (!in_array($repeat, PlaylistService::REPEAT_MODES, true)) {
            $repeat = 'off';
        }

        Client::find(client()->id)?->update([
            'default_shuffle' => $shuffle ? 1 : 0,
            'default_repeat'  => $repeat,
            'crossfade'       => $crossfade ? 1 : 0,
        ]);

        container()->get(PlaylistService::class)->applyDefaults($shuffle, $repeat);
    }
}
