<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{MusicService, PlaylistService, PlayerService, SearchService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;
use App\Http\StreamResponse;

class PlayerController extends Controller
{
    public function __construct(
        private PlayerService $player,
        private SearchService $search,
        private PlaylistService $playlist,
        private MusicService $music,
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
        $playlist = $this->playlist->getPlaylist();
        $next = $this->playlist->changePlaylistTrack($playlist, true);
        if ($next) {
            $this->hxTrigger("nowPlaying");
            return $this->play($next['hash']);
        }
    }

    #[Get("/player/prev-track", "player.prev-track")]
    public function prevTrack()
    {
        $playlist = $this->playlist->getPlaylist();
        $prev = $this->playlist->changePlaylistTrack($playlist, false);
        if ($prev) {
            $this->hxTrigger("nowPlaying");
            return $this->play($prev['hash']);
        }
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
        $playlist = $this->playlist->getPlaylist();
        $this->playlist->setPlaylist($tracks, $index, $playlist["shuffle"]);
        $this->hxTrigger("loadPlaylist");
        return $this->play($tracks[$index]['hash']);
    }

    #[Get("/player/play/playlist/{index}", "player.play-playlist-track")]
    public function playPlaylistTrack(int $index)
    {
        $playlist = $this->playlist->getPlaylist();
        if (!empty($playlist['tracks'][$index])) {
            $this->playlist->setPlaylist($playlist['tracks'], $index, $playlist["shuffle"]);
            $this->hxTrigger("nowPlaying");
            return $this->play($playlist['tracks'][$index]['hash']);
        }
    }

    #[Get("/player/play/search", "player.play-search")]
    public function playSearch()
    {
        $playlist = $this->playlist->getPlaylist();
        $search = $this->search->getSearch();
        if (!empty($search['tracks'])) {
            $this->playlist->setPlaylist($search['tracks'], 0, $playlist["shuffle"]);
            $this->hxTrigger("loadPlaylist");
            return $this->play($search['tracks'][0]['hash']);
        }
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
        $this->playlist->setPlaylist($tracks);
        $this->hxTrigger("loadPlaylist");
        return $this->play($tracks[0]['hash']);
    }

    #[Get("/player/play/{hash}", "player.play")]
    public function play(string $hash): void
    {
        $track = $this->music->getTrack($hash);
        if (!$track) {
            return;
        }

        $album  = $track->album();
        $artist = $track->artist();
        $meta   = $track->meta();
        $src    = uri("player.stream", $track->hash);

        $this->music->trackPlay((int) $track->id, client()?->id);
        $this->player->setPlayer([
            'hash'        => $track->hash,
            'album_hash'  => $album?->hash  ?? '#',
            'artist_hash' => $artist?->hash ?? '#',
            'title'       => $meta?->title  ?? '',
            'artist'      => $artist?->name ?? '',
            'album'       => $album?->title ?? '',
            'cover'       => $album?->cover,
            'src'         => $src,
        ]);
        $this->hxTrigger("loadPlayer, recentlyPlayed, topPlayed, topTracks");
    }

    #[Get("/player/stream/{hash}", "player.stream")]
    public function stream(string $hash): StreamResponse
    {
        $track = $this->music->getTrack($hash);

        if ($track && is_file($track->pathname) && is_readable($track->pathname)) {
            return new StreamResponse($track->pathname);
        }

        return $this->pageNotFound();
    }
}
