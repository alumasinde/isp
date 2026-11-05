<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();

        // Reseller accounts must never access the admin prefix routes
        if ($user->hasRole('Reseller') || $user->reseller !== null) {
            abort(403, 'Access denied: Reseller accounts cannot access the admin area.');
        }

        // Must have an administrative role or specific administrative permission
        if (! hasAccess(['Super Admin', 'Admin', 'Product Manager', 'Accountant', 'Tech'], ['create-reseller', 'view-user', 'all-customer'])) {
            abort(403, 'Access denied: You do not have permission to access the admin area.');
        }

        return $next($request);
    }
}
