<?php

namespace App\Http\Controllers\Soprano;

use App\Services\Soprano\WrappedService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

/**
 * "Year in Review" listening stats. /wrapped picks the season's year
 * (December → this year, otherwise last year); /wrapped/{year} shows any
 * year directly — the page is reachable year-round, only the home banner
 * is seasonal.
 */
#[Group(middleware: ["client"])]
class WrappedController extends Controller
{
    public function __construct(private WrappedService $wrapped) {}

    #[Get("/wrapped", "wrapped.index")]
    public function index(): string
    {
        return $this->show($this->wrapped->seasonYear());
    }

    #[Get("/wrapped/{year}", "wrapped.show")]
    public function show(int $year): string
    {
        // Sanity-clamp: track plays only exist for plausible library years.
        if ($year < 2000 || $year > (int) date('Y')) {
            return $this->pageNotFound();
        }

        return $this->render("wrapped/index.html.twig", [
            "review" => $this->wrapped->review($year),
        ]);
    }
}
