<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramFlowRun;
use Illuminate\Support\Facades\DB;

/** Public tracked redirect: https://api.teamzo.io/r/{token} → counts the click, flags the run, 302s to the real URL. */
class InstagramLinkRedirectController extends Controller
{
    public function __invoke(string $token)
    {
        $link = DB::table('instagram_link_clicks')->where('token', $token)->first();
        if (!$link || !preg_match('#^https?://#i', $link->url)) {
            abort(404);
        }
        DB::table('instagram_link_clicks')->where('id', $link->id)->update([
            'clicks'           => DB::raw('clicks + 1'),
            'first_clicked_at' => $link->first_clicked_at ?? now(),
            'last_clicked_at'  => now(),
        ]);
        if ($run = InstagramFlowRun::find($link->instagram_flow_run_id)) {
            $run->setCtx('link_clicked', true);
            $run->save();
        }
        return redirect()->away($link->url);
    }
}
