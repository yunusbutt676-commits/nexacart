<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AccountSettingsController extends Controller
{
    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['prohibited'],
            'role' => ['prohibited'],
            'is_blocked' => ['prohibited'],
        ]);

        $user = $request->user();
        $user->name = trim($data['name']);
        $user->save();

        return response()->json([
            'message' => 'Profile updated.',
            'user' => $user,
        ]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                'different:current_password',
                'max:72',
                PasswordRule::min(8)->letters()->numbers(),
            ],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Your current password is incorrect.',
            ]);
        }

        $this->replacePassword($user, $data['password']);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Password changed. Please sign in again.',
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        Password::sendResetLink($data);

        // Do not disclose whether an email has an account.
        return response()->json([
            'message' => 'If this email has an account, a reset link will be sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [
                'required',
                'confirmed',
                'max:72',
                PasswordRule::min(8)->letters()->numbers(),
            ],
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $this->replacePassword($user, $password);
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Password reset successfully. You can now sign in.',
        ]);
    }

    public function sendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email is already verified.',
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification link sent.',
        ]);
    }

    public function verifyEmail(Request $request, string $id, string $hash)
    {
        $user = User::findOrFail($id);

        abort_if($user->is_blocked, 403, 'This account is blocked.');

        abort_unless(
            hash_equals(sha1($user->getEmailForVerification()), $hash),
            403,
            'Invalid verification link.'
        );

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect(
            rtrim(config('frontend.url'), '/').'/settings?verified=1'
        );
    }

    public function users(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = User::query()
            ->select([
                'id',
                'name',
                'email',
                'role',
                'is_blocked',
                'email_verified_at',
                'created_at',
            ]);

        if ($search = trim($data['q'] ?? '')) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate(15);
    }

    public function updateUserStatus(Request $request, User $user)
    {
        abort_unless($request->user()->role === 'admin', 403);

        $data = $request->validate([
            'is_blocked' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $user, $data) {
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            abort_if(
                $target->id === $request->user()->id || $target->role === 'admin',
                422,
                'Administrator accounts cannot be blocked here.'
            );

            $target->is_blocked = $data['is_blocked'];
            $target->save();

            if ($target->is_blocked && config('session.driver') === 'database') {
                DB::table('sessions')
                    ->where('user_id', $target->id)
                    ->delete();
            }
        });

        return response()->json([
            'message' => $data['is_blocked']
                ? 'Customer blocked.'
                : 'Customer unblocked.',
        ]);
    }

    private function replacePassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            if (config('session.driver') === 'database') {
                DB::table('sessions')
                    ->where('user_id', $user->id)
                    ->delete();
            }
        });
    }
}