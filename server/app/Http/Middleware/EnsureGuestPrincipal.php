<?php

namespace App\Http\Middleware;

use App\Models\GuestIdentity;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestPrincipal
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$abilities
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $principal = $request->user();

        // 1. Verify that the authenticated principal is an actual GuestIdentity
        if (!($principal instanceof GuestIdentity)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Action requires an authenticated guest identity.',
            ], 403);
        }

        // 2. Guest identities must strictly authenticate via persisted bearer tokens
        // Never allow ambient cookie sessions or TransientTokens to impersonate guest principals
        $token = $principal->currentAccessToken();
        if (!$token || $token instanceof TransientToken) {
            return response()->json([
                'status' => 'error',
                'message' => 'Guest identity requires bearer token authentication.',
            ], 403);
        }

        // 3. Reject access if this guest identity has already been claimed by a registered user
        if ($principal->isClaimed()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Guest identity has been claimed and converted into a registered user account.',
            ], 403);
        }

        // 4. Verify that the token has the required ability (default: guest:play)
        $requiredAbilities = !empty($abilities) ? $abilities : ['guest:play'];
        foreach ($requiredAbilities as $ability) {
            if (!$principal->tokenCan($ability)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Token lacks required ability [{$ability}].",
                ], 403);
            }
        }

        return $next($request);
    }
}
