<?php

namespace App\Http\Controllers\Soprano;

use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;
use Echo\Framework\Routing\Route\Get;

#[Group(middleware: ["client"])]
class SopranoController extends Controller
{
    public function __construct() {}

    #[Get("/sidebar", "soprano.sidebar")]
    public function sidebar(): string
    {
        return $this->render("soprano/sidebar.html.twig", [
            "client" => client(),
        ]);
    }
}
