<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{CoverArtService, MusicService, PlaylistService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class PlaylistController extends Controller
{
    private const DEFAULT_CURRENT = [
        "hash"        => "#",
        "album_hash"  => "#",
        "artist_hash" => "#",
        "artist"      => "N/A",
        "cover"       => "/images/no-album-art.png",
        "album"       => "N/A",
        "title"       => "N/A",
    ];

    public function __construct(
        private PlaylistService $playlist,
        private MusicService $music,
        private CoverArtService $coverArt,
    ) {}

    #[Get("/playlist", "playlist.index")]
    public function index(): string
    {
        return $this->render("playlist/index.html.twig");
    }

    #[Get("/playlist/now-playing", "playlist.now-playing")]
    public function nowPlaying(): string
    {
        $playlist = $this->playlist->getPlaylist();
        $index = $playlist["index"];
        return $this->render("playlist/now-playing.html.twig", [
            "dominant" => (isset($playlist["tracks"][$index])
                ? $this->coverArt->hexToRgb($playlist["tracks"][$index]["dominant_color"] ?? null)
                : null) ?? [18,18,18],
            "current" => $playlist["tracks"][$index] ?? self::DEFAULT_CURRENT
        ]);
    }

    #[Get("/playlist/actions", "playlist.actions")]
    public function actions(): string
    {
        return $this->render("playlist/actions.html.twig", [
            "playlist" => $this->playlist->getPlaylist(),
        ]);
    }

    #[Get("/playlist/queue", "playlist.queue")]
    public function queue(): string
    {
        $playlist = $this->playlist->getPlaylist();
        // Queue rows are a session snapshot; their liked flag goes stale the
        // moment a like is toggled elsewhere. refreshLikedState keeps the keys,
        // so playlist.index still addresses the same row the template renders.
        $playlist["tracks"] = $this->music->refreshLikedState($playlist["tracks"] ?? []);
        return $this->render("playlist/queue.html.twig", [
            "playlist" => $playlist,
        ]);
    }

    #[Get("/playlist/queue/clear", "playlist.queue-clear")]
    public function queueClear(): void
    {
        $this->playlist->clearPlaylist();
        // Repaint the three panes in place rather than rebuilding the whole
        // panel fragment: a rebuild re-inserts the queue skeleton, so clearing
        // flashed ten placeholder rows on its way to "Queue is empty". Same
        // triggers queueRemove() uses, plus nowPlaying — the header still names
        // the track that was just cleared out from under it.
        $this->hxTrigger("nowPlaying, playlistQueue, playlistActions");
    }

    #[Get("/playlist/queue-next/{hash}", "playlist.queue-next")]
    public function queueNext(string $hash): void
    {
        $this->queueTrack($hash, true);
    }

    #[Get("/playlist/queue-last/{hash}", "playlist.queue-last")]
    public function queueLast(string $hash): void
    {
        $this->queueTrack($hash, false);
    }

    #[Get("/playlist/queue-remove/{hash}", "playlist.queue-remove")]
    public function queueRemove(string $hash): void
    {
        if ($this->playlist->removeTrack($hash)) {
            $this->hxTrigger("playlistQueue, playlistActions");
        }
    }

    private function queueTrack(string $hash, bool $next): void
    {
        $track = $this->music->trackRow($hash);
        if (!$track) {
            return;
        }
        $was_empty = empty($this->playlist->getPlaylist()["tracks"]);
        $this->playlist->queueTrack($track, $next);
        // A previously empty queue has no player hooked up — load it so
        // next/prev reach the freshly queued track.
        $this->hxTrigger("playlistQueue, playlistActions" . ($was_empty ? ", loadPlayer" : ""));
    }

    #[Get("/playlist/shuffle", "playlist.shuffle")]
    public function shuffle()
    {
        $this->playlist->toggleShuffle();
        $this->hxTrigger("playlistActions");
    }

    #[Get("/playlist/repeat", "playlist.repeat")]
    public function repeat()
    {
        $this->playlist->cycleRepeat();
        $this->hxTrigger("playlistActions");
    }
}
