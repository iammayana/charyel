<?php

namespace App\Contracts;

interface OtpDeliveryServiceContract
{
    /**
     * Dispatch a verification OTP to an email address.
     *
     * @param  string  $email  Normalized email address
     * @param  string  $otp    Cryptographically generated raw 6-digit OTP
     * @param  array<string, mixed>  $context  Metadata including challenge_id, principal_type, expires_at
     * @return void
     *
     * @throws \Throwable If delivery transmission fails
     */
    public function sendEmailOtp(string $email, string $otp, array $context): void;

    /**
     * Dispatch a verification OTP to a phone number.
     *
     * @param  string  $phone  Normalized E.164 phone number
     * @param  string  $otp    Cryptographically generated raw 6-digit OTP
     * @param  array<string, mixed>  $context  Metadata including challenge_id, principal_type, expires_at
     * @return void
     *
     * @throws \Throwable If delivery transmission fails
     */
    public function sendSmsOtp(string $phone, string $otp, array $context): void;
}
