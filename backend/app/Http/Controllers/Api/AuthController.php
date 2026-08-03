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
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        $token = $user->createToken($request->input('device_name', 'api'))->plainTextToken;

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
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
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
