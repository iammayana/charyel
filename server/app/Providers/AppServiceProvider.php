<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Contracts\OtpDeliveryServiceContract::class, function ($app) {
            $driver = config('auth.verification.delivery_driver', 'array');
            if ($app->isProduction()) {
                if ($driver === 'array') {
                    throw new \RuntimeException('ArrayOtpDeliveryService is strictly forbidden in production.');
                }
                return new \App\Services\Verification\ProductionOtpDeliveryService();
            }

            if ($driver === 'production') {
                return new \App\Services\Verification\ProductionOtpDeliveryService();
            }

            return new \App\Services\Verification\ArrayOtpDeliveryService();
        });

        $this->app->singleton(
            \App\Contracts\QuizScoringPolicyContract::class,
            \App\Services\Quiz\Policies\StandardQuizScoringPolicy::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = $request->ip() . '|' . strtolower((string) $request->input('email'));
            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('guest', function (Request $request) {
            $key = $request->ip() . '|' . strtolower((string) $request->input('guest_uuid'));
            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('verification-challenge', function (Request $request) {
            $user = $request->user();
            $principalKey = $user ? get_class($user) . ':' . $user->id : $request->ip();
            return Limit::perMinute(6)->by($principalKey . '|' . $request->ip());
        });

        RateLimiter::for('verification-verify', function (Request $request) {
            $user = $request->user();
            $principalKey = $user ? get_class($user) . ':' . $user->id : $request->ip();
            return Limit::perMinute(15)->by($principalKey . '|' . $request->ip());
        });
    }
}
