<?php

namespace App\Modules\ShortLink\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ShortLink;

/** Public (no auth) — GET /s/{code} counts the click and 302s to the target URL. */
class ShortLinkRedirectController extends Controller
{
    public function __invoke(string $code)
    {
        $link = ShortLink::where('code', $code)->first();

        if (!$link || !$link->is_active || ($link->expires_at && $link->expires_at->isPast())) {
            abort(404, 'This link is no longer available.');
        }

        // atomic bump — safe under concurrent clicks
        ShortLink::where('id', $link->id)->update([
            'clicks'          => \DB::raw('clicks + 1'),
            'last_clicked_at' => now(),
        ]);

        return redirect()->away($link->target_url, 302)->header('Cache-Control', 'no-store');
    }
}
