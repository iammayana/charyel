<?php

namespace App\Services\Verification;

use App\Contracts\OtpDeliveryServiceContract;
use RuntimeException;

class ArrayOtpDeliveryService implements OtpDeliveryServiceContract
{
    /**
     * In-memory storage for sent OTP messages during automated testing.
     *
     * @var array<int, array{type: string, to: string, otp: string, context: array<string, mixed>, sent_at: string}>
     */
    public array $sent = [];

    public function __construct()
    {
        if (app()->isProduction()) {
            throw new RuntimeException('ArrayOtpDeliveryService is strictly forbidden in production.');
        }
    }

    /**
     * Dispatch an email OTP to in-memory store.
     */
    public function sendEmailOtp(string $email, string $otp, array $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('ArrayOtpDeliveryService is strictly forbidden in production.');
        }

        $this->sent[] = [
            'type' => 'email',
            'to' => $email,
            'otp' => $otp,
            'context' => $context,
            'sent_at' => now()->toISOString(),
        ];
    }

    /**
     * Dispatch an SMS OTP to in-memory store.
     */
    public function sendSmsOtp(string $phone, string $otp, array $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('ArrayOtpDeliveryService is strictly forbidden in production.');
        }

        $this->sent[] = [
            'type' => 'sms',
            'to' => $phone,
            'otp' => $otp,
            'context' => $context,
            'sent_at' => now()->toISOString(),
        ];
    }

    /**
     * Retrieve all sent messages filtered by type ('email' or 'sms').
     *
     * @return array<int, array{type: string, to: string, otp: string, context: array<string, mixed>, sent_at: string}>
     */
    public function sentByType(string $type): array
    {
        return array_values(array_filter($this->sent, fn ($m) => $m['type'] === $type));
    }

    /**
     * Retrieve the latest dispatched OTP for a specific contact destination.
     */
    public function latestOtpFor(string $contact): ?string
    {
        $filtered = array_values(array_filter($this->sent, fn ($m) => $m['to'] === $contact));
        if (empty($filtered)) {
            return null;
        }

        $latest = end($filtered);
        return $latest['otp'];
    }

    /**
     * Clear all recorded messages.
     */
    public function clear(): void
    {
        $this->sent = [];
    }
}
