<?php

use App\Models\File;
use App\Repositories\GlobalSettingRepo;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (! function_exists('versionedAsset')) {
    /**
     * A public asset URL stamped with the file's last-modified time.
     *
     * The vendor stylesheets are plain <link>s rather than Vite entries, so
     * nothing fingerprints them — an edited file kept serving from the
     * browser cache, leaving new markup styled by old rules. The stamp only
     * changes when the file does, so unchanged assets still cache normally.
     */
    function versionedAsset(string $path): string
    {
        $absolute = public_path($path);

        if (! is_file($absolute)) {
            return asset($path);
        }

        return asset($path).'?v='.filemtime($absolute);
    }
}

if (! function_exists('formatDate')) {
    /**
     * Format a date value for display, or null when it cannot be parsed.
     */
    function formatDate(mixed $date, string $format = 'd M Y'): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->format($format);
        } catch (Exception) {
            return null;
        }
    }
}

if (! function_exists('uploadFile')) {
    /**
     * Store an uploaded file and record it in the files table.
     *
     * @return int The id of the created File record.
     */
    function uploadFile(
        UploadedFile $file,
        string $folder,
        ?string $customName = null,
        string $disk = 'public'
    ): int {
        $extension = $file->getClientOriginalExtension();

        $fileName = $customName
            ? $customName.'.'.$extension
            : Str::uuid().'.'.$extension;

        $path = $file->storeAs($folder, $fileName, $disk);

        return File::create([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'disk' => $disk,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'extension' => $extension,
        ])->id;
    }
}

if (! function_exists('getFileUrl')) {
    /**
     * Resolve a stored file id to a public URL, falling back to a placeholder.
     */
    function getFileUrl(?int $fileId, string $default = 'images/no_image.png'): string
    {
        if (! $fileId) {
            return asset($default);
        }

        $file = File::find($fileId);

        return $file?->url ?? asset($default);
    }
}

if (! function_exists('deleteFile')) {
    /**
     * Delete a stored file: removes it from disk and drops the files row.
     *
     * Takes the File id (the same value uploadFile() returns and that
     * owning models store), not a storage path.
     */
    function deleteFile(?int $fileId): bool
    {
        if (! $fileId) {
            return false;
        }

        $file = File::find($fileId);

        if (! $file) {
            return false;
        }

        if ($file->file_path && Storage::disk($file->disk)->exists($file->file_path)) {
            Storage::disk($file->disk)->delete($file->file_path);
        }

        return (bool) $file->delete();
    }
}

if (! function_exists('orgtypes')) {
    /**
     * Organization types for legacy Blade selects.
     *
     * @deprecated Superseded by GET /api/v1/organization-types; removed with the Blade views.
     */
    function orgtypes(?array $search = null)
    {
        return (new GlobalSettingRepo)->getOrgTypes($search);
    }
}

if (! function_exists('emailButton')) {
    /**
     * Inline-styled call-to-action button for transactional emails.
     */
    function emailButton(string $url, string $text): string
    {
        return "
        <a href='{$url}'
           style='
            background:#2563eb;
            color:#ffffff;
            text-decoration:none;
            padding:12px 24px;
            border-radius:6px;
            display:inline-block;
            font-weight:600;
           '>
           {$text}
        </a>";
    }
}

if (! function_exists('whatsapp')) {
    /**
     * The one door into WhatsApp.
     *
     *     whatsapp($organizationId)->sendTemplate(
     *         phone: $patient->phone,
     *         template: 'appointment_confirmation',
     *         variables: [
     *             'patient_name' => $patient->name,
     *             'doctor_name' => $doctor->name,
     *         ],
     *     );
     *
     * The caller names a template and some values. Which provider carries it,
     * what that provider calls the template, where its credentials live, how
     * its answer maps onto a status, what is retried and what is logged are
     * all below this line and none of them are the caller's business.
     *
     * The organization must be named. A queue worker serves many clinics in
     * one process, so there is no safe ambient "current organization" to fall
     * back on — that is how a message goes out from the wrong account.
     */
    function whatsapp(App\Models\Platform\Organization|int $organization): App\Services\WhatsApp\WhatsAppManager
    {
        return app(App\Services\WhatsApp\WhatsAppManager::class)->forOrganization($organization);
    }
}

if (! function_exists('emailer')) {
    /**
     * The one door into email.
     *
     *     emailer($organization)->queue(
     *         EmailMessage::make(to: $patient->email, subject: ..., body: ...),
     *     );
     *
     * Named `emailer` rather than `email` because `email` reads as a noun and
     * would collide with the first local variable somebody calls $email.
     *
     * The organization must be named, for the same reason it must be named for
     * WhatsApp: a queue worker serves many clinics in one process, and mail
     * that goes out from the wrong clinic's address is discovered by a patient.
     */
    function emailer(App\Models\Platform\Organization|int $organization): App\Services\Email\EmailManager
    {
        return app(App\Services\Email\EmailManager::class)->forOrganization($organization);
    }
}
