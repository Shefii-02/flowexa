<?php

namespace App\Modules\Instagram\Jobs;

use App\Models\InstagramAccount;
use App\Models\InstagramCommentEvent;
use App\Models\InstagramConversation;
use App\Models\InstagramMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Meta data-deletion callback: purge everything stored for one IG user id (as a customer and/or as a connected account). */
class DeleteInstagramUserData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $code, public string $igUserId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $convos = InstagramConversation::where('participant_id', $this->igUserId)->pluck('id');
            InstagramMessage::whereIn('instagram_conversation_id', $convos)->delete();
            InstagramConversation::whereIn('id', $convos)->delete();
            InstagramCommentEvent::where('from_id', $this->igUserId)->delete();

            // The user is also a connected business account: wipe its token and disconnect it.
            InstagramAccount::where('ig_user_id', $this->igUserId)->get()->each(function ($a) {
                $a->automations()->update(['is_active' => false]);
                $a->update(['access_token' => '', 'is_active' => false, 'status' => 'disconnected', 'webhook_subscribed' => false]);
            });

            DB::table('instagram_data_deletions')->where('code', $this->code)
                ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        });
    }
}
