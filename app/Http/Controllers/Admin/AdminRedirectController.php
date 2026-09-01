<?php

namespace App\Http\Controllers\Admin;

use Echo\Framework\Http\RedirectResponse;
use Echo\Framework\Routing\Route\Get;

/**
 * Lands /admin on the dashboard. Inherits the '/admin' pathPrefix and the
 * "auth" middleware from AdminController — don't re-declare a #[Group] here,
 * the Collector would concatenate the prefixes into /admin/admin.
 */
class AdminRedirectController extends AdminController
{
    #[Get("/", "admin.root")]
    public function index(): RedirectResponse
    {
        // Route name, not a literal path: the dashboard moved from /dashboard
        // to /admin/dashboard when the backend left its own subdomain.
        return redirect(uri("dashboard.admin.index"));
    }
}
