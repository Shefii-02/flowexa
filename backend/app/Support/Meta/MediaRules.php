<?php

namespace App\Support\Meta;

use Illuminate\Http\UploadedFile;

/**
 * Single source of truth for upload restrictions. The frontend mirrors these in
 * frontend/src/utils/mediaRules.ts — change both together.
 *
 * Meta limits: template header IMAGE jpeg/png ≤ 5 MB, VIDEO mp4/3gpp ≤ 16 MB, DOCUMENT pdf
 * (capped at 16 MB here — the app's upload safety limit, below Meta's own 100 MB).
 */
class MediaRules
{
    private const MB = 1048576;

    /** @var array<string,array{label:string,mimes:array<int,string>,max_mb:int,min_width?:int}> */
    private const RULES = [
        'header_image'    => ['label' => 'image',    'mimes' => ['image/jpeg', 'image/png'], 'max_mb' => 5, 'min_width' => 200],
        'header_video'    => ['label' => 'video',    'mimes' => ['video/mp4', 'video/3gpp'], 'max_mb' => 16],
        'header_document' => ['label' => 'document', 'mimes' => ['application/pdf'], 'max_mb' => 16],
        'library'         => ['label' => 'file',     'mimes' => [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'video/mp4', 'video/3gpp', 'video/quicktime', 'video/webm',
            'audio/mpeg', 'audio/ogg', 'audio/aac', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/amr', 'audio/webm',
            'application/pdf', 'text/plain', 'text/csv', 'application/zip', 'application/x-zip-compressed',
            'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ], 'max_mb' => 100],
    ];

    /** Rule key for a template header_format (IMAGE / VIDEO / DOCUMENT). */
    public static function forHeaderFormat(string $format): ?string
    {
        return match (strtoupper($format)) {
            'IMAGE'    => 'header_image',
            'VIDEO'    => 'header_video',
            'DOCUMENT' => 'header_document',
            default    => null,
        };
    }

    /** @return string|null  a user-facing error, or null when the file is acceptable */
    public static function validate(UploadedFile $file, string $rule): ?string
    {
        $r = self::RULES[$rule] ?? null;
        if (!$r) {
            return null;
        }

        // Sniffed from the file bytes, not the client-supplied extension/type.
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        if (!in_array($mime, $r['mimes'], true)) {
            $allowed = collect($r['mimes'])->map(fn ($m) => strtoupper(explode('/', $m)[1]))->implode(' / ');
            return $rule === 'library'
                ? "This file type ({$mime}) isn't supported."
                : "Unsupported {$r['label']} type ({$mime}). Allowed: {$allowed}.";
        }

        if ($file->getSize() > $r['max_mb'] * self::MB) {
            return ucfirst($r['label']) . " is too large ("
                . round($file->getSize() / self::MB, 1) . " MB). Maximum is {$r['max_mb']} MB.";
        }

        if (isset($r['min_width']) && str_starts_with((string) $mime, 'image/')) {
            $dim = @getimagesize($file->getRealPath());
            if ($dim === false) {
                return 'The image could not be read — it may be corrupted.';
            }
            if ($dim[0] < $r['min_width']) {
                return "Image is too small ({$dim[0]} px wide). Use at least {$r['min_width']} px wide; 800×418 px is recommended.";
            }
        }

        return null;
    }
}
