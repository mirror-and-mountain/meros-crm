<?php

namespace MM\Meros\Crm\App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticatedPortal {
    /**
     * Bounce already-authenticated users away from guest pages
     * (e.g. visiting /portal/login while logged in).
     */
    public function handle(Request $request, Closure $next): Response {
        if (Auth::guard('portal')->check()) {
            return redirect()->to(route('portal.dashboard'));
        }

        return $next($request);
    }
}