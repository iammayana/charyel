<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserPrincipal
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$abilities
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        // 1. Verify that the authenticated principal is an instance of User (registered account)
        if (!($user instanceof User)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Action requires an authenticated registered user account.',
            ], 403);
        }

        // 2. If token-based authentication is used and specific abilities are requested, verify them
        if ($user->currentAccessToken()) {
            foreach ($abilities as $ability) {
                if (!$user->tokenCan($ability)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Token lacks required ability [{$ability}].",
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
