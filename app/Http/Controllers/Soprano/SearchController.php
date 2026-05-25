<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\PlayerService;
use App\Services\Soprano\SearchService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class SearchController extends Controller
{
    public function __construct(
        private SearchService $search, 
        private PlayerService $player
    ) {}

    #[Get("/search", "search.index")]
    public function index(): string
    {
        $term = request()->get->get("term");

        if ($term && trim($term) !== '') {
            $this->search->setSearch($term);
            $this->hxTrigger("loadTop, searchResults, searchActions");
        }

        return $this->render("search/index.html.twig");
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
        $this->search->setSearch('');
        $this->hxTrigger("loadTop, searchResults, searchActions");
    }
}
