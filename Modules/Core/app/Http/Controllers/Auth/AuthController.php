<?php

namespace Modules\Core\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Requests\Auth\ChangePasswordRequest;
use Modules\Core\Http\Requests\Auth\LoginRequest;
use Modules\Core\Http\Resources\UserResource;
use Modules\Core\Models\User;
use Modules\Core\Services\AuditLogService;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            $user?->increment('failed_logins');

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => __('This account is not active.'),
            ]);
        }

        $user->forceFill([
            'failed_logins' => 0,
            'last_login' => now(),
        ])->save();

        $token = $user->createToken('api')->plainTextToken;

        DB::transaction(function () use ($user, $request) {
            $this->auditLog->record([
                'module' => 'auth',
                'entity_type' => 'User',
                'entity_id' => $user->id,
                'operation' => 'LOGIN',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'tenant_id' => $user->tenant_id,
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $request) {
            $this->auditLog->record([
                'module' => 'auth',
                'entity_type' => 'User',
                'entity_id' => $user->id,
                'operation' => 'LOGOUT',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'tenant_id' => $user->tenant_id,
                'ip_address' => $request->ip(),
            ]);

            $user->currentAccessToken()->delete();
        });

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated('password'),
        ])->save();

        DB::transaction(function () use ($user, $request) {
            $this->auditLog->record([
                'module' => 'auth',
                'entity_type' => 'User',
                'entity_id' => $user->id,
                'operation' => 'UPDATE',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'tenant_id' => $user->tenant_id,
                'new_values' => ['password_changed' => true],
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json(['message' => 'Password changed.']);
    }
}
