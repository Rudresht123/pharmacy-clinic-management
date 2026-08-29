<?php

use App\Models\File;
use App\Repositories\GlobalSettingRepo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (!function_exists('formatDate')) {
    /**
     * Format a date value for display, or null when it cannot be parsed.
     */
    function formatDate(mixed $date, string $format = 'd M Y'): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($date)->format($format);
        } catch (\Exception) {
            return null;
        }
    }
}

if (!function_exists('uploadFile')) {
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
            ? $customName . '.' . $extension
            : Str::uuid() . '.' . $extension;

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

if (!function_exists('getFileUrl')) {
    /**
     * Resolve a stored file id to a public URL, falling back to a placeholder.
     */
    function getFileUrl(?int $fileId, string $default = 'images/no_image.png'): string
    {
        if (!$fileId) {
            return asset($default);
        }

        $file = File::find($fileId);

        return $file?->url ?? asset($default);
    }
}

if (!function_exists('deleteFile')) {
    /**
     * Delete a stored file: removes it from disk and drops the files row.
     *
     * Takes the File id (the same value uploadFile() returns and that
     * owning models store), not a storage path.
     */
    function deleteFile(?int $fileId): bool
    {
        if (!$fileId) {
            return false;
        }

        $file = File::find($fileId);

        if (!$file) {
            return false;
        }

        if ($file->file_path && Storage::disk($file->disk)->exists($file->file_path)) {
            Storage::disk($file->disk)->delete($file->file_path);
        }

        return (bool) $file->delete();
    }
}

if (!function_exists('orgtypes')) {
    /**
     * Organization types for legacy Blade selects.
     *
     * @deprecated Superseded by GET /api/v1/organization-types; removed with the Blade views.
     */
    function orgtypes(?array $search = null)
    {
        return (new GlobalSettingRepo())->getOrgTypes($search);
    }
}

if (!function_exists('emailButton')) {
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
