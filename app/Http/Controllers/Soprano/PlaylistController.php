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
        $index = $playlist["index"];
        // Queue rows are a session snapshot; their liked flag goes stale the
        // moment a like is toggled elsewhere.
        $playlist["tracks"] = $this->music->refreshLikedState($playlist["tracks"] ?? []);
        return $this->render("playlist/queue.html.twig", [
            "playlist" => $playlist,
            "current" => $playlist["tracks"][$index] ?? false
        ]);
    }

    #[Get("/playlist/queue/clear", "playlist.queue-clear")]
    public function queueClear(): void
    {
        $this->playlist->clearPlaylist();
        $this->hxTrigger("loadPlaylist");
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
        // next/prev reach the freshly queued track (same as liked()).
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

    #[Get("/playlist/random", "playlist.random")]
    public function random()
    {
        $tracks = $this->music->randomTracks();
        if (empty($tracks)) {
            return;
        }
        $this->playlist->setPlaylist($tracks, source: "random");
        $this->hxTrigger("playlistActions, playlistQueue");
    }

    #[Get("/playlist/liked", "playlist.liked")]
    public function liked()
    {
        $current_playlist = $this->playlist->getPlaylist()['tracks'] ?? [];
        $tracks = $this->music->likedTracks();
        $this->playlist->setPlaylist($tracks, source: "liked");
        $this->hxTrigger("playlistActions, playlistQueue");
        if (!$current_playlist) {
            // In this case, we want to load the player so that we 
            // can use next/prev for the liked queue
            $this->hxTrigger("loadPlayer");
        }
    }
}
