<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{CoverArtService, MusicService, PlaylistService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class TrackController extends Controller
{
    public function __construct(
        private MusicService $music,
        private CoverArtService $coverArt,
        private PlaylistService $playlist,
    ) {}

    #[Get("/track/{hash}", "track.index")]
    public function index(string $hash): string
    {
        $track = $this->music->getTrack($hash);

        if (!$track) {
            return $this->pageNotFound();
        }

        $meta = $track->meta();
        $artist = $track->trackArtist();
        $album = $track->album();

        return $this->render("tracks/index.html.twig", [
            "hash" => $track->hash,
            "album_hash" => $album->hash,
            "artist_hash" => $artist->hash,
            "cover" => $album->cover ?? '/images/no-album-art.png',
            "title" => $meta->title,
            "artist" => $artist->name,
            "album" => $album->title,
            "lyrics" => $meta->lyrics,
            "dominant"    => $this->coverArt->hexToRgb($album->dominant_color),
        ]);
    }

    #[Get("/track/{hash}/actions", "track.actions")]
    public function actions(string $hash): string
    {
        $track = $this->music->getTrack($hash);
        if (!$track) {
            return $this->pageNotFound();
        }

        return $this->render("tracks/actions.html.twig", [
            "hash" => $track->hash,
        ]);
    }

    #[Get("/track/{hash}/like", "track.like")]
    public function like(string $hash): string
    {
        $state = $this->music->isTrackLiked($hash);
        return $this->render("components/like-btn.html.twig", [
            "liked" => $state,
            "like_uri" => uri('track.like-toggle', $hash)
        ]);
    }

    #[Get("/track/{hash}/like-toggle", "track.like-toggle")]
    public function likeToggle(string $hash): string
    {
        $liked = $this->music->toggleTrackLike($hash);
        $this->syncLikedQueue($hash, $liked);
        $this->hxTrigger("like-$hash, recentlyLiked");
        return $this->like($hash);
    }

    /**
     * While the queue is the liked playlist, mirror like/unlike into it:
     * a fresh like lands at the end of the queue, an unlike drops out.
     */
    private function syncLikedQueue(string $hash, bool $liked): void
    {
        if (!$this->playlist->isLikedQueue()) {
            return;
        }

        if ($liked) {
            $track = $this->music->trackRow($hash);
            if (!$track || $this->playlist->hasTrack($hash)) {
                return;
            }
            $this->playlist->queueTrack($track);
            // Queueing into an emptied-out liked queue resets the source.
            $this->playlist->setSource("liked");
        } elseif (!$this->playlist->removeTrack($hash)) {
            return;
        }

        $this->hxTrigger("playlistQueue, playlistActions");
    }
}
