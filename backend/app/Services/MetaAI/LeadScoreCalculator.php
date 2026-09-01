<?php

namespace App\Services\MetaAI;

use App\Models\Contact;
use App\Models\ConversationAnalysis;

class LeadScoreCalculator
{
    public function calculate(Contact $contact, array $recentAnalyses): int
    {
        $score = $contact->lead_score ?? 0;

        foreach ($recentAnalyses as $analysis) {
            $score += $this->scoreFromAnalysis($analysis);
        }

        return max(0, min(100, $score));
    }

    private function scoreFromAnalysis(ConversationAnalysis|array $a): int
    {
        $delta    = 0;
        $intent   = is_array($a) ? ($a['detected_intent'] ?? 'browsing') : $a->detected_intent;
        $sentiment= is_array($a) ? ($a['sentiment'] ?? 'neutral')        : $a->sentiment;
        $signals  = is_array($a) ? ($a['buying_signals'] ?? [])          : ($a->buying_signals ?? []);
        $objects  = is_array($a) ? ($a['objections'] ?? [])              : ($a->objections ?? []);

        // Sentiment contribution
        $delta += match($sentiment) {
            'positive' => 5,
            'negative' => -5,
            default    => 0,
        };

        // Intent contribution
        $delta += match($intent) {
            'price_inquiry'    => 15,
            'product_inquiry'  => 20,
            'buying_signal'    => 30,
            'ready_to_buy'     => 40,
            'not_interested'   => -20,
            'complaint'        => -10,
            'referral'         => 10,
            'existing_customer'=> 5,
            'needs_followup'   => 5,
            default            => 0,
        };

        // Buying signals boost
        $delta += count($signals) * 5;

        // Objections penalty
        $delta -= count($objects) * 5;

        return $delta;
    }

    public function getScoreLabel(int $score): array
    {
        return match(true) {
            $score >= 91 => ['label' => 'Convert Now',  'color' => 'red',    'emoji' => '🚀', 'action' => 'Call immediately'],
            $score >= 76 => ['label' => 'Hot Lead',     'color' => 'orange', 'emoji' => '🔥', 'action' => 'Follow up today'],
            $score >= 51 => ['label' => 'Warm Lead',    'color' => 'yellow', 'emoji' => '⚡', 'action' => 'Follow up this week'],
            $score >= 26 => ['label' => 'Interested',   'color' => 'blue',   'emoji' => '👀', 'action' => 'Nurture with content'],
            default      => ['label' => 'Cold Lead',    'color' => 'gray',   'emoji' => '❄️',  'action' => 'Add to drip campaign'],
        };
    }

    public function recalculateAll(int $companyId): void
    {
        $contacts = \App\Models\Contact::where('company_id', $companyId)->get();

        foreach ($contacts as $contact) {
            $analyses = ConversationAnalysis::where('company_id', $companyId)
                ->where('contact_id', $contact->id)
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get()
                ->toArray();

            if (empty($analyses)) continue;

            // Reset to 10 base + contributions from analyses
            $score = 10;
            foreach ($analyses as $a) {
                $score += $this->scoreFromAnalysis($a);
            }
            $score = max(0, min(100, $score));

            $contact->update([
                'lead_score'            => $score,
                'lead_score_updated_at' => now(),
            ]);
        }
    }
}
