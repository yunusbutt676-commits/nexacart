<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('shopping-ai', function (Request $request) {
            $id = $request->user()?->id ?? $request->ip();

            return [
                Limit::perMinute(5)->by('ai-minute-'.$id),
                Limit::perDay(50)->by('ai-day-'.$id),
                Limit::perMinute(15)->by('ai-global'),
            ];
        });

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return rtrim(config('frontend.url'), '/').'/recover?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });

        VerifyEmail::createUrlUsing(function ($user) {
            return URL::temporarySignedRoute(
                'nexacart.verification.verify',
                now()->addMinutes(60),
                [
                    'id' => $user->getKey(),
                    'hash' => sha1($user->getEmailForVerification()),
                ]
            );
        });

        RateLimiter::for('account-recovery', function (Request $request) {
            return Limit::perMinute(5)->by('recovery:'.$request->ip());
        });

        RateLimiter::for('account-settings', function (Request $request) {
            return Limit::perMinute(20)->by(
                'settings:'.$request->user()->id
            );
        });

        RateLimiter::for('verification-email', function (Request $request) {
            return Limit::perMinute(2)->by(
                'verify-send:'.$request->user()->id
            );
        });

        RateLimiter::for('verification-link', function (Request $request) {
            return Limit::perMinute(20)->by('verify-link:'.$request->ip());
        });

        RateLimiter::for('admin-users', function (Request $request) {
            return Limit::perMinute(60)->by(
                'admin-users:'.$request->user()->id
            );
        });
    }
}