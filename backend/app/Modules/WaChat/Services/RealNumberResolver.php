<?php

namespace App\Modules\WaChat\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\LeadEvent;
use App\Models\Lead;
use App\Modules\Contact\Exceptions\ContactException;
use App\Modules\Contact\Services\ContactService;
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A WhatsApp contact that reached us through a privacy id ("88630978687215@lid") has no dialable number. The chat gateway can often map
 * that id to the real phone number; this asks it, and — when it answers and nobody else already holds that number — saves it on the
 * contact (the old id is kept as an alias, so the same chat still lands on the same contact and lead).
 *
 * Runs at lead creation (a queued job from the inbound webhook), on an hourly sweep, and when a lead is opened — all through here.
 */
class RealNumberResolver
{
    public const SAVED = 'saved', CONFLICT = 'conflict', UNRESOLVED = 'unresolved', SKIPPED = 'skipped';

    public function __construct(private readonly ContactService $contacts) {}

    /** A contact worth looking up: it has a privacy chat id and no real number yet. */
    public static function applies(Contact $c): bool
    {
        return str_ends_with((string) $c->wa_id, '@lid') && !$c->phoneLooksReal();
    }

    /**
     * @return array{outcome:string, phone?:string, other_id?:int, other_name?:string, other_deleted?:bool}
     */
    public function resolve(Contact $contact, ?WahaSession $preferred = null): array
    {
        if (!self::applies($contact)) { return ['outcome' => self::SKIPPED]; }
        $company = Company::find($contact->company_id);
        if (!$company || !filled($company->wa_chat_token)) { return ['outcome' => self::SKIPPED]; }

        $phone = null;
        foreach ($this->sessionsToTry($contact->company_id, $preferred) as $session) {
            $phone = $this->lookup((string) $company->wa_chat_token, $session, (string) $contact->wa_id);
            if ($phone) { break; }
        }
        if (!$phone) { return $this->record($contact, self::UNRESOLVED); }
        if ($phone === preg_replace('/\D+/', '', (string) $contact->phone)) { return $this->record($contact, self::UNRESOLVED); }   // the id's digits themselves

        try {
            $this->contacts->changePhone($contact, $phone, (int) $contact->company_id);
        } catch (ContactException $e) {
            // someone else already holds this number — never overwrite; staff decide (compare / merge) from the lead
            $other = Contact::withTrashed()->where('company_id', $contact->company_id)->where('id', '!=', $contact->id)
                ->where(fn ($q) => $q->where('phone', $phone)->when(Contact::aliasesAvailable(), fn ($w) => $w->orWhereJsonContains('phone_aliases', $phone)))->first();
            return $this->record($contact, self::CONFLICT, ['phone' => $phone] + ($other ? ['other_id' => (int) $other->id, 'other_name' => $other->name ?: (string) $other->phone, 'other_deleted' => $other->trashed()] : []));
        }

        $this->record($contact, self::SAVED);
        $this->noteOnLeads($contact, $phone);
        return ['outcome' => self::SAVED, 'phone' => $phone];
    }

    /** The session the chat came in on first, then the company's other live ones (a few at most). @return WahaSession[] */
    private function sessionsToTry(int $companyId, ?WahaSession $preferred): array
    {
        $others = WahaSession::where('company_id', $companyId)->when($preferred, fn ($q) => $q->where('id', '!=', $preferred->id))
            ->orderByRaw("status = 'connected' desc")->orderBy('id')->limit(3)->get()->all();
        return $preferred ? array_merge([$preferred], $others) : $others;
    }

    /** @return string|null digits of the real number behind a privacy id, or null when the gateway cannot map it */
    private function lookup(string $token, WahaSession $session, string $chatId): ?string
    {
        try {
            $res = Http::withHeaders(['X-API-Key' => $token])->timeout(8)->connectTimeout(4)
                ->get(rtrim((string) config('services.open_wa.base_url'), '/') . "/sessions/{$session->session_name}/contacts/" . rawurlencode($chatId) . '/phone');
        } catch (\Throwable) {
            return null;
        }
        $digits = $res->successful() ? preg_replace('/\D+/', '', (string) $res->json('phone')) : '';
        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }

    private function record(Contact $c, string $status, array $extra = []): array
    {
        if (Schema::hasColumn('contacts', 'phone_lookup_status')) {
            Contact::withoutEvents(fn () => Contact::whereKey($c->id)->update(['phone_lookup_at' => now(), 'phone_lookup_status' => $status]));
        }
        return ['outcome' => $status] + $extra;
    }

    /** A line in each open lead's history, so staff can see the number appeared on its own. */
    private function noteOnLeads(Contact $contact, string $phone): void
    {
        try {
            Lead::where('contact_id', $contact->id)->whereNotIn('stage', ['enrolled', 'lost'])->get()->each(fn (Lead $l) => LeadEvent::create([
                'lead_id' => $l->id, 'company_id' => $l->company_id, 'user_id' => null, 'event' => 'real_number_found', 'payload' => ['phone' => $phone],
            ]));
        } catch (\Throwable $e) {
            Log::debug('real_number_found event failed: ' . $e->getMessage());
        }
    }
}
