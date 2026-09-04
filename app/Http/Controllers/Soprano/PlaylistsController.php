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
        return $this->render("playlists/index.html.twig");
    }

    #[Get("/playlists/load", "playlists.load")]
    public function load(): string
    {
        return $this->render("playlists/load.html.twig", [
            "playlists" => $this->playlists->getPlaylists(),
        ]);
    }

    #[Get("/playlists/{hash}", "playlists.show")]
    public function show(string $hash): string
    {
        if ($virtual = $this->virtualPlaylist($hash)) {
            // Opening the page deals a fresh hand; the seed then holds still
            // so the rows rendered below are the ones a click plays.
            if ($this->isRandom($hash)) {
                $this->playlists->rerollRandomSeed();
            }

            return $this->render("playlists/show.html.twig", [
                "hash"     => $virtual['hash'],
                "name"     => $virtual['name'],
                "icon"     => $virtual['icon'],
                "count"    => $virtual['track_count'],
                "cover"    => $virtual['cover'],
                // An icon-faced playlist has no art to tint the hero with.
                "dominant" => null,
            ]);
        }

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
        if ($virtual = $this->virtualPlaylist($hash)) {
            return $this->render("playlists/actions.html.twig", [
                "hash"       => $virtual['hash'],
                "name"       => $virtual['name'],
                "is_virtual" => true,
            ]);
        }

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
        if ($this->isVirtual($hash)) {
            return $this->render("playlists/tracks.html.twig", [
                "hash"       => $hash,
                "is_virtual" => true,
                "is_liked"   => $this->isLiked($hash),
                "player"     => state()->player,
                "tracks"     => $this->virtualTracks($hash),
            ]);
        }

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
        $this->hxTrigger("playlistTracks-$hash, loadPlaylists, loadSidebar, loadTop");
        return $this->renderModal($src, $ref);
    }

    #[Get("/playlists/{hash}/remove/{trackHash}", "playlists.remove-track")]
    public function removeTrack(string $hash, string $trackHash): void
    {
        $this->playlists->removeTrack($hash, $trackHash);
        $this->hxTrigger("playlistTracks-$hash, loadPlaylists, loadSidebar, loadTop");
    }

    #[Post("/playlists", "playlists.create")]
    public function create(): string
    {
        $valid = $this->validate(["name" => ["required", "max_length:255"]]);
        $src   = request()->request->get("src");
        $ref   = request()->request->get("ref");

        if ($valid) {
            $playlist = $this->playlists->createPlaylist($valid->name);
            if ($playlist) {
                $this->playlists->addSelection($playlist->hash, $this->resolveSelection($src, $ref));
            }
            $this->hxTrigger("loadSidebar, loadPlaylists, loadTop");
        }

        return $this->renderModal($src, $ref);
    }

    /**
     * The rename form, pre-filled with the current name. Virtual playlists
     * ("Liked", "Random") have no row to rename, so they never get here — the
     * actions bar hides the button and this guard falls through to a 404.
     */
    #[Get("/playlists/{hash}/rename", "playlists.rename-form")]
    public function renameForm(string $hash): string
    {
        $playlist = $this->playlists->getPlaylistByHash($hash);
        if (!$playlist) {
            return $this->pageNotFound();
        }

        return $this->render("playlists/modal-rename.html.twig", [
            "hash" => $playlist->hash,
            "name" => $playlist->name,
        ]);
    }

    #[Post("/playlists/{hash}/rename", "playlists.rename")]
    public function rename(string $hash): string
    {
        $playlist = $this->playlists->getPlaylistByHash($hash);
        if (!$playlist) {
            return $this->pageNotFound();
        }

        $valid = $this->validate(["name" => ["required", "max_length:255"]]);
        $name  = $valid ? $this->playlists->renamePlaylist($hash, $valid->name) : null;

        if ($name === null) {
            // Whitespace-only clears `required` but is not a name; the service
            // rejects it, so say so rather than re-rendering a clean form.
            if ($valid) {
                $this->addValidationError("name", "Required field");
            }

            return $this->render("playlists/modal-rename.html.twig", [
                "hash" => $playlist->hash,
                "name" => (string) request()->request->get("name"),
            ]);
        }

        // playlistRenamed closes the modal (app.js); the rest repaint the name
        // in the sidebar, the index grid and the delete button's confirm text.
        $this->hxTrigger("playlistRenamed, playlistActions, loadSidebar, loadPlaylists, loadTop");

        return $this->render("playlists/modal-rename.html.twig", [
            "hash"    => $playlist->hash,
            "name"    => $name,
            // Swaps the hero heading, which is rendered by the page around the
            // modal and so is not otherwise this response's to update.
            "renamed" => true,
        ]);
    }

    #[Get("/playlists/{hash}/delete", "playlists.delete")]
    public function delete(string $hash): void
    {
        $this->playlists->deletePlaylist($hash);
        $this->hxTrigger("loadSidebar, loadPlaylists, loadTop");
        // HX-Location does a client-side htmx navigation (swap #view), unlike
        // HX-Redirect which forces a full page reload.
        $this->setHeader("HX-Location", json_encode([
            "path"   => uri("playlists.index"),
            "target" => "#view",
            "select" => "#view",
            "swap"   => "outerHTML",
        ]));
    }

    /** The virtual "Liked" playlist is track_likes wearing a playlist's clothes. */
    private function isLiked(string $hash): bool
    {
        return $hash === PlaylistsService::LIKED_HASH;
    }

    /** The virtual "Random" playlist is a seeded shuffle of the library. */
    private function isRandom(string $hash): bool
    {
        return $hash === PlaylistsService::RANDOM_HASH;
    }

    private function isVirtual(string $hash): bool
    {
        return $this->isLiked($hash) || $this->isRandom($hash);
    }

    /**
     * The row for a virtual playlist, or null for a real one. Both virtuals
     * render through the playlist views with no `playlists` row behind them.
     */
    private function virtualPlaylist(string $hash): ?array
    {
        return match (true) {
            $this->isLiked($hash)  => $this->playlists->likedPlaylist(),
            $this->isRandom($hash) => $this->playlists->randomPlaylist(),
            default                => null,
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function virtualTracks(string $hash): array
    {
        return $this->isLiked($hash)
            ? $this->music->likedTracks()
            : $this->music->randomTracks(
                $this->playlists->randomSeed(),
                PlaylistsService::RANDOM_SIZE,
            );
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
