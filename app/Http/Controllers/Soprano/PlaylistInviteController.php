<?php

namespace App\Http\Controllers\Soprano;

use App\Models\Playlist;
use App\Services\Soprano\PlaylistsService;
use Echo\Framework\Http\{Controller, Response};
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\{Get, Post};

/**
 * The landing side of a playlist invite link. The owner makes the link in
 * the collaborators modal and sends it to someone outside the app. Opening it
 * asks whether to join, and accepting makes the visitor a collaborator. There
 * is no user search anywhere in the app, so this link is the only way
 * someone joins a playlist, and accepting it is how they agree to.
 *
 * A signed-out visitor is bounced to sign-in by the client middleware, which
 * remembers this path and comes back to it afterwards.
 */
#[Group(middleware: ["client"])]
class PlaylistInviteController extends Controller
{
    public function __construct(private PlaylistsService $playlists) {}

    #[Get("/invite/{token}", "playlists.invite")]
    public function show(string $token): string|Response
    {
        $playlist = $this->playlists->findByInviteToken($token);
        if (!$playlist) {
            return $this->render("playlists/invite.html.twig", ["valid" => false]);
        }

        // Already in: the owner opening their own link, or someone clicking
        // it a second time. Nothing to accept, so go straight there.
        if ($this->alreadyIn($playlist)) {
            return redirect(uri("playlists.show", $playlist->hash));
        }

        return $this->render("playlists/invite.html.twig", [
            "valid"  => true,
            "token"  => $token,
            "name"   => $playlist->name,
            "owner"  => $this->playlists->ownerName($playlist),
            "count"  => $this->playlists->trackCount($playlist),
        ]);
    }

    #[Post("/invite/{token}", "playlists.invite-accept")]
    public function accept(string $token): string|Response
    {
        $playlist = $this->playlists->findByInviteToken($token);
        if (!$playlist) {
            // Revoked between opening the page and pressing Join.
            return $this->render("playlists/invite.html.twig", ["valid" => false]);
        }

        $this->playlists->join($playlist);

        return redirect(uri("playlists.show", $playlist->hash));
    }

    private function alreadyIn(Playlist $playlist): bool
    {
        return $this->playlists->isOwner($playlist) || $this->playlists->isMember($playlist);
    }
}
