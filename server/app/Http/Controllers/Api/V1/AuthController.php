<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Church;
use App\Models\ChurchMembership;
use App\Models\User;
use App\Models\UserProfile;
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
}
