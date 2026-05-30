<?php

namespace App\Http\Controllers\Soprano;

use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class PodcastsController extends Controller
{
    public function __construct()
    {
    }

    #[Get("/podcasts", "podcasts.index")]
    public function index(): string
    {
        return $this->render("podcasts/index.html.twig", []);
    }
}
