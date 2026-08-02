<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MediaAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'flow_node_id',
        'disk',
        'path',
        'url',
        'mime_type',
        'original_name',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    // ── Relationships ───────────────────────────────────────────────────────
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function flowNode()
    {
        return $this->belongsTo(FlowNode::class);
    }

    // ── Model events ─────────────────────────────────────────────────────────
    // Keep company.storage_used_bytes in sync automatically so callers never
    // have to remember to decrement it manually when an asset goes away.
    protected static function booted(): void
    {
        static::deleting(function (MediaAsset $asset) {
            // Remove the physical file first — if this throws, the DB row (and
            // the quota debit below) are left alone so nothing goes out of sync silently.
            if ($asset->path && Storage::disk($asset->disk)->exists($asset->path)) {
                Storage::disk($asset->disk)->delete($asset->path);
            }

            if ($asset->company) {
                $asset->company->decrement('storage_used_bytes', min($asset->size, $asset->company->storage_used_bytes));
            }
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────────────
    public function humanSize(): string
    {
        $bytes = $this->size;
        if ($bytes <= 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
