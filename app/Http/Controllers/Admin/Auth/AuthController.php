<?php

namespace App\Http\Controllers\Admin\Auth;

use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;

/**
 * AuthController
 * Auth routes will extend this controller for path / name prefix
 *
 * The /admin path prefix is load-bearing, not cosmetic: the client-side
 * Soprano\Auth controllers declare /sign-in, /register and /sign-out at the
 * exact same paths. The admin subdomain used to keep the two sets apart —
 * without a prefix here the Collector throws "Duplicate route detected" and
 * route:cache fails, taking the whole app down.
 *
 * "guest" is an inert tag; only sitemap:generate reads it, to keep the admin
 * login out of public/sitemap.xml now that these routes are on the main host.
 */
#[Group(pathPrefix: '/admin', namePrefix: 'auth', middleware: ['guest'])]
class AuthController extends Controller
{
}
