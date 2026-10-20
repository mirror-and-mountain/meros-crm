<?php

namespace MM\Meros\Crm\App\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Base;
use Illuminate\Http\Request;

class AuthenticatePortal extends Base {
    /**
     * Where unauthenticated users get sent.
     */
    protected function redirectTo(Request $request): ?string {
        if ($request->expectsJson()) {
            return null; // forces a 401 response instead of a redirect
        }

        return route('portal.login');
    }
}