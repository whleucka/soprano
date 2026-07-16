<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\{SearchService, MusicService, PlayerService};
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class SearchController extends Controller
{
    public function __construct(
        private SearchService $search,
        private MusicService $music,
        private PlayerService $player,
    ) {}

    #[Get("/search", "search.index")]
    public function index(): string
    {
        $term = request()->get->get("term");

        if ($term && trim($term) !== '') {
            $this->search->setSearch($term);
            $this->hxTrigger("loadTop, searchResults, searchActions");
        }

        return $this->render("search/index.html.twig", [
            "search" => $this->search->getSearch(),
        ]);
    }

    /**
     * Curated feeds ("More" links on the home rails). Each loads a canned
     * track list into the search results view.
     */
    #[Get("/search/feed/{feed}", "search.feed")]
    public function feed(string $feed): string
    {
        $tracks = match ($feed) {
            "recently-played"  => $this->music->recentlyPlayed(1000),
            "top-played"       => $this->music->topPlayed(1000),
            "recently-added"   => $this->music->recentlyAddedTracks(1000),
            "recently-liked"   => $this->music->recentlyLiked(1000),
            "top-played-month" => $this->music->topPlayedThisMonth(1000),
            "rediscover"       => $this->music->rediscover(1000),
            default            => [],
        };

        if ($tracks) {
            $this->search->setSearchResults($tracks);
            $this->hxTrigger("searchResults, searchActions");
        }
        return $this->index();
    }

    #[Get("/search/genre", "search.genre")]
    public function genre(): string
    {
        $genre = request()->get->get("genre");
        if ($genre) {
            $tracks = $this->music->tracksByGenre($genre);
            if ($tracks) {
                $this->search->setSearchResults($tracks);
                $this->hxTrigger("searchResults, searchActions");
            }
        }
        return $this->index();
    }

    #[Get("/search/decade", "search.decade")]
    public function decade(): string
    {
        $decade = (int) request()->get->get("decade");
        if ($decade) {
            $tracks = $this->music->tracksByDecade($decade);
            if ($tracks) {
                $this->search->setSearchResults($tracks);
                $this->hxTrigger("searchResults, searchActions");
            }
        }
        return $this->index();
    }

    #[Get("/search/browse", "search.browse")]
    public function browse(): string
    {
        return $this->render("search/browse.html.twig", [
            "genres"  => $this->music->genres(),
            "decades" => $this->music->decades(),
        ]);
    }

    #[Get("/search/results", "search.results")]
    public function results()
    {
        return $this->render("search/results.html.twig", [
            "search" => $this->search->getSearch(),
            "player" => $this->player->getPlayer()
        ]);
    }

    #[Get("/search/actions", "search.actions")]
    public function actions(): string
    {
        return $this->render("search/actions.html.twig", [
            "search" => $this->search->getSearch(),
        ]);
    }

    #[Get("/search/top", "search.top")]
    public function top(): string
    {
        return $this->render("search/top.html.twig", [
            "search" => $this->search->getSearch()
        ]);
    }

    #[Get("/search/clear", "search.clear")]
    public function clear()
    {
        $this->search->clearSearch();
        $this->hxTrigger("loadTop, searchResults, searchActions");
    }
}
