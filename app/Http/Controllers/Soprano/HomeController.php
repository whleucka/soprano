<?php

namespace App\Http\Controllers\Welcome;

use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class HomeController extends Controller
{
    #[Get("/", "home.index")] 
    public function index(): string
    {
        return $this->render("home/index.html.twig");
    }
}
