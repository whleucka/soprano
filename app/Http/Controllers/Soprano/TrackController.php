<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\MusicService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class TrackController extends Controller
{
    public function __construct(
        private MusicService $music, 
    ) {}

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
        $this->hxTrigger("like-$hash");
        return $this->like($hash);
    }
}
