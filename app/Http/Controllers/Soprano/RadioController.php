<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\RadioService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class RadioController extends Controller
{
    public function __construct(
        private RadioService $radio,
    ) {}

    #[Get("/radio", "radio.index")]
    public function index(): string
    {
        return $this->render("radio/index.html.twig", []);
    }

    #[Get("/radio/stations", "radio.stations")]
    public function stations(): string
    {
        return $this->render("radio/stations.html.twig", [
            "stations" => $this->radio->getStations(),
        ]);
    }

    #[Get("/radio/{hash}/like", "radio.like")]
    public function like(string $hash): string
    {
        return $this->render("components/like-btn.html.twig", [
            "liked"    => $this->radio->isStationLiked($hash),
            "like_uri" => uri('radio.like-toggle', $hash),
        ]);
    }

    #[Get("/radio/{hash}/like-toggle", "radio.like-toggle")]
    public function likeToggle(string $hash): string
    {
        $this->radio->toggleStationLike($hash);
        $this->hxTrigger("like-$hash, radioStations");
        return $this->like($hash);
    }
}
