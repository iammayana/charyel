<?php

namespace App\Services\Verification;

use RuntimeException;

class OtpKeyManager
{
    /**
     * Resolve the cryptographic key material for a specific key identifier (key_id).
     *
     * @param  string  $keyId  Key identifier stored on the challenge record
     * @return string
     *
     * @throws RuntimeException If key is missing, insecure in production, or unauthorized
     */
    public static function resolveKey(string $keyId): string
    {
        $configKeys = (array) config('auth.verification.keys', []);
        $activeKeyId = (string) config('auth.verification.active_key_id', 'v1');
        $defaultKey = config('auth.verification.key');

        $key = $configKeys[$keyId] ?? null;
        if (empty($key) && $keyId === $activeKeyId && !empty($defaultKey)) {
            $key = $defaultKey;
        }

        if (!empty($key)) {
            if (app()->isProduction()) {
                if (strlen($key) < 32) {
                    throw new RuntimeException("Cryptographic key for key_id [{$keyId}] must be at least 32 bytes in production.");
                }
            }
            return (string) $key;
        }

        // Key not configured in environment
        if (app()->isProduction()) {
            throw new RuntimeException("Missing required cryptographic key for key_id [{$keyId}] in production.");
        }

        $allowedLocalEnvs = ['local', 'testing', 'dev'];
        if (!in_array(app()->environment(), $allowedLocalEnvs, true)) {
            throw new RuntimeException("Missing required cryptographic key for key_id [{$keyId}] in environment [" . app()->environment() . "].");
        }

        // Local and automated test HKDF fallback strictly restricted to 'v1' or active key
        if ($keyId === 'v1' || $keyId === $activeKeyId) {
            $appKey = (string) config('app.key');
            if (empty($appKey)) {
                throw new RuntimeException('APP_KEY must be configured to derive test OTP verification key.');
            }
            return hash_hkdf('sha256', $appKey, 32, 'charyel-otp-token-digest-v1');
        }

        throw new RuntimeException("Unrecognized key_id [{$keyId}] for OTP verification.");
    }

    /**
     * Retrieve the active key ID used for issuing newly created verification challenges.
     */
    public static function getActiveKeyId(): string
    {
        return (string) config('auth.verification.active_key_id', 'v1');
    }

    /**
     * Validate the cryptographic configuration for production readiness.
     *
     * @throws RuntimeException
     */
    public static function validateProductionConfiguration(): void
    {
        if (app()->isProduction()) {
            $activeKeyId = self::getActiveKeyId();
            $key = self::resolveKey($activeKeyId);

            if (empty($key) || strlen($key) < 32) {
                throw new RuntimeException('Missing or insecure CHARYEL_OTP_KEY in production.');
            }

            $driver = config('auth.verification.delivery_driver', 'array');
            if ($driver === 'array') {
                throw new RuntimeException('ArrayOtpDeliveryService is strictly forbidden in production.');
            }
        }
    }
}
