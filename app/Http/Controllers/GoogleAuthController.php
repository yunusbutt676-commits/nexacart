<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        if (! config('services.google.client_id') ||
            ! config('services.google.client_secret')) {
            return $this->failure('google_unavailable');
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure('google_failed');
        }

        $verified = filter_var(
            $google->user['email_verified']
                ?? $google->user['verified_email']
                ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        if (! $google->getId() || ! $google->getEmail() || ! $verified) {
            return $this->failure('google_failed');
        }

        $user = User::where('google_id', $google->getId())->first();

        if (! $user) {
            $email = strtolower($google->getEmail());

            // Existing password accounts must sign in with their password.
            // Explicit account linking can be added later.
            if (User::where('email', $email)->exists()) {
                return $this->failure('existing_account');
            }

            $user = new User([
                'name' => mb_substr($google->getName() ?: 'Customer', 0, 100),
                'email' => $email,
                'password' => Str::random(64),
            ]);

            $user->google_id = $google->getId();
            $user->email_verified_at = now();
            $user->save();
        }

        if ($user->is_blocked) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->failure('blocked');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect(config('frontend.url').'/account');
    }

    private function failure(string $code): RedirectResponse
    {
        return redirect(config('frontend.url').'/login?error='.$code);
    }
}