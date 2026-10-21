<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentBusiness
{
    /**
     * Send users who don't belong to a business to set one up first.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->current_business_id === null) {
            $business = $user->businesses()->first();

            if (! $business) {
                return redirect()->route('businesses.create');
            }

            $user->switchBusiness($business);
        }

        return $next($request);
    }
}
