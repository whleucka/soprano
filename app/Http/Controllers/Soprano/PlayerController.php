<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{MusicService, PlaylistService, PlayerService, RadioService, SearchService, TranscodeService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;
use App\Http\StreamResponse;

#[Group(middleware: ["client"])]
class PlayerController extends Controller
{
    public function __construct(
        private PlayerService $player,
        private SearchService $search,
        private PlaylistService $playlist,
        private MusicService $music,
        private RadioService $radio,
        private TranscodeService $transcode,
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
        $next = $this->playlist->changePlaylistTrack(true);
        if ($next) {
            $this->hxTrigger("nowPlaying");
            return $this->play($next['hash']);
        }
    }

    #[Get("/player/prev-track", "player.prev-track")]
    public function prevTrack()
    {
        $prev = $this->playlist->changePlaylistTrack(false);
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
        $this->playlist->setPlaylist($tracks, $index);
        $this->hxTrigger("nowPlaying, playlistQueue, playlistActions");
        return $this->play($tracks[$index]['hash']);
    }

    #[Get("/player/play/artist/{hash}", "player.play-artist")]
    public function playArtist(string $hash)
    {
        $tracks = $this->music->artistTracks($hash);
        if (empty($tracks)) {
            return;
        }
        $this->playlist->setPlaylist($tracks);
        $this->hxTrigger("nowPlaying, playlistQueue, playlistActions");
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
            $this->playlist->setPlaylist($search['tracks']);
            $this->hxTrigger("nowPlaying, playlistQueue, playlistActions");
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
        $this->hxTrigger("nowPlaying, playlistQueue, playlistActions");
        return $this->play($tracks[0]['hash']);
    }

    #[Get("/player/play/radio/{hash}", "player.play-radio")]
    public function playRadio(string $hash): void
    {
        $station = $this->radio->getStation($hash);
        if (!$station) {
            return;
        }

        // Radio is a single live stream — clear the playlist so prev/next
        // disable, and play the external HLS/stream URL directly (no transcode).
        $this->playlist->clearPlaylist();
        $this->player->setPlayer([
            'type'   => 'radio',
            'hash'   => $station->hash,
            'title'  => $station->name,
            'artist' => trim(implode(', ', array_filter([$station->city, $station->province]))) ?: ($station->country ?? ''),
            'album'  => $station->name,
            'cover'  => $station->cover,
            'src'    => $station->src,
        ]);
        $this->hxTrigger("loadPlayer, playlistQueue, playlistActions");
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

        $this->music->trackPlay($track->id);
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
        $this->hxTrigger("loadPlayer, recentlyPlayed, topPlayed, topPlayedMonth, rediscover, topTracks");
    }

    #[Get("/player/stream/{hash}", "player.stream")]
    public function stream(string $hash): StreamResponse
    {
        $track = $this->music->getTrack($hash);

        if ($track && is_file($track->pathname) && is_readable($track->pathname)) {
            // Lossless/large sources are served from the cached Opus transcode
            // (encoded on demand if not warmed yet); everything else streams
            // straight from disk.
            $path = $this->transcode->resolve($track) ?? $track->pathname;
            return new StreamResponse($path);
        }

        return $this->pageNotFound();
    }
}
