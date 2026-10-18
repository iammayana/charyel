<?php

namespace App\Services\Verification;

use App\Contracts\OtpDeliveryServiceContract;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class ProductionOtpDeliveryService implements OtpDeliveryServiceContract
{
    /**
     * Dispatch an email OTP in production via configured mail transport.
     */
    public function sendEmailOtp(string $email, string $otp, array $context): void
    {
        Mail::raw("Your Charyel verification code is: {$otp}. It expires in 10 minutes.", function ($message) use ($email) {
            $message->to($email)
                ->subject('Charyel Verification Code');
        });
    }

    /**
     * Guard phone OTP delivery in production until a live SMS provider is approved and configured.
     */
    public function sendSmsOtp(string $phone, string $otp, array $context): void
    {
        throw new RuntimeException('Live SMS delivery is not yet configured for production.');
    }
}
