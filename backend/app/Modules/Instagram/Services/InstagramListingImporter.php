<?php

namespace App\Modules\Instagram\Services;

use App\Models\InstagramAccount;
use App\Models\Listing;
use App\Modules\Catalog\IndustryTemplates;
use Illuminate\Support\Facades\Log;

/**
 * "Auto-Import Listings from Instagram" — pull the account's posts/reels in as ready-to-review
 * catalog entries (photo + caption included), so a business never re-enters its inventory by hand.
 *
 * Idempotent per media id (unique `company_id,source,external_ref`): re-running refreshes captions
 * and media instead of duplicating. Imported rows land as `status = draft` for the user to review,
 * price and vertical fields before the AI starts using them.
 */
class InstagramListingImporter
{
    public function __construct(private readonly InstagramClient $client) {}

    /**
     * @param string[]|null $mediaIds  limit to these media ids; null = all recent media
     * @return array{imported:int, updated:int, listings:\Illuminate\Support\Collection}
     */
    public function import(InstagramAccount $account, ?array $mediaIds = null): array
    {
        $company = $account->company;
        $type = IndustryTemplates::listingType($company?->industry_template);

        $media = collect($this->client->media($account, 100));
        if ($mediaIds) {
            $want = array_flip($mediaIds);
            $media = $media->filter(fn ($m) => isset($want[$m['id'] ?? '']));
        }
        // Skip pure text / story-type entries with no usable image.
        $media = $media->filter(fn ($m) => !empty($m['media_url']) || !empty($m['thumbnail_url']));

        $imported = 0;
        $updated  = 0;
        $rows = collect();

        foreach ($media as $m) {
            $caption   = trim((string) ($m['caption'] ?? ''));
            $title     = $this->title($caption) ?: 'Instagram post';
            $mediaType = strtoupper((string) ($m['media_type'] ?? 'IMAGE'));
            $isReel    = $mediaType === 'VIDEO' || strtoupper((string) ($m['media_product_type'] ?? '')) === 'REELS';
            $thumb     = $m['thumbnail_url'] ?? ($isReel ? null : $m['media_url'] ?? null);
            $full      = $m['media_url'] ?? null;

            // Reel/video → keep the thumbnail (for display) AND the video URL; image → just the image.
            $mediaEntries = array_values(array_filter([
                $thumb ? ['url' => $thumb, 'type' => 'image'] : null,
                ($isReel && $full && $full !== $thumb) ? ['url' => $full, 'type' => 'video'] : null,
                (!$isReel && $full && $full !== $thumb) ? ['url' => $full, 'type' => 'image'] : null,
            ]));

            $igAttrs = array_filter([
                'instagram_permalink'  => $m['permalink'] ?? null,
                'instagram_media_type' => $isReel ? 'reel' : strtolower($mediaType),
                'instagram_posted_at'  => $m['timestamp'] ?? null,
            ]);

            try {
                $listing = Listing::withTrashed()->firstOrNew([
                    'company_id'   => $account->company_id,
                    'source'       => 'instagram',
                    'external_ref' => $m['id'],
                ]);
                $isNew = !$listing->exists;

                // Don't clobber a user's manual edits — only fill what's still empty, always refresh media + IG links.
                $listing->fill([
                    'company_id'  => $account->company_id,
                    'created_by'  => $account->connected_by,
                    'type'        => $listing->type ?: $type,
                    'title'       => $isNew ? $title : $listing->title,
                    'description' => $isNew ? ($caption ?: null) : ($listing->description ?: ($caption ?: null)),
                    'status'      => $isNew ? 'draft' : $listing->status,
                    'price'       => $listing->price ?: $this->extractPrice($caption),
                    'media'       => $mediaEntries ?: ($listing->media ?? []),
                    'attributes'  => array_merge($listing->attributes ?? [], $igAttrs),
                ]);
                if ($listing->trashed()) {
                    $listing->restore();
                }
                $listing->save();

                $isNew ? $imported++ : $updated++;
                $rows->push($listing);
            } catch (\Throwable $e) {
                Log::warning('InstagramListingImporter: failed on media', ['id' => $m['id'] ?? '?', 'error' => $e->getMessage()]);
            }
        }

        return ['imported' => $imported, 'updated' => $updated, 'listings' => $rows];
    }

    /** First non-empty line of the caption, trimmed to a sane title length. */
    private function title(string $caption): string
    {
        $first = trim(strtok($caption, "\n") ?: '');
        $first = preg_replace('/\s+/', ' ', $first);
        return mb_substr($first, 0, 120);
    }

    /** Best-effort price from a caption: "₹85,00,000", "Rs 45000", "AED 1.2M", "12 lakhs". */
    private function extractPrice(string $caption): ?float
    {
        $c = mb_strtolower($caption);

        if (preg_match('/(?:₹|rs\.?|inr|aed|\$)\s*([0-9][0-9,\.]*)\s*(k|lakh|lakhs|l|cr|crore|m|million)?/i', $c, $mm)) {
            $n = (float) str_replace(',', '', $mm[1]);
            return $this->applyUnit($n, $mm[2] ?? null);
        }
        if (preg_match('/([0-9][0-9,\.]*)\s*(lakh|lakhs|cr|crore|million)/i', $c, $mm)) {
            return $this->applyUnit((float) str_replace(',', '', $mm[1]), $mm[2]);
        }
        return null;
    }

    private function applyUnit(float $n, ?string $unit): float
    {
        return match (strtolower((string) $unit)) {
            'k'                       => $n * 1_000,
            'l', 'lakh', 'lakhs'      => $n * 100_000,
            'cr', 'crore'             => $n * 10_000_000,
            'm', 'million'            => $n * 1_000_000,
            default                   => $n,
        };
    }
}
