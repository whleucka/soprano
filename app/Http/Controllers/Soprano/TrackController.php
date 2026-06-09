<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{CoverArtService, MusicService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class TrackController extends Controller
{
    public function __construct(
        private MusicService $music, 
        private CoverArtService $coverArt, 
    ) {}

    #[Get("/track/{hash}", "track.index")]
    public function index(string $hash): string
    {
        $track = $this->music->getTrack($hash);

        if (!$track) {
            return $this->pageNotFound();
        }

        $meta = $track->meta();
        $artist = $track->artist();
        $album = $track->album();

        return $this->render("tracks/index.html.twig", [
            "hash" => $track->hash,
            "album_hash" => $album->hash,
            "artist_hash" => $artist->hash,
            "cover" => $album->cover,
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
        $this->music->toggleTrackLike($hash);
        $this->hxTrigger("like-$hash, recentlyLiked");
        return $this->like($hash);
    }
}
