<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GuestIdentity;
use App\Models\User;
use App\Services\Verification\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class VerificationController extends Controller
{
    public function __construct(
        protected OtpService $otpService
    ) {}

    /**
     * Request a contact verification challenge for the authenticated principal.
     */
    public function challenge(Request $request): JsonResponse
    {
        if ($schemaError = $this->assertSchemaAvailable()) {
            return $schemaError;
        }

        $validated = $request->validate([
            'contact_type' => ['required', 'string', 'in:email,phone'],
        ]);

        $principal = $this->getAuthenticatedPrincipal($request, $errorResponse);
        if ($errorResponse) {
            return $errorResponse;
        }

        $result = $this->otpService->createChallenge(
            $principal,
            $validated['contact_type']
        );

        return response()->json($result, $result['http_status']);
    }

    /**
     * Submit an OTP code to verify a pending challenge.
     */
    public function verify(Request $request): JsonResponse
    {
        if ($schemaError = $this->assertSchemaAvailable()) {
            return $schemaError;
        }

        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'uuid'],
            'otp' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);

        $principal = $this->getAuthenticatedPrincipal($request, $errorResponse);
        if ($errorResponse) {
            return $errorResponse;
        }

        $result = $this->otpService->verifyChallenge(
            $principal,
            $validated['challenge_id'],
            $validated['otp']
        );

        return response()->json($result, $result['http_status']);
    }

    /**
     * Check verification status for the authenticated principal's contact channels.
     */
    public function status(Request $request): JsonResponse
    {
        if ($schemaError = $this->assertSchemaAvailable()) {
            return $schemaError;
        }

        $principal = $this->getAuthenticatedPrincipal($request, $errorResponse);
        if ($errorResponse) {
            return $errorResponse;
        }

        $result = $this->otpService->getStatus($principal);

        return response()->json($result, $result['http_status']);
    }

    /**
     * Guard against execution in unmigrated database environments.
     */
    protected function assertSchemaAvailable(): ?JsonResponse
    {
        if (!Schema::hasTable('contact_verification_tokens')) {
            return response()->json([
                'status' => 'error',
                'code' => 'SCHEMA_UPGRADE_REQUIRED',
                'message' => 'Database schema upgrade required. Please run pending migrations.',
            ], 503);
        }

        return null;
    }

    /**
     * Retrieve and validate the authenticated principal.
     */
    protected function getAuthenticatedPrincipal(Request $request, ?JsonResponse &$errorResponse = null): ?object
    {
        $principal = $request->user();

        if (!$principal instanceof GuestIdentity && !$principal instanceof User) {
            $errorResponse = response()->json([
                'status' => 'error',
                'code' => 'UNAUTHORIZED_PRINCIPAL',
                'message' => 'Action requires an authenticated guest or registered user.',
            ], 403);
            return null;
        }

        if ($principal instanceof GuestIdentity && $principal->isClaimed()) {
            $errorResponse = response()->json([
                'status' => 'error',
                'code' => 'GUEST_CLAIMED',
                'message' => 'Guest identity has been claimed and converted into a registered user account.',
            ], 403);
            return null;
        }

        return $principal;
    }
}
