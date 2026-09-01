<?php

namespace App\Http\Controllers\Admin;

use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;

/**
 * AdminController
 * Admin routes will extend this controller for the /admin path prefix
 * These are routes used in the admin backend, for ModuleControllers
 */
#[Group(pathPrefix: '/admin', middleware: ["auth"])]
class AdminController extends Controller
{
}
