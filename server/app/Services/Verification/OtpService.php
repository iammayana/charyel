<?php

namespace App\Services\Verification;

use App\Contracts\OtpDeliveryServiceContract;
use App\Models\ContactVerificationToken;
use App\Models\GuestIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OtpService
{
    public function __construct(
        protected OtpDeliveryServiceContract $deliveryService
    ) {}

    /**
     * Compute a context-bound HMAC digest for an OTP challenge.
     *
     * Preimage: challenge_id|principal_type|principal_id|contact_type|normalized_contact|purpose|raw_otp
     */
    public function computeDigest(
        string $challengeId,
        string $principalType,
        string $principalId,
        string $contactType,
        string $normalizedContact,
        string $purpose,
        string $rawOtp,
        string $keyId
    ): string {
        $key = OtpKeyManager::resolveKey($keyId);
        $context = implode('|', [
            $challengeId,
            $principalType,
            $principalId,
            $contactType,
            $normalizedContact,
            $purpose,
            $rawOtp,
        ]);

        return hash_hmac('sha256', $context, $key);
    }

    /**
     * Issue a new verification challenge for the authenticated principal.
     *
     * @return array<string, mixed>
     */
    public function createChallenge(
        Model $principal,
        string $contactType,
        string $purpose = 'guest_contact_verification'
    ): array {
        if (!Schema::hasTable('contact_verification_tokens')) {
            return [
                'status' => 'error',
                'code' => 'SCHEMA_UPGRADE_REQUIRED',
                'http_status' => 503,
                'message' => 'Database schema upgrade required. Please run pending migrations.',
            ];
        }

        $contactValue = $contactType === 'email' ? $principal->email : $principal->phone_number;
        if (empty($contactValue)) {
            return [
                'status' => 'error',
                'code' => 'CONTACT_NOT_CONFIGURED',
                'http_status' => 422,
                'message' => "Contact details for [{$contactType}] are not configured on this account.",
            ];
        }

        // Contact-level atomic lock: serializes competing simultaneous requests for the same contact
        $contactLockKey = 'otp_issuance_contact:' . $contactType . ':' . sha1($contactValue);
        $lock = Cache::lock($contactLockKey, 10);

        if (!$lock->get()) {
            return [
                'status' => 'error',
                'code' => 'CONCURRENT_REQUEST',
                'http_status' => 429,
                'message' => 'A verification request for this contact is currently in progress. Please wait a moment.',
                'retry_after' => 5,
            ];
        }

        try {
            // STEP 1: Database persistence inside a dedicated fast transaction with principal row lock
            $issuanceResult = DB::transaction(function () use ($principal, $contactType, $contactValue, $purpose) {
                // Re-read and lock principal to serialize concurrent requests for this principal
                $lockedPrincipal = $principal->newQuery()->where('id', $principal->id)->lockForUpdate()->first();

                $isVerified = $contactType === 'email'
                    ? ($lockedPrincipal->email_verified_at !== null)
                    : ($lockedPrincipal->phone_verified_at !== null);

                if ($isVerified) {
                    return [
                        'status' => 'error',
                        'code' => 'CONTACT_ALREADY_VERIFIED',
                        'http_status' => 409,
                        'message' => "Contact details for [{$contactType}] are already verified.",
                    ];
                }

                // Rate Limit 1: Maximum 3 challenges per normalized contact within 15 minutes
                $recentCount = ContactVerificationToken::where('contact_type', $contactType)
                    ->where('contact_value', $contactValue)
                    ->where('created_at', '>=', now()->subMinutes(15))
                    ->count();

                if ($recentCount >= 3) {
                    return [
                        'status' => 'error',
                        'code' => 'TOO_MANY_CHALLENGES_FOR_CONTACT',
                        'http_status' => 429,
                        'message' => 'Too many verification requests for this contact. Please try again after 15 minutes.',
                        'retry_after' => 900,
                    ];
                }

                // Rate Limit 2: 60-second cooldown per principal + contact channel
                $query = ContactVerificationToken::where('contact_type', $contactType);
                if ($lockedPrincipal instanceof GuestIdentity) {
                    $query->where('guest_identity_id', $lockedPrincipal->id);
                } else {
                    $query->where('user_id', $lockedPrincipal->id);
                }

                $latestToken = $query->latest('created_at')->first();
                if ($latestToken && $latestToken->created_at->diffInSeconds(now()) < 60) {
                    $retryAfter = 60 - (int) $latestToken->created_at->diffInSeconds(now());
                    return [
                        'status' => 'error',
                        'code' => 'COOLDOWN_ACTIVE',
                        'http_status' => 429,
                        'message' => "Please wait {$retryAfter} seconds before requesting another verification code.",
                        'retry_after' => $retryAfter,
                    ];
                }

                // Generate cryptographic challenge artifacts
                $challengeId = (string) Str::uuid7();
                $rawOtp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $activeKeyId = OtpKeyManager::getActiveKeyId();
                $principalType = $lockedPrincipal instanceof GuestIdentity ? 'guest' : 'user';
                $principalId = $lockedPrincipal instanceof GuestIdentity ? (string) $lockedPrincipal->guest_uuid : (string) $lockedPrincipal->id;

                $digest = $this->computeDigest(
                    $challengeId,
                    $principalType,
                    $principalId,
                    $contactType,
                    $contactValue,
                    $purpose,
                    $rawOtp,
                    $activeKeyId
                );
                $expiresAt = now()->addMinutes(10);

                // Invalidate any existing open unverified challenges for this principal and contact type
                $invalidationQuery = ContactVerificationToken::where('contact_type', $contactType)
                    ->whereNull('verified_at')
                    ->whereNull('locked_at');
                if ($lockedPrincipal instanceof GuestIdentity) {
                    $invalidationQuery->where('guest_identity_id', $lockedPrincipal->id);
                } else {
                    $invalidationQuery->where('user_id', $lockedPrincipal->id);
                }
                $invalidationQuery->update(['locked_at' => now()]);

                // Persist new challenge record to database
                $token = ContactVerificationToken::create([
                    'challenge_id' => $challengeId,
                    'guest_identity_id' => $lockedPrincipal instanceof GuestIdentity ? $lockedPrincipal->id : null,
                    'user_id' => $lockedPrincipal instanceof User ? $lockedPrincipal->id : null,
                    'contact_type' => $contactType,
                    'contact_value' => $contactValue,
                    'purpose' => $purpose,
                    'token_digest' => $digest,
                    'key_id' => $activeKeyId,
                    'attempts' => 0,
                    'max_attempts' => 5,
                    'expires_at' => $expiresAt,
                ]);

                return [
                    'status' => 'success',
                    'token' => $token,
                    'challenge_id' => $challengeId,
                    'raw_otp' => $rawOtp,
                    'principal_type' => $principalType,
                    'expires_at' => $expiresAt,
                ];
            });

            if ($issuanceResult['status'] === 'error') {
                return $issuanceResult;
            }

            // STEP 2: Database transaction committed. No DB locks held during external delivery.
            $context = [
                'challenge_id' => $issuanceResult['challenge_id'],
                'principal_type' => $issuanceResult['principal_type'],
                'expires_at' => $issuanceResult['expires_at']->toISOString(),
            ];

            try {
                if ($contactType === 'email') {
                    $this->deliveryService->sendEmailOtp($contactValue, $issuanceResult['raw_otp'], $context);
                } else {
                    $this->deliveryService->sendSmsOtp($contactValue, $issuanceResult['raw_otp'], $context);
                }
            } catch (\Throwable $e) {
                // If delivery fails, immediately lock/revoke the committed token so it cannot be verified!
                $issuanceResult['token']->locked_at = now();
                $issuanceResult['token']->save();

                Log::error('OTP delivery failed', [
                    'challenge_id' => $issuanceResult['challenge_id'],
                    'contact_type' => $contactType,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => 'error',
                    'code' => 'DELIVERY_FAILED',
                    'http_status' => 502,
                    'message' => 'Failed to deliver verification code. Please try again later.',
                ];
            }

            return [
                'status' => 'success',
                'http_status' => 201,
                'message' => 'Verification challenge issued.',
                'data' => [
                    'challenge_id' => $issuanceResult['challenge_id'],
                    'contact_type' => $contactType,
                    'masked_contact' => self::maskContact($contactValue, $contactType),
                    'expires_at' => $issuanceResult['expires_at']->toISOString(),
                    'max_attempts' => 5,
                ],
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Verify an OTP challenge with durable attempt tracking and atomic state transitions.
     *
     * @return array<string, mixed>
     */
    public function verifyChallenge(
        Model $principal,
        string $challengeId,
        string $rawOtp
    ): array {
        if (!Schema::hasTable('contact_verification_tokens')) {
            return [
                'status' => 'error',
                'code' => 'SCHEMA_UPGRADE_REQUIRED',
                'http_status' => 503,
                'message' => 'Database schema upgrade required. Please run pending migrations.',
            ];
        }

        return DB::transaction(function () use ($principal, $challengeId, $rawOtp) {
            $challenge = ContactVerificationToken::where('challenge_id', $challengeId)
                ->lockForUpdate()
                ->first();

            if (!$challenge) {
                return [
                    'status' => 'error',
                    'code' => 'CHALLENGE_NOT_FOUND',
                    'http_status' => 404,
                    'message' => 'Verification challenge not found.',
                ];
            }

            // Verify principal ownership
            if ($principal instanceof GuestIdentity) {
                if ($challenge->guest_identity_id !== $principal->id || $challenge->user_id !== null) {
                    return [
                        'status' => 'error',
                        'code' => 'FORBIDDEN',
                        'http_status' => 403,
                        'message' => 'You do not have permission to verify this challenge.',
                    ];
                }
            } elseif ($principal instanceof User) {
                if ($challenge->user_id !== $principal->id || $challenge->guest_identity_id !== null) {
                    return [
                        'status' => 'error',
                        'code' => 'FORBIDDEN',
                        'http_status' => 403,
                        'message' => 'You do not have permission to verify this challenge.',
                    ];
                }
            }

            // Check if already consumed
            if ($challenge->verified_at !== null) {
                return [
                    'status' => 'error',
                    'code' => 'CHALLENGE_ALREADY_CONSUMED',
                    'http_status' => 409,
                    'message' => 'Verification challenge has already been verified.',
                ];
            }

            // Check if locked / maximum attempts exceeded
            if ($challenge->locked_at !== null || $challenge->attempts >= $challenge->max_attempts) {
                return [
                    'status' => 'error',
                    'code' => 'CHALLENGE_LOCKED',
                    'http_status' => 423,
                    'message' => 'Verification challenge is locked due to too many failed attempts.',
                ];
            }

            // Check expiration
            if (now()->greaterThan($challenge->expires_at)) {
                return [
                    'status' => 'error',
                    'code' => 'CHALLENGE_EXPIRED',
                    'http_status' => 410,
                    'message' => 'Verification challenge has expired. Please request a new code.',
                ];
            }

            // Re-read and lock principal under transaction to verify current contact detail
            $freshPrincipal = $principal->newQuery()->where('id', $principal->id)->lockForUpdate()->first();
            $currentContact = $freshPrincipal
                ? ($challenge->contact_type === 'email' ? $freshPrincipal->email : $freshPrincipal->phone_number)
                : null;

            if ($currentContact !== $challenge->contact_value) {
                $challenge->locked_at = now();
                $challenge->save();

                return [
                    'status' => 'error',
                    'code' => 'CONTACT_MODIFIED',
                    'http_status' => 409,
                    'message' => 'The contact detail associated with this challenge has been modified. Challenge is invalidated.',
                ];
            }

            // Increment attempt counter
            $challenge->attempts += 1;

            // Recompute expected HMAC digest
            $principalType = $principal instanceof GuestIdentity ? 'guest' : 'user';
            $principalId = $principal instanceof GuestIdentity ? (string) $principal->guest_uuid : (string) $principal->id;

            $computedDigest = $this->computeDigest(
                $challenge->challenge_id,
                $principalType,
                $principalId,
                $challenge->contact_type,
                $challenge->contact_value,
                $challenge->purpose,
                $rawOtp,
                $challenge->key_id
            );

            $isMatch = hash_equals($challenge->token_digest, $computedDigest);

            if (!$isMatch) {
                if ($challenge->attempts >= $challenge->max_attempts) {
                    $challenge->locked_at = now();
                }

                // CRITICAL: save to database inside the transaction and return normally so DB::transaction COMMITS the increment!
                $challenge->save();

                if ($challenge->locked_at !== null) {
                    return [
                        'status' => 'error',
                        'code' => 'CHALLENGE_LOCKED',
                        'http_status' => 423,
                        'message' => 'Maximum verification attempts exceeded. Challenge is locked.',
                        'attempts' => $challenge->attempts,
                        'max_attempts' => $challenge->max_attempts,
                        'remaining_attempts' => 0,
                    ];
                }

                return [
                    'status' => 'error',
                    'code' => 'INVALID_OTP',
                    'http_status' => 422,
                    'message' => 'Invalid verification code.',
                    'attempts' => $challenge->attempts,
                    'max_attempts' => $challenge->max_attempts,
                    'remaining_attempts' => $challenge->max_attempts - $challenge->attempts,
                ];
            }

            // Digest matched! Mark verified and update principal
            $now = now();
            $challenge->verified_at = $now;
            $challenge->save();

            // Invalidate other pending challenges for this principal and contact type
            $invalidationQuery = ContactVerificationToken::where('contact_type', $challenge->contact_type)
                ->whereNull('verified_at')
                ->whereNull('locked_at')
                ->where('id', '!=', $challenge->id);
            if ($principal instanceof GuestIdentity) {
                $invalidationQuery->where('guest_identity_id', $principal->id);
            } else {
                $invalidationQuery->where('user_id', $principal->id);
            }
            $invalidationQuery->update(['locked_at' => $now]);

            // Update principal verification timestamp
            if ($challenge->contact_type === 'email') {
                $freshPrincipal->email_verified_at = $now;
                $principal->email_verified_at = $now;
            } else {
                $freshPrincipal->phone_verified_at = $now;
                $principal->phone_verified_at = $now;
            }
            $freshPrincipal->save();

            return [
                'status' => 'success',
                'http_status' => 200,
                'message' => 'Contact verified successfully.',
                'data' => [
                    'contact_type' => $challenge->contact_type,
                    'verified_at' => $now->toISOString(),
                ],
            ];
        });
    }

    /**
     * Retrieve current contact verification status and active challenges for a principal.
     *
     * @return array<string, mixed>
     */
    public function getStatus(Model $principal): array
    {
        if (!Schema::hasTable('contact_verification_tokens')) {
            return [
                'status' => 'error',
                'code' => 'SCHEMA_UPGRADE_REQUIRED',
                'http_status' => 503,
                'message' => 'Database schema upgrade required. Please run pending migrations.',
            ];
        }

        $query = ContactVerificationToken::query()
            ->whereNull('verified_at')
            ->whereNull('locked_at')
            ->where('expires_at', '>', now());

        if ($principal instanceof GuestIdentity) {
            $query->where('guest_identity_id', $principal->id);
        } else {
            $query->where('user_id', $principal->id);
        }

        $activeChallenges = $query->get()->map(fn (ContactVerificationToken $t) => [
            'challenge_id' => $t->challenge_id,
            'contact_type' => $t->contact_type,
            'masked_contact' => self::maskContact($t->contact_value, $t->contact_type),
            'expires_at' => $t->expires_at->toISOString(),
            'attempts' => $t->attempts,
            'max_attempts' => $t->max_attempts,
            'remaining_attempts' => max(0, $t->max_attempts - $t->attempts),
        ]);

        return [
            'status' => 'success',
            'http_status' => 200,
            'data' => [
                'principal_type' => $principal instanceof GuestIdentity ? 'guest' : 'user',
                'email' => $principal->email,
                'is_email_verified' => $principal->email_verified_at !== null,
                'email_verified_at' => $principal->email_verified_at?->toISOString(),
                'phone_number' => $principal->phone_number,
                'is_phone_verified' => $principal->phone_verified_at !== null,
                'phone_verified_at' => $principal->phone_verified_at?->toISOString(),
                'active_challenges' => $activeChallenges,
            ],
        ];
    }

    /**
     * Mask a contact string for user-safe display.
     */
    public static function maskContact(string $contact, string $type): string
    {
        if ($type === 'email') {
            $parts = explode('@', $contact);
            if (count($parts) !== 2) {
                return '***';
            }
            $name = $parts[0];
            $domain = $parts[1];
            if (strlen($name) <= 2) {
                $maskedName = substr($name, 0, 1) . '***';
            } else {
                $maskedName = substr($name, 0, 1) . '***' . substr($name, -1);
            }
            return $maskedName . '@' . $domain;
        }

        // Phone: e.g. +2348011112222 -> +234****2222
        if (strlen($contact) <= 5) {
            return '***';
        }
        return substr($contact, 0, 4) . '****' . substr($contact, -4);
    }
}
