<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\SidebarService;
use Echo\Framework\Routing\Route\Get;

// No #[Group] of its own on purpose. The Collector concatenates pathPrefix all
// the way up the inheritance chain, so repeating '/admin' here on top of
// AdminController's would route these to /admin/admin/sidebar.
class SidebarController extends AdminController
{
    public function __construct(private SidebarService $service)
    {
    }

    #[Get("/sidebar", "admin.sidebar.load")]
    public function load(): string
    {
        $links = $this->service->getLinks(null, user());
        // Non-admin users must be granted permission
        return $this->render("admin/sidebar.html.twig", [
            "hide" => $this->service->getState(),
            "links" => $links
        ]);
    }

    #[Get("/sidebar/toggle", "admin.sidebar.toggle")]
    public function toggle(): string
    {
        $this->service->toggleState();
        return $this->load();
    }
}
