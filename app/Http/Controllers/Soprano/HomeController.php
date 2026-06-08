<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\MusicService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class HomeController extends Controller
{
    public function __construct(private MusicService $music) {}

    #[Get("/", "home.root")]
    public function index(): void
    {
        redirect("/home")->send();
    }

    #[Get("/home", "home.index")]
    public function home(): string
    {
        return $this->render("home/index.html.twig");
    }

    #[Get("/home/recently-added", "home.recently-added")]
    public function recentlyAdded(): string
    {
        return $this->render("home/recently-added.html.twig", [
            "tracks" => $this->music->recentlyAdded(),
        ]);
    }

    #[Get("/home/recently-played", "home.recently-played")]
    public function recentlyplayed(): string
    {
        return $this->render("home/recently-played.html.twig", [
            "tracks" => $this->music->recentlyPlayed(),
        ]);
    }

    #[Get("/home/top-played", "home.top-played")]
    public function topPlayed(): string
    {
        return $this->render("home/top-played.html.twig", [
            "tracks" => $this->music->topPlayed(),
        ]);
    }

    #[Get("/home/recently-liked", "home.recently-liked")]
    public function recentlyLiked(): string
    {
        return $this->render("home/recently-liked.html.twig", [
            "tracks" => $this->music->recentlyLiked(),
        ]);
    }
}
