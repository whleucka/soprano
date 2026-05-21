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
            $this->hxTrigger("loadTop");
        }

        return $this->render("search/index.html.twig", [
            "search" => $this->search->getSearch(),
            "player" => $this->player->getPlayer()
        ]);
    }

    #[Get("/top", "search.top")]
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
        $this->hxTrigger("loadTop");

        return $this->index();
    }
}
