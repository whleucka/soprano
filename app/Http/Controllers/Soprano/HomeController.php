<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\HomeService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class HomeController extends Controller
{
    public function __construct(private HomeService $home) {}

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
            "items" => $this->home->recentlyAdded(),
        ]);
    }

    #[Get("/home/recently-played", "home.recently-played")]
    public function recentlyplayed(): string
    {
        return $this->render("home/recently-played.html.twig", [
            "items" => $this->home->recentlyPlayed(),
        ]);
    }

    #[Get("/home/top-played", "home.top-played")]
    public function topPlayed(): string
    {
        return $this->render("home/top-played.html.twig", [
            "items" => $this->home->topPlayed(),
        ]);
    }
}
