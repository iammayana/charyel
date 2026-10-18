<?php

namespace App\Http\Middleware;

use App\Models\GuestIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestHasContact
{
    /**
     * Handle an incoming request.
     * Enforces that authenticated guest identities have at least one contact channel (email or phone)
     * attached before accessing gameplay endpoints.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->user();

        if ($principal instanceof GuestIdentity && !$principal->hasContact()) {
            return response()->json([
                'status' => 'error',
                'code' => 'GUEST_CONTACT_REQUIRED',
                'message' => 'Contact details (email or phone) are required before continuing.',
            ], 403);
        }

        return $next($request);
    }
}
