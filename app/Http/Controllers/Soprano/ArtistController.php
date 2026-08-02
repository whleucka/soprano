<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{PlayerService, MusicService, PlaylistService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class ArtistController extends Controller
{
    public function __construct(
        private PlayerService $player,
        private MusicService $music,
        private PlaylistService $playlist,
    ) {}

    #[Get("/artist/{hash}", "artist.index")]
    public function artist(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        $stats = $this->music->getArtistStats((int) $artist->id);

        return $this->render("artist/index.html.twig", [
            "artist_hash"  => $artist->hash,
            "artist"       => $artist->name,
            "artist_image" => $artist->image,
            "album_count" => $stats["album_count"],
            "track_count" => $stats["track_count"],
            "runtime"     => $stats["runtime"],
        ]);
    }

    #[Get("/artist/{hash}/actions", "artist.actions")]
    public function actions(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        return $this->render("artist/actions.html.twig", [
            "artist_hash" => $artist->hash,
        ]);
    }

    /**
     * The artist's collection heart: filled only once every track of theirs in
     * the library is liked. Also reloads on single-track likes, so the top
     * tracks list and this never disagree.
     */
    #[Get("/artist/{hash}/like", "artist.like")]
    public function like(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        return $this->render("components/like-btn.html.twig", [
            "liked"    => $this->music->artistFullyLiked($hash),
            "like_uri" => uri("artist.like-toggle", $hash),
        ]);
    }

    #[Get("/artist/{hash}/like-toggle", "artist.like-toggle")]
    public function likeToggle(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        $tracks = $this->music->artistTracks($hash);
        $liked  = $this->music->toggleTracksLike(array_column($tracks, "hash"));

        if ($this->playlist->syncLiked($tracks, $liked)) {
            $this->hxTrigger("playlistQueue, playlistActions");
        }
        $this->hxTrigger("topTracks, trackLike, recentlyLiked");

        return $this->like($hash);
    }

    #[Get("/artist/{hash}/discography", "artist.discography")]
    public function discography(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        return $this->render("artist/discography.html.twig", [
            "tracks" => $this->music->discography((int) $artist->id),
        ]);
    }

    #[Get("/artist/{hash}/top-tracks", "artist.top-tracks")]
    public function topTracks(string $hash): string
    {
        $artist = $this->music->getArtistByHash($hash);
        if (!$artist) {
            return $this->pageNotFound();
        }

        return $this->render("artist/top-tracks.html.twig", [
            "player" => $this->player->getPlayer(),
            "tracks" => $this->music->topTracksByArtist((int) $artist->id),
        ]);
    }
}

