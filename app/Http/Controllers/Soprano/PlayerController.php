<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{MixService, MusicService, PlaylistService, PlaylistsService, PlayerService, PodcastService, RadioService, ReplayGainService, SearchService, StationService, TranscodeService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;
use App\Http\StreamResponse;
use Echo\Framework\Http\Response;

#[Group(middleware: ["client"])]
class PlayerController extends Controller
{
    /**
     * Playback events player.js is allowed to report. Anything else is dropped
     * by clientLog() — see the note there on why this list is closed.
     */
    private const CLIENT_EVENTS = [
        // Media element events that can end or interrupt playback.
        'pause', 'play', 'playing', 'ended', 'stalled', 'waiting',
        'suspend', 'abort', 'emptied', 'error',
        // Page/OS lifecycle — Android freezing a backgrounded tab, or the
        // screen locking, stops the timers the crossfade rides on.
        'visibility', 'pagehide', 'freeze', 'resume',
        // Bluetooth earbuds connecting/dropping shows up as a device change.
        'devicechange', 'ctxstate',
        // Reported by our own code rather than the browser.
        'stall-detected', 'crossfade-stalled', 'autoplay-blocked',
        // Queue-advance recovery: a next-track request that had to be re-sent,
        // a finished track found still sitting there when the tab came back,
        // and a track that swapped in but never started.
        'advance-retry', 'advance-recovered', 'start-stalled',
        // The gapless early handoff a hidden tab uses when crossfade is off:
        // one armed, one whose next-track request never came back, and one
        // whose hold outlived its (frozen) timer and had to be released.
        'preadvance', 'preadvance-stalled', 'preadvance-released',
    ];

    /** Session key holding the last auto advance, for retry de-duplication. */
    private const LAST_AUTO_ADVANCE = 'player.last_auto_advance';

    /**
     * How long the same outgoing track re-requesting an auto advance counts as
     * a duplicate. Comfortably longer than a swap round trip, and short enough
     * that repeat-one on a very short track still advances on its own.
     */
    private const ADVANCE_DEDUP_SECONDS = 8;

    public function __construct(
        private PlayerService $player,
        private SearchService $search,
        private PlaylistService $playlist,
        private MusicService $music,
        private PlaylistsService $playlists,
        private RadioService $radio,
        private PodcastService $podcasts,
        private TranscodeService $transcode,
        private StationService $stations,
        private ReplayGainService $replaygain,
        private MixService $mix,
    ) {}

    #[Get("/player", "player.index")]
    public function load(): string
    {
        return $this->render("player/index.html.twig", [
            "player" => $this->player->getPlayer(),
            "playlist" => $this->playlist->getPlaylist()
        ]);
    }

    #[Get("/player/next-track", "player.next-track")]
    public function nextTrack()
    {
        // auto=1 marks a natural end-of-track advance, which is where the
        // repeat mode applies (replay on 'one', stop at queue end on 'off').
        $auto = (bool) request()->get->get("auto");
        $cur  = (string) (request()->get->get("cur") ?? '');

        // Idempotency for advance retries. player.js re-sends an auto advance
        // when the first one leaves no trace (a phone that slept through the
        // response, a swap that never landed), and a crossfade handoff that
        // recovers late can produce a second one too. Advancing twice silently
        // skips a track. `cur` names the track being left, so the same one
        // arriving again within a few seconds is a duplicate rather than a new
        // end-of-track: re-render the player instead, which resyncs the client
        // onto whatever is actually current — the recovery it was asking for.
        //
        // Keyed on time, deliberately not on queue position: a queue edited
        // under a playing track (removeTrack leaves the index on whatever slid
        // into the slot) would make a position check suppress a *real* advance
        // and stop playback outright. Manual next/prev carry no auto=1 and are
        // untouched.
        if ($auto && $cur !== '') {
            $last = session()->get(self::LAST_AUTO_ADVANCE);
            if (is_array($last)
                && ($last['hash'] ?? null) === $cur
                && (time() - (int) ($last['at'] ?? 0)) < self::ADVANCE_DEDUP_SECONDS) {
                $this->hxTrigger("loadPlayer, nowPlaying");
                return;
            }
            session()->set(self::LAST_AUTO_ADVANCE, ['hash' => $cur, 'at' => time()]);
        }

        $next = $this->playlist->changePlaylistTrack(true, $auto);
        if ($next) {
            $this->hxTrigger("nowPlaying");
            return $this->play($next['hash']);
        }
        // Queue ended with no advance (repeat off) — still close out the play.
        $this->logStalledAdvance($auto);
        $this->finalizeOutgoingPlay();
    }

    #[Get("/player/prev-track", "player.prev-track")]
    public function prevTrack()
    {
        $prev = $this->playlist->changePlaylistTrack(false);
        if ($prev) {
            $this->hxTrigger("nowPlaying");
            return $this->play($prev['hash']);
        }
        $this->finalizeOutgoingPlay();
    }

    #[Get("/player/play/album/{hash}/track/{index}", "player.play-album-track")]
    public function playAlbumTrack(string $hash, int $index)
    {
        $album = $this->music->getAlbumByHash($hash);
        if (!$album) {
            return;
        }
        $tracks = $this->music->albumTracks((int) $album->id);
        if (empty($tracks[$index])) {
            return;
        }
        $this->playlist->setPlaylist($tracks, $index, source: "album");
        $this->queueReplaced();
        return $this->play($tracks[$index]['hash']);
    }

    #[Get("/player/play/artist/{hash}", "player.play-artist")]
    public function playArtist(string $hash)
    {
        $tracks = $this->music->artistTracks($hash);
        if (empty($tracks)) {
            return;
        }
        $this->playlist->setPlaylist($tracks, source: "artist");
        $this->queueReplaced();
        return $this->play($tracks[0]['hash']);
    }

    #[Get("/player/play/artist/{hash}/radio", "player.play-artist-radio")]
    public function playArtistRadio(string $hash)
    {
        $tracks = $this->mix->build($hash);
        if (empty($tracks)) {
            return;
        }
        $this->playlist->setPlaylist($tracks, source: "artist-radio:$hash");
        $this->queueReplaced();
        return $this->play($tracks[0]['hash']);
    }

    #[Get("/player/play/playlist/{index}", "player.play-playlist-track")]
    public function playPlaylistTrack(int $index)
    {
        $playlist = $this->playlist->getPlaylist();
        if (!empty($playlist['tracks'][$index])) {
            $this->playlist->setPlaylistIndex($index);
            $this->hxTrigger("nowPlaying");
            return $this->play($playlist['tracks'][$index]['hash']);
        }
    }

    #[Get("/player/play/search", "player.play-search")]
    public function playSearch()
    {
        $search = $this->search->getSearch();
        if (!empty($search['tracks'])) {
            $this->playlist->setPlaylist($search['tracks'], source: "search");
            $this->queueReplaced();
            return $this->play($search['tracks'][0]['hash']);
        }
    }

    #[Get("/player/play/station/{slug}", "player.play-station")]
    public function playStation(string $slug)
    {
        $tracks = $this->stations->build($slug);
        if (empty($tracks)) {
            return;
        }
        $this->playlist->setPlaylist($tracks, source: "station:$slug");
        $this->queueReplaced();
        return $this->play($tracks[0]['hash']);
    }

    #[Get("/player/play/collection/{hash}", "player.play-collection")]
    public function playCollection(string $hash)
    {
        return $this->playCollectionTrack($hash, 0);
    }

    #[Get("/player/play/collection/{hash}/track/{index}", "player.play-collection-track")]
    public function playCollectionTrack(string $hash, int $index)
    {
        // The virtual "Liked" playlist has no playlists row — it queues with
        // source "liked" so like/unlike keeps mirroring into the queue.
        if ($hash === PlaylistsService::LIKED_HASH) {
            $tracks = $this->music->likedTracks();
            $source = "liked";
        } elseif ($hash === PlaylistsService::RANDOM_HASH) {
            // Same seeded hand the playlist page rendered, so index N here is
            // the row the client actually clicked.
            $tracks = $this->music->randomTracks(
                $this->playlists->randomSeed(),
                PlaylistsService::RANDOM_SIZE,
            );
            $source = "random";
        } else {
            $playlist = $this->playlists->getPlaylistByHash($hash);
            if (!$playlist) {
                return;
            }
            $tracks = $this->music->playlistTracks((int) $playlist->id);
            $source = "playlist:$hash";
        }

        if (empty($tracks[$index])) {
            return;
        }
        $this->playlist->setPlaylist($tracks, $index, source: $source);
        $this->queueReplaced();
        return $this->play($tracks[$index]['hash']);
    }

    #[Get("/player/play/album/{hash}", "player.play-album")]
    public function playAlbum(string $hash)
    {
        $album = $this->music->getAlbumByHash($hash);
        if (!$album) {
            return;
        }
        $tracks = $this->music->albumTracks((int) $album->id);
        if (empty($tracks)) {
            return;
        }
        $this->playlist->setPlaylist($tracks, source: "album");
        $this->queueReplaced();
        return $this->play($tracks[0]['hash']);
    }

    #[Get("/player/play/radio/{hash}", "player.play-radio")]
    public function playRadio(string $hash): void
    {
        $station = $this->radio->getStation($hash);
        if (!$station) {
            return;
        }

        // Switching to radio abandons whatever track was playing.
        $this->finalizeOutgoingPlay();
        // Radio is a single live stream — clear the playlist so prev/next
        // disable, and play the external HLS/stream URL directly (no transcode).
        $this->playlist->clearPlaylist();
        $this->player->setPlayer([
            'type'      => 'radio',
            'hash'      => $station->hash,
            'title'  => $station->name,
            'artist' => trim(implode(', ', array_filter([$station->city, $station->province]))) ?: ($station->country ?? ''),
            'album'  => "Soprano Radio",
            'cover'  => $station->cover,
            'src'    => $station->src,
        ]);
        // Clearing the playlist makes the rendered queue stale — refresh it so
        // clicking a leftover row doesn't hit the now-empty playlist (no-op).
        $this->hxTrigger("loadPlayer, nowPlaying, playlistQueue, playlistActions");
    }

    #[Get("/player/play/podcast/{hash}/episode/{episodeId}", "player.play-podcast-episode")]
    public function playPodcastEpisode(string $hash, string $episodeId): void
    {
        $episode = $this->podcasts->getEpisode($episodeId);
        if (!$episode || empty($episode['audio'])) {
            return;
        }

        // Switching to a podcast abandons whatever track was playing.
        $this->finalizeOutgoingPlay();
        // A podcast episode is a single finite MP3 — clear the playlist (prev/
        // next disable) and hand the external audio URL straight to the player.
        // Unlike radio it's seekable, so type 'podcast' keeps the progress bar.
        $this->playlist->clearPlaylist();
        $this->player->setPlayer([
            'type'      => 'podcast',
            'hash'      => $hash,
            'episode_id' => $episode['id'],
            'resume_ms'  => $this->podcasts->touchProgress($episode),
            'title'      => $episode['title'],
            'artist'     => $episode['podcast_title'],
            'album'      => 'Soprano Podcasts',
            'cover'      => $episode['image'],
            'src'        => $episode['audio'],
        ]);
        // Clearing the playlist makes the rendered queue stale — refresh it so
        // clicking a leftover row doesn't hit the now-empty playlist (no-op).
        $this->hxTrigger("loadPlayer, nowPlaying, playlistQueue, playlistActions");
    }

    #[Get("/player/play/podcast-surprise", "player.play-podcast-surprise")]
    public function playPodcastSurprise(): void
    {
        $episode = $this->podcasts->randomEpisode();
        if (!$episode || empty($episode['audio'])) {
            return;
        }

        $this->finalizeOutgoingPlay();
        $this->playlist->clearPlaylist();
        $this->player->setPlayer([
            'type'      => 'podcast',
            'hash'      => $episode['podcast_hash'],
            'episode_id' => $episode['id'],
            'resume_ms'  => $this->podcasts->touchProgress($episode),
            'title'      => $episode['title'],
            'artist'     => $episode['podcast_title'],
            'album'      => "Soprano Podcasts",
            'cover'      => $episode['image'],
            'src'        => $episode['audio'],
        ]);
        // Clearing the playlist makes the rendered queue stale — refresh it so
        // clicking a leftover row doesn't hit the now-empty playlist (no-op).
        $this->hxTrigger("loadPlayer, nowPlaying, playlistQueue, playlistActions");
    }

    #[Get("/player/play/{hash}", "player.play")]
    public function play(string $hash): void
    {
        $track = $this->music->getTrack($hash);
        if (!$track) {
            // The other way playback dies quietly: an advance already moved
            // the queue index, but no player swaps in because the library no
            // longer has this hash.
            logger()->channel('soprano')->warning('Play requested for unknown track', [
                'hash'   => $hash,
                'source' => $this->playlist->getSource(),
                'client' => client()?->id,
            ]);
            return;
        }

        $album  = $track->album();
        $artist = $track->trackArtist();
        $meta   = $track->meta();
        $src    = uri("player.stream", $track->hash);

        $this->finalizeOutgoingPlay();
        // One-off plays (track row, search result, wrapped) tag themselves
        // with ?src= since they don't come from the queue; queue-driven
        // plays inherit the queue's source.
        $one_off = (string) (request()->get->get("src") ?? '');
        $source  = $one_off ?: $this->playlist->getSource();
        // A one-off still has to become the queue's current track: the
        // advance at the end of it reads the queue, so leaving the queue
        // pointing elsewhere either stops playback (empty queue) or jumps
        // to something unrelated (stale index).
        if ($one_off !== '' && ($row = $this->music->trackRow($hash))) {
            $this->playlist->setCurrentTrack($row);
            $this->hxTrigger("playlistQueue, playlistActions");
        }
        $this->music->trackPlay($track->id, $source);
        $this->player->setPlayer([
            'hash'      => $track->hash,
            'album_hash'  => $album?->hash  ?? '#',
            'artist_hash' => $artist?->hash ?? '#',
            'title'       => $meta?->title  ?? '',
            'artist'      => $artist?->name ?? '',
            'album'       => $album?->title ?? '',
            'cover'       => $album?->cover,
            'src'         => $src,
            // ReplayGain the client should apply (WebAudio). Transcoded tracks
            // get 0 — their gain is baked into the cached Opus file. Asked with
            // the client's data-saver flag, because that decides whether a lossy
            // source is served raw (apply gain) or as Opus (already baked in),
            // and with their transcode flag, which turns the encode off outright.
            'gain'        => $this->transcode->servesOpus(
                $track,
                (bool) client()->data_saver,
                (bool) (client()->transcode ?? true),
            )
                ? 0.0
                : $this->replaygain->trackGainDb($track),
            // Crossfade: the client's toggle, gated on there being a next track
            // to fade into (so the final track ends cleanly with no crossfade).
            'crossfade' => (bool) client()->crossfade,
            'has_next'    => $this->playlist->hasNextAuto(),
        ]);
        $this->hxTrigger("loadPlayer, recentlyPlayed, topPlayed, topPlayedMonth, rediscover, topTracks, searchResults");
    }

    #[Get("/player/podcast-progress", "player.podcast-progress")]
    public function podcastProgress(): void
    {
        // Fired by player.js every ~15s during podcast playback (and on
        // pause/pagehide) so the episode can resume where it left off.
        $get = request()->get;
        $episode = (string) ($get->get("episode") ?? '');
        $pos = $get->get("pos");
        if ($episode === '' || $pos === null || $pos === '') {
            return;
        }
        $dur = $get->get("dur");
        $this->podcasts->saveProgress(
            $episode,
            (int) $pos,
            ($dur !== null && $dur !== '') ? (int) $dur : null,
        );
    }

    #[Get("/player/progress", "player.progress")]
    public function progress(): void
    {
        // Fired by player.js on pagehide (fetch keepalive) so a play that
        // never reaches another track-change request still gets closed out.
        $this->finalizeOutgoingPlay();
    }

    /**
     * Diagnostic for "playback just stopped when it shouldn't have". With
     * repeat on, an auto advance always has somewhere to go, so a refusal
     * means the queue itself is gone by the time the request lands — dump
     * its whole shape (plus the outgoing track, which says whether this is
     * even the queue that was playing) so one repro identifies the cause.
     */
    /**
     * A whole-queue swap — album, playlist, artist, artist radio, station, or
     * "play all" on a search. Repaints the queue views and reveals the panel:
     * the queue you had is gone, and on mobile that happens entirely off-screen
     * behind a panel you have to know to open.
     *
     * Deliberately NOT fired for a one-off play (a rail card, a search result),
     * which only splices itself in via setCurrentTrack(), nor for radio and
     * podcasts, which clear the queue outright and would reveal an empty panel.
     */
    private function queueReplaced(): void
    {
        $this->hxTrigger("queueReplaced, nowPlaying, playlistQueue, playlistActions");
    }

    private function logStalledAdvance(bool $auto): void
    {
        $playlist = $this->playlist->getPlaylist();
        $tracks   = $playlist["tracks"] ?? [];
        $index    = (int) ($playlist["index"] ?? 0);
        $order    = $playlist["order"] ?? null;
        $cur      = (string) (request()->get->get("cur") ?? '');
        $pos      = is_array($order) ? array_search($index, $order, true) : null;

        logger()->channel('soprano')->warning('Queue advance returned no track', [
            'auto'         => $auto,
            'count'        => count($tracks),
            'index'        => $index,
            'shuffle'      => (bool) ($playlist["shuffle"] ?? false),
            'repeat'       => $playlist["repeat"] ?? null,
            'source'       => $playlist["source"] ?? null,
            'order_count'  => is_array($order) ? count($order) : null,
            'order_pos'    => $pos === false ? 'not-in-order' : $pos,
            'index_hash'   => $tracks[$index]["hash"] ?? null,
            'outgoing'     => $cur !== '' ? $cur : null,
            // A queue that doesn't contain the track that just finished is a
            // different (stale or clobbered) queue than the one being played.
            'outgoing_queued' => $cur !== '' ? $this->playlist->hasTrack($cur) : null,
            'client'    => client()?->id,
        ]);
    }

    /**
     * Close out the outgoing track's play row. player.js attaches cur (the
     * outgoing track's hash), pos and dur (ms) to every track-change request;
     * auto=1 marks a natural end-of-track advance, which is never a skip.
     */
    private function finalizeOutgoingPlay(): void
    {
        $get = request()->get;
        $cur = (string) ($get->get("cur") ?? '');
        $pos = $get->get("pos");
        if ($cur === '' || $pos === null || $pos === '') {
            return;
        }
        $dur = $get->get("dur");
        $this->music->finalizeTrackPlay(
            $cur,
            (int) $pos,
            ($dur !== null && $dur !== '') ? (int) $dur : null,
            (bool) $get->get("auto"),
        );
    }

    #[Get("/player/stream/{hash}", "player.stream")]
    public function stream(string $hash): StreamResponse
    {
        $track = $this->music->getTrack($hash);

        if ($track && is_file($track->pathname) && is_readable($track->pathname)) {
            // Lossless/large sources are served from the cached Opus transcode
            // (encoded on demand if not warmed yet); everything else streams
            // straight from disk, unless this client is on data saver, which
            // shrinks high-bitrate lossy sources too. A client who has turned
            // transcoding off gets the source file either way.
            $path = $this->transcode->resolve(
                $track,
                (bool) client()->data_saver,
                (bool) (client()->transcode ?? true),
            ) ?? $track->pathname;
            return new StreamResponse($path);
        }

        return $this->pageNotFound();
    }

    /**
     * Client-side playback events, whitelisted and clamped.
     *
     * The failure we're chasing ("it just stops") happens in the browser, on
     * someone else's phone, so a console.warn is written where nobody will
     * ever read it. This is the other end of that: player.js beacons the
     * media element's state here on every event that can end playback, and it
     * lands in soprano-player-<date>.log next to the nginx access log, where
     * one repro can be lined up against the request that served it.
     *
     * GET (not POST) on purpose: it stays CSRF-exempt, so telemetry can't die
     * silently an hour into a listening session when the token rotates — which
     * is exactly the session length where the bug shows up.
     */
    #[Get("/player/client-log", "player.client-log", ["max_requests" => 240, "decay_seconds" => 60])]
    public function clientLog(): Response
    {
        $get   = request()->get;
        $event = (string) ($get->get("e") ?? '');

        // Unknown event names are dropped rather than logged: the endpoint is
        // reachable by anything holding a session cookie, and a diagnostic log
        // is worthless if a third party can write arbitrary lines into it.
        if (!in_array($event, self::CLIENT_EVENTS, true)) {
            return new Response('', 204);
        }

        $int = static fn(string $k, int $max): ?int => ($v = $get->get($k)) === null || $v === ''
            ? null
            : max(0, min($max, (int) $v));

        logger()->channel('soprano-player')->info('client: ' . $event, array_filter([
            'client'    => client()?->id,
            'hash'      => ($h = (string) ($get->get("h") ?? '')) !== '' && preg_match('/^[a-f0-9]{32}$/', $h) ? $h : null,
            'type'      => in_array($t = (string) ($get->get("ty") ?? ''), ['track', 'radio', 'podcast'], true) ? $t : null,
            // Where in the track it happened — a mid-song stop and an
            // end-of-track stall are different bugs with the same symptom.
            'pos_ms'    => $int("t", 86_400_000),
            'dur_ms'    => $int("d", 86_400_000),
            'paused'    => $int("pa", 1),
            // 0-4 / 0-2: whether the element still had buffered audio to play,
            // which separates "the network died" from "something paused us".
            'ready'     => $int("rs", 4),
            'network'   => $int("ns", 2),
            'err_code'  => $int("ec", 4),
            'muted'     => $int("mu", 1),
            'volume'    => $int("vo", 100),
            'visible'   => $int("vis", 1),
            'crossfade' => $int("xf", 1),
            'ctx'       => in_array($c = (string) ($get->get("ctx") ?? ''), ['running', 'suspended', 'closed', 'interrupted'], true) ? $c : null,
            // Set only when our own UI initiated it. Its ABSENCE on a pause is
            // the signal: something outside the page stopped playback.
            'ui'        => $int("ui", 1),
            'note'      => ($n = (string) ($get->get("n") ?? '')) !== '' ? substr(preg_replace('/[^\w .:-]/', '', $n), 0, 120) : null,
            'ip'        => request()->getClientIp(),
            'ua'        => substr((string) (request()->headers->get('User-Agent') ?? ''), 0, 180),
        ], static fn($v) => $v !== null && $v !== ''));

        return new Response('', 204);
    }
}
