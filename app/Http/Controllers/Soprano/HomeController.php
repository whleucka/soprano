<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\MusicService;
use App\Services\Soprano\PlaylistsService;
use App\Services\Soprano\RadioService;
use App\Services\Soprano\PodcastService;
use App\Services\Soprano\WrappedService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class HomeController extends Controller
{
    public function __construct(
        private MusicService $music,
        private PlaylistsService $playlists,
        private RadioService $radio,
        private PodcastService $podcasts,
        private WrappedService $wrapped,
    ) {}

    #[Get("/", "home.root")]
    public function index(): void
    {
        redirect("/home")->send();
    }

    #[Get("/home", "home.index")]
    public function home(): string
    {
        return $this->render("home/index.html.twig", [
            // Seasonal banner (Dec 15 – Jan 15); the /wrapped page itself
            // works year-round for any year.
            "wrapped_season" => $this->wrapped->isSeason(),
            "wrapped_year"   => $this->wrapped->seasonYear(),
        ]);
    }

    #[Get("/home/made-for-you", "home.made-for-you")]
    public function madeForYou(): string
    {
        return $this->render("home/made-for-you.html.twig", [
            "playlists" => $this->playlists->getGeneratedPlaylists(),
        ]);
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

    #[Get("/home/top-played-month", "home.top-played-month")]
    public function topPlayedThisMonth(): string
    {
        return $this->render("home/top-played-month.html.twig", [
            "tracks" => $this->music->topPlayedThisMonth(),
        ]);
    }

    #[Get("/home/favourite-radio", "home.favourite-radio")]
    public function favouriteRadio(): string
    {
        return $this->render("home/favourite-radio.html.twig", [
            "stations" => $this->radio->getLikedStations(),
        ]);
    }

    #[Get("/home/my-podcasts", "home.my-podcasts")]
    public function myPodcasts(): string
    {
        return $this->render("home/my-podcasts.html.twig", [
            "podcasts" => $this->podcasts->getLikedPodcasts(),
        ]);
    }

    #[Get("/home/rediscover", "home.rediscover")]
    public function rediscover(): string
    {
        return $this->render("home/rediscover.html.twig", [
            "tracks" => $this->music->rediscover(),
        ]);
    }
}
