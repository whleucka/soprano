<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{CoverArtService, MusicService, PlaylistsService, SearchService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\{Get, Post};

/**
 * Persistent, per-client playlists. Note: the singular `playlist` routes (in
 * PlaylistController) are the now-playing queue — unrelated. Loading a saved
 * playlist into the queue lives in PlayerController::playCollection.
 */
#[Group(middleware: ["client"])]
class PlaylistsController extends Controller
{
    public function __construct(
        private PlaylistsService $playlists,
        private MusicService $music,
        private CoverArtService $coverArt,
        private SearchService $search,
    ) {}

    #[Get("/playlists", "playlists.index")]
    public function index(): string
    {
        return $this->render("playlists/index.html.twig", [
            "playlists" => $this->playlists->getPlaylists(),
        ]);
    }

    #[Get("/playlists/{hash}", "playlists.show")]
    public function show(string $hash): string
    {
        $playlist = $this->playlists->getPlaylistByHash($hash);
        if (!$playlist) {
            return $this->pageNotFound();
        }

        $tracks = $this->music->playlistTracks((int) $playlist->id);
        $first  = $tracks[0] ?? null;

        return $this->render("playlists/show.html.twig", [
            "hash"     => $playlist->hash,
            "name"     => $playlist->name,
            "count"    => count($tracks),
            "cover"    => $first['cover'] ?? '/images/no-album-art.png',
            "dominant" => $this->coverArt->hexToRgb($first['dominant_color'] ?? null),
        ]);
    }

    #[Get("/playlists/{hash}/actions", "playlists.actions")]
    public function actions(string $hash): string
    {
        $playlist = $this->playlists->getPlaylistByHash($hash);
        if (!$playlist) {
            return $this->pageNotFound();
        }

        return $this->render("playlists/actions.html.twig", [
            "hash" => $playlist->hash,
            "name" => $playlist->name,
        ]);
    }

    #[Get("/playlists/{hash}/tracks", "playlists.tracks")]
    public function tracks(string $hash): string
    {
        $playlist = $this->playlists->getPlaylistByHash($hash);
        if (!$playlist) {
            return $this->pageNotFound();
        }

        return $this->render("playlists/tracks.html.twig", [
            "hash"   => $playlist->hash,
            "player" => state()->player,
            "tracks" => $this->music->playlistTracks((int) $playlist->id),
        ]);
    }

    #[Get("/playlists/modal", "playlists.modal")]
    public function modal(): string
    {
        return $this->renderModal(request()->get->get("src"), request()->get->get("ref"));
    }

    #[Get("/playlists/{hash}/toggle", "playlists.toggle")]
    public function toggle(string $hash): string
    {
        $src = request()->get->get("src");
        $ref = request()->get->get("ref");
        $this->playlists->toggleSelection($hash, $this->resolveSelection($src, $ref));
        $this->hxTrigger("playlistTracks-$hash, loadSidebar");
        return $this->renderModal($src, $ref);
    }

    #[Get("/playlists/{hash}/remove/{trackHash}", "playlists.remove-track")]
    public function removeTrack(string $hash, string $trackHash): void
    {
        $this->playlists->removeTrack($hash, $trackHash);
        $this->hxTrigger("playlistTracks-$hash, loadSidebar");
    }

    #[Post("/playlists", "playlists.create")]
    public function create(): string
    {
        $valid = $this->validate(["name" => ["required"]]);
        $src   = request()->request->get("src");
        $ref   = request()->request->get("ref");

        if ($valid) {
            $playlist = $this->playlists->createPlaylist($valid->name);
            if ($playlist) {
                $this->playlists->addSelection($playlist->hash, $this->resolveSelection($src, $ref));
            }
            $this->hxTrigger("loadSidebar");
        }

        return $this->renderModal($src, $ref);
    }

    #[Get("/playlists/{hash}/delete", "playlists.delete")]
    public function delete(string $hash): void
    {
        $this->playlists->deletePlaylist($hash);
        $this->hxTrigger("loadSidebar");
        // HX-Location does a client-side htmx navigation (swap #view), unlike
        // HX-Redirect which forces a full page reload.
        $this->setHeader("HX-Location", json_encode([
            "path"   => uri("playlists.index"),
            "target" => "#view",
            "select" => "#view",
            "swap"   => "outerHTML",
        ]));
    }

    private function renderModal(?string $src, ?string $ref): string
    {
        $hashes = $this->resolveSelection($src, $ref);

        return $this->render("playlists/modal-picker.html.twig", [
            "src"       => $src,
            "ref"       => $ref,
            "count"     => count($hashes),
            "playlists" => $this->playlists->playlistsForSelection($hashes),
        ]);
    }

    /**
     * Resolve a source descriptor to the set of track hashes it represents.
     * Kept compact (src+ref) so even a 2,500-track search never bloats a URL —
     * the hashes are re-derived server-side on every modal render and toggle.
     *
     * @return string[]
     */
    private function resolveSelection(?string $src, ?string $ref): array
    {
        return match ($src) {
            "album" => ($album = $ref ? $this->music->getAlbumByHash($ref) : null)
                ? array_column($this->music->albumTracks((int) $album->id), "hash")
                : [],
            "artist" => $ref ? array_column($this->music->artistTracks($ref), "hash") : [],
            "search" => array_column($this->search->getSearch()["tracks"] ?? [], "hash"),
            // "track" and any single-hash fallback
            default  => $ref ? [$ref] : [],
        };
    }
}
