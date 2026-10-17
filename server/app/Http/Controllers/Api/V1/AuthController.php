<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Church;
use App\Models\ChurchMembership;
use App\Models\GuestIdentity;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Register a new user account, pilgrim profile, and optional church membership.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols(), 'confirmed'],
            'pilgrim_name' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9_\-\s]+$/'],
            'locality_id' => ['nullable', 'integer', 'exists:localities,id'],
            'church_id' => ['nullable', 'integer', 'exists:churches,id'],
            'token_mode' => ['nullable', 'string', 'in:bearer,session'],
            'device_name' => ['required_if:token_mode,bearer', 'nullable', 'string', 'max:64'],
        ]);

        $tokenMode = $validated['token_mode'] ?? 'session';

        $result = DB::transaction(function () use ($validated, $tokenMode, $request) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            $profile = UserProfile::create([
                'user_id' => $user->id,
                'locality_id' => $validated['locality_id'] ?? null,
                'primary_church_id' => $validated['church_id'] ?? null,
                'pilgrim_name' => $validated['pilgrim_name'],
                'avatar_seed' => Str::random(16),
                'total_xp' => 0,
                'current_streak' => 0,
                'longest_streak' => 0,
                'is_leaderboard_public' => false,
                'show_church_affiliation' => true,
            ]);

            $membership = null;
            if (!empty($validated['church_id'])) {
                $membership = ChurchMembership::create([
                    'user_id' => $user->id,
                    'church_id' => $validated['church_id'],
                    'role' => 'pilgrim',
                    'is_primary' => true,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            return [
                'user' => $user,
                'profile' => $profile,
                'membership' => $membership,
            ];
        });

        $user = $result['user'];
        $profile = $result['profile'];
        $membership = $result['membership'];

        if ($tokenMode === 'bearer') {
            $deviceName = $validated['device_name'] ?? 'Charyel Client';
            $plainToken = $user->createToken($deviceName, ['user:all'])->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'User registered successfully.',
                'data' => [
                    'auth_mode' => 'bearer',
                    'token' => $plainToken,
                    'token_type' => 'Bearer',
                    'abilities' => ['user:all'],
                    'user' => $user->only(['id', 'name', 'email', 'created_at']),
                    'profile' => $profile,
                    'church_membership' => $membership,
                ],
            ], 201);
        }

        // Default: Stateful Cookie/Session Authentication for Browser PWA
        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'User registered successfully.',
            'data' => [
                'auth_mode' => 'session',
                'user' => $user->only(['id', 'name', 'email', 'created_at']),
                'profile' => $profile,
                'church_membership' => $membership,
            ],
        ], 201);
    }

    /**
     * Authenticate user credentials and establish session or issue bearer token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'token_mode' => ['nullable', 'string', 'in:bearer,session'],
            'device_name' => ['required_if:token_mode,bearer', 'nullable', 'string', 'max:64'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email or password.',
            ], 401);
        }

        $tokenMode = $validated['token_mode'] ?? 'session';
        $user->load(['profile', 'primaryChurchMembership.church']);

        if ($tokenMode === 'bearer') {
            $deviceName = $validated['device_name'] ?? 'Charyel Client';
            $plainToken = $user->createToken($deviceName, ['user:all'])->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Authenticated successfully.',
                'data' => [
                    'auth_mode' => 'bearer',
                    'token' => $plainToken,
                    'token_type' => 'Bearer',
                    'abilities' => ['user:all'],
                    'user' => $user->only(['id', 'name', 'email', 'created_at']),
                    'profile' => $user->profile,
                    'church_membership' => $user->primaryChurchMembership,
                ],
            ]);
        }

        // Default: Stateful Cookie/Session Authentication for Browser PWA
        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Authenticated successfully.',
            'data' => [
                'auth_mode' => 'session',
                'user' => $user->only(['id', 'name', 'email', 'created_at']),
                'profile' => $user->profile,
                'church_membership' => $user->primaryChurchMembership,
            ],
        ]);
    }

    /**
     * Retrieve the current authenticated user's profile and church affiliation.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['profile.church', 'primaryChurchMembership.church']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user->only(['id', 'name', 'email', 'created_at']),
                'profile' => $user->profile,
                'church_membership' => $user->primaryChurchMembership,
            ],
        ]);
    }

    /**
     * Terminate the current session or revoke the current bearer token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // If bearer token authenticated (persisted database token), revoke current token
        if ($user && $user->currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $user->currentAccessToken()->delete();
        }

        // If session authenticated, invalidate session
        Auth::guard('web')->logout();
        Auth::forgetGuards();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Revoke all personal access tokens and invalidate all active sessions for the user.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $sessionDriver = config('session.driver');
        $databaseSessionsRevoked = false;

        if ($user) {
            // 1. Revoke all personal access tokens across all devices
            $user->tokens()->delete();

            // 2. Invalidate persisted database sessions for this user across all devices
            if ($sessionDriver === 'database') {
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                $databaseSessionsRevoked = true;
            } else {
                Log::warning(
                    "Session driver [{$sessionDriver}] does not support cross-device session revocation via database. Only current session and bearer tokens were revoked.",
                    ['user_id' => $user->id]
                );
            }
        }

        Auth::guard('web')->logout();
        Auth::forgetGuards();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = ($sessionDriver === 'database')
            ? 'All sessions and tokens revoked.'
            : 'Tokens and current session revoked. Cross-device session revocation is not supported by the active session driver.';

        return response()->json([
            'status' => 'success',
            'message' => $message,
        ]);
    }

    /**
     * Authenticate or register an anonymous guest identity via proof-of-possession.
     * Issues a scoped 30-day Sanctum token restricted to [guest:play].
     */
    public function guest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'guest_uuid' => [
                'required',
                'string',
                'uuid',
                'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            ],
            'guest_secret' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    $isHex64 = (bool) preg_match('/^[0-9a-fA-F]{64}$/', $value);
                    $isBase64Url = (bool) preg_match('/^[A-Za-z0-9_-]{43,44}={0,2}$/', $value);
                    if (!$isHex64 && !$isBase64Url) {
                        $fail('The ' . $attribute . ' must be a properly encoded 256-bit cryptographically random secret (64-character hex or 43-44 character base64url).');
                        return;
                    }
                    if ($isBase64Url && !$isHex64) {
                        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
                        if ($decoded === false || strlen($decoded) !== 32) {
                            $fail('The ' . $attribute . ' must decode to exactly 256 bits (32 bytes).');
                        }
                    }
                },
            ],
            'device_name' => ['nullable', 'string', 'max:64'],
        ], [
            'guest_uuid.regex' => 'The guest uuid must be a canonical UUIDv7.',
        ]);

        $guestUuid = strtolower($validated['guest_uuid']);
        $guestSecret = $validated['guest_secret'];
        $deviceName = $validated['device_name'] ?? 'Charyel Guest Client';

        // Transactional handler for guest lookup / creation
        $executeAuth = function () use ($guestUuid, $guestSecret, $deviceName) {
            $guest = GuestIdentity::where('guest_uuid', $guestUuid)->lockForUpdate()->first();

            if ($guest) {
                // Reauthentication path
                // 1. Strict proof of possession must be verified BEFORE any claim state disclosure
                if (!Hash::check($guestSecret, $guest->secret_hash)) {
                    return [
                        'status' => 'unauthorized',
                        'message' => 'Invalid guest credentials.',
                    ];
                }

                // 2. Reject reauthentication if the identity was already claimed by a registered user
                if ($guest->isClaimed()) {
                    return [
                        'status' => 'claimed',
                        'message' => 'Guest identity has been claimed and converted into a registered user account.',
                    ];
                }

                // 3. Revoke existing tokens for this identity to enforce single-active token policy
                $guest->tokens()->delete();

                // 4. Issue a new 30-day fixed-expiry guest token
                $expiresAt = now()->addDays(30);
                $plainToken = $guest->createToken($deviceName, ['guest:play'], $expiresAt)->plainTextToken;

                return [
                    'status' => 'success',
                    'code' => 200,
                    'guest' => $guest,
                    'token' => $plainToken,
                    'expires_at' => $expiresAt,
                ];
            }

            // Registration path
            $guest = GuestIdentity::create([
                'guest_uuid' => $guestUuid,
                'secret_hash' => Hash::make($guestSecret),
            ]);

            $expiresAt = now()->addDays(30);
            $plainToken = $guest->createToken($deviceName, ['guest:play'], $expiresAt)->plainTextToken;

            return [
                'status' => 'success',
                'code' => 201,
                'guest' => $guest,
                'token' => $plainToken,
                'expires_at' => $expiresAt,
            ];
        };

        try {
            $result = DB::transaction($executeAuth);
        } catch (UniqueConstraintViolationException $e) {
            // If concurrent registrations raced on the unique constraint, re-execute as reauthentication
            $result = DB::transaction($executeAuth);
        }

        if ($result['status'] === 'unauthorized') {
            return response()->json([
                'status' => 'error',
                'message' => $result['message'],
            ], 401);
        }

        if ($result['status'] === 'claimed') {
            return response()->json([
                'status' => 'error',
                'message' => $result['message'],
            ], 403);
        }

        $guest = $result['guest'];

        return response()->json([
            'status' => 'success',
            'data' => [
                'auth_mode' => 'bearer',
                'guest_uuid' => $guest->guest_uuid,
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'abilities' => ['guest:play'],
                'expires_at' => $result['expires_at']->toIso8601String(),
                'is_claimed' => false,
            ],
        ], $result['code']);
    }

    /**
     * Retrieve the current authenticated guest's identity details.
     */
    public function guestMe(Request $request): JsonResponse
    {
        $guest = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'guest_uuid' => $guest->guest_uuid,
                'created_at' => $guest->created_at?->toIso8601String(),
                'is_claimed' => $guest->isClaimed(),
            ],
        ]);
    }
}
