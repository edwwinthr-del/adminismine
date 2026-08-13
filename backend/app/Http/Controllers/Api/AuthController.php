<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * A valid bcrypt hash of a value nothing can supply, used only to spend the
     * same time on an unknown email as on a wrong password.
     */
    private const DUMMY_HASH = '$2y$12$8bB0vBmZaMflvKZ8y8lVUeCoJUZ4ZnCT.LZmZK0iEwZ5hZCkxTC3S';

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        // Hash a throwaway value when the email is unknown, so a miss costs the
        // same time as a wrong password. Short-circuiting `||` skipped bcrypt
        // entirely on the miss path, and the ~100-250ms difference told an
        // attacker which addresses hold accounts.
        $passwordMatches = Hash::check(
            $request->input('password'),
            $user?->password ?? self::DUMMY_HASH,
        );

        if (! $user || ! $passwordMatches) {
            $this->recordFailure($request, $user, 'credentials');

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            $this->recordFailure($request, $user, 'inactive');

            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        $token = $user->createToken($request->input('device_name', 'api'))->plainTextToken;

        // Who signed in and from where. Without this the trail — the app's whole
        // accountability story — recorded every financial edit but not a single
        // authentication, so a stolen or hijacked account left no first footprint.
        activity()->performedOn($user)->causedBy($user)
            ->withProperties(['ip' => $request->ip()])
            ->log('auth.login');

        return response()->json([
            'token' => $token,
            'user' => $this->payload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->payload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        activity()->performedOn($user)->causedBy($user)
            ->withProperties(['ip' => $request->ip()])
            ->log('auth.logout');

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * A refused sign-in.
     *
     * The email is recorded but never the password attempt, and the row is not
     * attributed to the account when the credentials were wrong — an attacker
     * guessing at someone's address must not be able to write audit rows that
     * read as that person's actions.
     */
    private function recordFailure(Request $request, ?User $user, string $reason): void
    {
        $activity = activity()->withProperties([
            'email' => (string) $request->input('email'),
            'ip' => $request->ip(),
            'reason' => $reason,
        ]);

        if ($reason === 'inactive' && $user !== null) {
            $activity->performedOn($user);
        }

        $activity->log('auth.login_failed');
    }

    /**
     * Change your own password.
     *
     * Available to every signed-in user whatever their role: an administrative
     * reset (UserAccessController::updatePassword) is for the person who has
     * *lost* access, and needing an admin for the ordinary case would mean the
     * admin knows everyone's password. The current one must be re-entered, so a
     * session left open cannot be used to take an account over.
     *
     * Other tokens are revoked because a password change is how someone reacts
     * to a session they no longer trust; the caller's own token survives, so
     * changing it is not a logout.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The password is incorrect.')],
            ]);
        }

        $user->forceFill(['password' => $request->input('password')])->save();

        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;
        $user->tokens()->when($currentId, fn ($query) => $query->whereKeyNot($currentId))->delete();

        activity()->performedOn($user)->causedBy($user)->log('user.password_changed');

        return response()->json(['message' => 'Password updated.']);
    }

    public function updateLocale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(User::LOCALES)],
        ]);

        $user = $request->user();
        $user->update(['locale' => $data['locale']]);

        return response()->json(['user' => $this->payload($user)]);
    }

    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'is_active' => $user->is_active,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
