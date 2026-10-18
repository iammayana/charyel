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
     * Enforces Approach A: requires at least one contact channel (email or phone) for new registrations.
     * Allows legacy and transitional contactless guests to reauthenticate without contact.
     * Prevents replacing existing contact with 409 Conflict.
     * Issues a scoped 30-day Sanctum token restricted to [guest:play].
     */
    public function guest(Request $request): JsonResponse
    {
        // Fail-closed schema guard: if Migration 12 columns are missing, return 503
        if (!Schema::hasColumns('guest_identities', ['email', 'phone_number', 'is_legacy'])) {
            return response()->json([
                'status' => 'error',
                'code' => 'SCHEMA_UPGRADE_REQUIRED',
                'message' => 'Database schema upgrade required. Please run pending migrations.',
            ], 503);
        }

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
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:32', 'regex:/^\+[1-9]\d{6,14}$/'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ], [
            'guest_uuid.regex' => 'The guest uuid must be a canonical UUIDv7.',
            'phone_number.regex' => 'The phone number must be in valid E.164 format (e.g. +12345678901).',
        ]);

        $guestUuid = strtolower($validated['guest_uuid']);
        $guestSecret = $validated['guest_secret'];
        $deviceName = $validated['device_name'] ?? 'Charyel Guest Client';
        $inputEmail = isset($validated['email']) && trim($validated['email']) !== '' ? strtolower(trim($validated['email'])) : null;
        $inputPhone = isset($validated['phone_number']) ? GuestIdentity::normalizePhone($validated['phone_number']) : null;

        // Transactional handler for guest lookup / creation
        $executeAuth = function () use ($guestUuid, $guestSecret, $deviceName, $inputEmail, $inputPhone) {
            $guest = GuestIdentity::where('guest_uuid', $guestUuid)->lockForUpdate()->first();

            if ($guest) {
                // Reauthentication path
                // 1. Strict proof of possession must be verified BEFORE any claim state disclosure or contact update
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

                // 3. Contact replacement check:
                // Strictly reject replacing an already-set non-null contact with a different value
                if ($guest->email !== null && $inputEmail !== null && strcasecmp($guest->email, $inputEmail) !== 0) {
                    return [
                        'status' => 'conflict',
                        'field' => 'email',
                        'message' => 'Existing email cannot be replaced without verified contact-change workflow.',
                    ];
                }

                if ($guest->phone_number !== null && $inputPhone !== null && $guest->phone_number !== $inputPhone) {
                    return [
                        'status' => 'conflict',
                        'field' => 'phone_number',
                        'message' => 'Existing phone number cannot be replaced without verified contact-change workflow.',
                    ];
                }

                // 4. Attach missing contact if provided
                $contactUpdated = false;
                if ($guest->email === null && $inputEmail !== null) {
                    $guest->email = $inputEmail;
                    $contactUpdated = true;
                }

                if ($guest->phone_number === null && $inputPhone !== null) {
                    $guest->phone_number = $inputPhone;
                    $contactUpdated = true;
                }

                if ($contactUpdated) {
                    $guest->save();
                }

                // 5. Revoke existing tokens for this identity to enforce single-active token policy
                $guest->tokens()->delete();

                // 6. Issue a new 30-day fixed-expiry guest token
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
            // Approach A requires at least one contact channel (email or phone) for new guest onboarding
            if (empty($inputEmail) && empty($inputPhone)) {
                return [
                    'status' => 'validation_error',
                    'code' => 422,
                    'error_code' => 'GUEST_CONTACT_REQUIRED',
                    'message' => 'At least one contact method (email or phone_number) is required for guest registration.',
                    'errors' => [
                        'contact' => ['At least one contact method (email or phone_number) is required.'],
                    ],
                ];
            }

            $guest = new GuestIdentity();
            $guest->guest_uuid = $guestUuid;
            $guest->secret_hash = Hash::make($guestSecret);
            $guest->email = $inputEmail;
            $guest->phone_number = $inputPhone;
            $guest->is_legacy = false;
            $guest->email_verified_at = null;
            $guest->phone_verified_at = null;
            $guest->completed_quiz_count = 0;
            $guest->gross_lifetime_xp = 0;
            $guest->save();

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

        if ($result['status'] === 'conflict') {
            return response()->json([
                'status' => 'error',
                'code' => 'CONTACT_REPLACEMENT_FORBIDDEN',
                'message' => $result['message'],
            ], 409);
        }

        if ($result['status'] === 'validation_error') {
            return response()->json([
                'status' => 'error',
                'code' => $result['error_code'] ?? 'VALIDATION_ERROR',
                'message' => $result['message'],
                'errors' => $result['errors'] ?? [],
            ], $result['code'] ?? 422);
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
                'is_legacy' => (bool) $guest->is_legacy,
                'contact' => [
                    'email' => $guest->email,
                    'phone_number' => $guest->phone_number,
                    'email_verified' => $guest->isEmailVerified(),
                    'phone_verified' => $guest->isPhoneVerified(),
                    'has_contact' => $guest->hasContact(),
                ],
            ],
        ], $result['code']);
    }

    /**
     * Attach an email or phone number to an authenticated guest identity.
     * Permits attaching missing contact or adding a second contact channel.
     * Strictly rejects replacing an already-set non-null contact with a 409 Conflict.
     */
    public function attachGuestContact(Request $request): JsonResponse
    {
        if (!Schema::hasColumns('guest_identities', ['email', 'phone_number', 'is_legacy'])) {
            return response()->json([
                'status' => 'error',
                'code' => 'SCHEMA_UPGRADE_REQUIRED',
                'message' => 'Database schema upgrade required. Please run pending migrations.',
            ], 503);
        }

        $validated = $request->validate([
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:32', 'regex:/^\+[1-9]\d{6,14}$/'],
        ], [
            'phone_number.regex' => 'The phone number must be in valid E.164 format (e.g. +12345678901).',
        ]);

        $inputEmail = isset($validated['email']) && trim($validated['email']) !== '' ? strtolower(trim($validated['email'])) : null;
        $inputPhone = isset($validated['phone_number']) ? GuestIdentity::normalizePhone($validated['phone_number']) : null;

        if (empty($inputEmail) && empty($inputPhone)) {
            return response()->json([
                'status' => 'error',
                'code' => 'GUEST_CONTACT_REQUIRED',
                'message' => 'At least one contact method (email or phone_number) must be provided.',
                'errors' => [
                    'contact' => ['At least one contact method (email or phone_number) must be provided.'],
                ],
            ], 422);
        }

        $guest = $request->user();

        $result = DB::transaction(function () use ($guest, $inputEmail, $inputPhone) {
            $lockedGuest = GuestIdentity::where('id', $guest->id)->lockForUpdate()->first();

            if (!$lockedGuest) {
                return ['status' => 'not_found'];
            }

            if ($lockedGuest->isClaimed()) {
                return ['status' => 'claimed'];
            }

            // Check contact replacement rule
            if ($lockedGuest->email !== null && $inputEmail !== null && strcasecmp($lockedGuest->email, $inputEmail) !== 0) {
                return [
                    'status' => 'conflict',
                    'message' => 'Existing email cannot be replaced without verified contact-change workflow.',
                ];
            }

            if ($lockedGuest->phone_number !== null && $inputPhone !== null && $lockedGuest->phone_number !== $inputPhone) {
                return [
                    'status' => 'conflict',
                    'message' => 'Existing phone number cannot be replaced without verified contact-change workflow.',
                ];
            }

            // Attach missing contact
            $contactUpdated = false;
            if ($lockedGuest->email === null && $inputEmail !== null) {
                $lockedGuest->email = $inputEmail;
                $contactUpdated = true;
            }

            if ($lockedGuest->phone_number === null && $inputPhone !== null) {
                $lockedGuest->phone_number = $inputPhone;
                $contactUpdated = true;
            }

            if ($contactUpdated) {
                $lockedGuest->save();

                // Invalidate any open verification challenges if schema exists
                if (Schema::hasTable('contact_verification_tokens')) {
                    DB::table('contact_verification_tokens')
                        ->where('guest_identity_id', $lockedGuest->id)
                        ->whereNull('verified_at')
                        ->whereNull('locked_at')
                        ->update(['locked_at' => now()]);
                }
            }

            return [
                'status' => 'success',
                'guest' => $lockedGuest,
            ];
        });

        if ($result['status'] === 'not_found') {
            return response()->json([
                'status' => 'error',
                'message' => 'Guest identity not found.',
            ], 404);
        }

        if ($result['status'] === 'claimed') {
            return response()->json([
                'status' => 'error',
                'message' => 'Guest identity has been claimed and converted into a registered user account.',
            ], 403);
        }

        if ($result['status'] === 'conflict') {
            return response()->json([
                'status' => 'error',
                'code' => 'CONTACT_REPLACEMENT_FORBIDDEN',
                'message' => $result['message'],
            ], 409);
        }

        $updatedGuest = $result['guest'];

        return response()->json([
            'status' => 'success',
            'message' => 'Contact details attached successfully.',
            'data' => [
                'guest_uuid' => $updatedGuest->guest_uuid,
                'is_legacy' => (bool) $updatedGuest->is_legacy,
                'contact' => [
                    'email' => $updatedGuest->email,
                    'phone_number' => $updatedGuest->phone_number,
                    'email_verified' => $updatedGuest->isEmailVerified(),
                    'phone_verified' => $updatedGuest->isPhoneVerified(),
                    'has_contact' => $updatedGuest->hasContact(),
                ],
            ],
        ], 200);
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
                'is_legacy' => (bool) $guest->is_legacy,
                'contact' => [
                    'email' => $guest->email,
                    'phone_number' => $guest->phone_number,
                    'email_verified' => $guest->isEmailVerified(),
                    'phone_verified' => $guest->isPhoneVerified(),
                    'has_contact' => $guest->hasContact(),
                ],
            ],
        ]);
    }
}
