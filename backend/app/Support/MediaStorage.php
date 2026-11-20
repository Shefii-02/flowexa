<?php

namespace App\Support;

use App\Models\Company;
use App\Modules\WaChat\Models\MediaLibrary;
use Illuminate\Support\Facades\DB;

/**
 * One storage figure per company: everything in the Media Library — files staff uploaded AND team chat photos, videos, voice notes and files.
 *
 * Limit (MB) = the plan's "Media storage" when the plan sets one, otherwise the company's own allowance (waha_media_limit_mb, default 500),
 * plus any storage / "raise a limit" add-ons. Usage is always the real sum of stored file sizes, never a counter that can drift.
 */
class MediaStorage
{
    public const WARN_AT = 0.9;

    public static function limitMb(Company $company): int
    {
        $plan = $company->plan;
        $base = ($plan && $plan->max_media_storage_mb !== null) ? (int) $plan->max_media_storage_mb : (int) ($company->waha_media_limit_mb ?? 500);
        return $base + PlanLimits::mediaBonusMb($company->id);
    }

    public static function usedBytes(int $companyId): int
    {
        return (int) MediaLibrary::where('company_id', $companyId)->sum('size');
    }

    /** Recompute, remember on the company row (for the superadmin screens) and return the figures. */
    public static function summary(Company $company, bool $withBreakdown = false): array
    {
        $bytes = self::usedBytes($company->id);
        $used = round($bytes / 1048576, 2);
        if ((float) $company->waha_media_used_mb !== $used) {
            $company->forceFill(['waha_media_used_mb' => $used])->saveQuietly();
        }
        $limit = self::limitMb($company);
        $out = [
            'used_mb' => $used, 'limit_mb' => $limit, 'free_mb' => max(0, round($limit - $used, 2)),
            'percent' => $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 100,
            'near_limit' => $limit > 0 && $used >= $limit * self::WARN_AT, 'full' => $used >= $limit,
        ];
        if ($withBreakdown) {
            $out['breakdown'] = self::breakdown($company->id);
        }
        return $out;
    }

    /** MB by where the file came from: library uploads, and team chat split into photos / videos / voice notes / files. */
    public static function breakdown(int $companyId): array
    {
        $rows = MediaLibrary::where('company_id', $companyId)->selectRaw("source, mime_type, SUM(size) bytes, COUNT(*) n")->groupBy('source', 'mime_type')->get();
        $mb = fn ($b) => round($b / 1048576, 2);
        $lib = ['mb' => 0, 'files' => 0];
        $chat = ['photos' => 0, 'videos' => 0, 'voice' => 0, 'files' => 0];
        $chatFiles = 0;
        foreach ($rows as $r) {
            if ($r->source !== 'team_chat') { $lib['mb'] += (int) $r->bytes; $lib['files'] += (int) $r->n; continue; }
            $mime = (string) $r->mime_type;
            $k = str_starts_with($mime, 'image/') ? 'photos' : (str_starts_with($mime, 'video/') ? 'videos' : (str_starts_with($mime, 'audio/') ? 'voice' : 'files'));
            $chat[$k] += (int) $r->bytes; $chatFiles += (int) $r->n;
        }
        return [
            'library'   => ['mb' => $mb($lib['mb']), 'files' => $lib['files']],
            'team_chat' => ['mb' => $mb(array_sum($chat)), 'count' => $chatFiles] + array_map($mb, $chat),   // photos / videos / voice / files are MB
        ];
    }

    /** Would `$bytes` more fit? Returns an error message, or null when it fits. */
    public static function refusal(Company $company, int $bytes): ?string
    {
        $limit = self::limitMb($company);
        $used = self::usedBytes($company->id) / 1048576;
        if ($used + $bytes / 1048576 > $limit) {
            $free = max(0, round($limit - $used, 1));
            return "Storage is full ({$limit} MB plan limit, {$free} MB free). Free up space in the Media Library or buy more storage.";
        }
        return null;
    }
}
