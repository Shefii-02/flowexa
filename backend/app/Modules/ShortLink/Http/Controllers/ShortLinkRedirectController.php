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

        // Same IP on the same day counts once (unique link+day+ip); repeats still redirect but don't add a click.
        $ua  = (string) request()->userAgent();
        $ref = request()->headers->get('referer');
        $counted = \DB::table('short_link_clicks')->insertOrIgnore([
            'short_link_id' => $link->id,
            'click_date'    => now()->toDateString(),
            'ip'            => (string) request()->ip(),
            'device'        => match (true) {
                (bool) preg_match('/bot|crawl|spider|facebookexternalhit|whatsapp/i', $ua) => 'bot',
                (bool) preg_match('/tablet|ipad/i', $ua)                                   => 'tablet',
                (bool) preg_match('/mobile|android|iphone/i', $ua)                         => 'mobile',
                default                                                                    => 'desktop',
            },
            'referrer'      => $ref ? mb_substr($ref, 0, 255) : null,
            'user_agent'    => mb_substr($ua, 0, 255),
            'created_at'    => now(),
        ]);

        if ($counted) {
            ShortLink::where('id', $link->id)->update([
                'clicks'          => \DB::raw('clicks + 1'),
                'last_clicked_at' => now(),
            ]);
        }

        return redirect()->away($link->target_url, 302)->header('Cache-Control', 'no-store');
    }
}
