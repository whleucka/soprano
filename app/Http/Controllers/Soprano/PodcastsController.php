<?php

namespace App\Http\Controllers\Welcome;

use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

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
