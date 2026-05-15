<?php

namespace App\Http\Controllers\Welcome;

use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Route\Get;

class RadioController extends Controller
{
    public function __construct()
    {
    }

    #[Get("/radio", "radio.index")]
    public function index(): string
    {
        return $this->render("radio/index.html.twig", []);
    }
}
