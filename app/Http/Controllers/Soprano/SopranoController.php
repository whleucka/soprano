<?php

namespace App\Http\Controllers\Welcome;

use App\Services\Soprano\SopranoService;
use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class SopranoController extends Controller
{
    public function __construct(private SopranoService $soprano) {}

    #[Get("/sidebar", "soprano.sidebar")]
    public function sidebar(): string
    {
        return $this->render("soprano/sidebar.html.twig");
    }

    #[Get("/top", "soprano.top")]
    public function top(): string
    {
        return $this->render("soprano/top.html.twig");
    }
}

