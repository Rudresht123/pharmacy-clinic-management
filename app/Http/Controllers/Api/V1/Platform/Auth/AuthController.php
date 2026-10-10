<?php

namespace App\Http\Controllers\Api\V1\Platform\Auth;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Platform\Auth\LoginRequest;
use App\Http\Resources\Platform\PlatformUserResource;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Admin panel session — Build Spec §19 (Auth group).
 *
 * Every method here speaks to the `platform` guard explicitly. Nothing in
 * this controller may fall back to the default guard, because the default is
 * the tenant one.
 *
 * @group Platform > Authentication
 */
class AuthController extends BaseApiController
{
    /**
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        // Fresh session id after a privilege change, so a fixated session id
        // captured before login is worthless.
        $request->session()->regenerate();

        $admin = Auth::guard('platform')->user();

        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        return $this->ok(
            PlatformUserResource::make($admin->load(['roles', 'avatarFile'])),
            'Signed in successfully.'
        );
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->noContent('Signed out successfully.');
    }

    /**
     * The SPA calls this on boot to decide between the panel and the login
     * screen, and reads `roles` from it to shape the navigation.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->ok(
            PlatformUserResource::make($request->user()->load(['roles', 'avatarFile']))
        );
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $admin = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'string', 'email', 'max:190',
                Rule::unique('platform_users', 'email')->ignore($admin->id),
            ],
        ]);

        $admin->update($data);

        return $this->ok(
            PlatformUserResource::make($admin->fresh()->load(['roles', 'avatarFile'])),
            'Profile updated successfully.'
        );
    }

    /**
     * Replace the admin's profile photo.
     *
     * Its own endpoint rather than a field on [updateProfile], for the same
     * reason DoctorController::uploadPhoto is its own: a file is not a value
     * that belongs in a JSON body, and re-sending it on every profile save
     * would upload the same image again.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $admin = $request->user();
        $previous = $admin->avatarFile;

        $disk = config('filesystems.uploads_disk');
        $stored = $request->file('avatar')->store('avatars', $disk);

        $file = File::create([
            'file_name' => $request->file('avatar')->getClientOriginalName(),
            'file_path' => $stored,
            'disk' => $disk,
            'mime_type' => $request->file('avatar')->getClientMimeType(),
            'file_size' => $request->file('avatar')->getSize(),
            'extension' => $request->file('avatar')->getClientOriginalExtension(),
        ]);

        $admin->forceFill(['avatar' => $file->id])->save();

        // Only once the new one is safely in place — a failure above leaves
        // the admin with the photo they had rather than with none.
        if ($previous) {
            Storage::disk($previous->disk)->delete($previous->file_path);
            $previous->delete();
        }

        return $this->ok(
            PlatformUserResource::make($admin->fresh()->load(['roles', 'avatarFile'])),
            'Photo updated'
        );
    }

    /** Remove it, leaving the admin. */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $admin = $request->user();
        $existing = $admin->avatarFile;

        $admin->forceFill(['avatar' => null])->save();

        if ($existing) {
            Storage::disk($existing->disk)->delete($existing->file_path);
            $existing->delete();
        }

        return $this->ok(
            PlatformUserResource::make($admin->fresh()->load(['roles', 'avatarFile'])),
            'Photo removed'
        );
    }
}
