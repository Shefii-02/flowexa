<?php

namespace App\Modules\Contact\Services;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Modules\Contact\Exceptions\ContactException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merge a duplicate contact INTO another: everything that pointed at the duplicate (leads, chats, deals, tasks, bookings, sends…)
 * now points at the surviving contact, the duplicate's old phone / chat id become aliases of it (so a message from the old
 * identifier still lands on the right person), and the duplicate is deleted. Nothing is lost; consent is never loosened.
 */
class ContactMergeService
{
    /** Tables that hold a contact_id. A row that would collide with one the survivor already has is left on the (deleted) duplicate. */
    private const CONTACT_ID_TABLES = [
        'leads', 'lead_assignments', 'lead_conversion_events', 'calendar_events', 'campaign_contacts', 'conversation_analyses', 'crm_deals',
        'crm_tasks', 'email_logs', 'flow_node_hits', 'flow_sessions', 'instagram_conversations', 'listing_sales', 'message_logs', 'meta_leads',
        'survey_form_responses', 'wa_calls', 'wa_conversations', 'widget_conversations',
    ];
    /** Tables keyed by the contact's phone number rather than its id (live state only — logs keep their history). */
    private const PHONE_TABLES = ['ai_agent_sessions' => 'contact_phone', 'follow_up_queue' => 'contact_phone'];

    /** Both contacts side by side, with what each has — the picture staff decide from. */
    public function summary(Contact $c): array
    {
        $leads = Lead::where('contact_id', $c->id);
        $open = (clone $leads)->whereNotIn('stage', ['enrolled', 'lost'])->with('assignedTo:id,name')->latest()->first();
        $chats = 0;
        foreach (['wa_conversations', 'instagram_conversations', 'widget_conversations'] as $t) {
            if (Schema::hasTable($t)) { $chats += DB::table($t)->where('contact_id', $c->id)->count(); }
        }
        return [
            'id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'phone_is_real' => $c->phoneLooksReal(), 'wa_id' => $c->wa_id,
            'opted_in' => (bool) $c->opted_in, 'deleted' => $c->trashed(),
            'last_message_at' => $c->last_message_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
            'labels' => $c->labels()->pluck('name')->all(),
            'leads_total' => (clone $leads)->count(),
            'open_lead' => $open ? ['id' => $open->id, 'stage' => $open->stage, 'staff' => $open->assignedTo?->name] : null,
            'chats' => $chats,
        ];
    }

    /** @return array{moved: array<string,int>, into: Contact} */
    public function merge(Contact $from, Contact $into, int $companyId): array
    {
        if ($from->id === $into->id) {
            throw new ContactException('Pick a different contact to merge into.', 422, 'merge_same');
        }
        if ((int) $from->company_id !== $companyId || (int) $into->company_id !== $companyId) {
            throw ContactException::notFound();
        }
        if ($from->trashed() || $into->trashed()) {
            throw new ContactException('A deleted contact cannot be merged.', 422, 'merge_deleted');
        }

        return DB::transaction(function () use ($from, $into) {
            $leadIds = Lead::where('contact_id', $from->id)->pluck('id')->all();
            $ignore = DB::getDriverName() === 'sqlite' ? 'OR IGNORE' : 'IGNORE';
            $moved = [];

            foreach (self::CONTACT_ID_TABLES as $table) {
                if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'contact_id')) { continue; }
                $n = DB::update("UPDATE {$ignore} {$table} SET contact_id = ? WHERE contact_id = ?", [$into->id, $from->id]);
                if ($n) { $moved[$table] = $n; }
            }
            foreach (self::PHONE_TABLES as $table => $col) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $col) && $from->phone !== $into->phone) {
                    $n = DB::update("UPDATE {$ignore} {$table} SET {$col} = ? WHERE {$col} = ?", [$into->phone, $from->phone]);
                    if ($n) { $moved[$table] = $n; }
                }
            }
            if (Schema::hasTable('contact_label_pivot')) {
                DB::statement("INSERT {$ignore} INTO contact_label_pivot (contact_id, contact_label_id) SELECT ?, contact_label_id FROM contact_label_pivot WHERE contact_id = ?", [$into->id, $from->id]);
                DB::table('contact_label_pivot')->where('contact_id', $from->id)->delete();
            }

            // the survivor keeps its own details and only fills gaps; the duplicate's identifiers become aliases
            $aliases = (array) ($into->phone_aliases ?? []);
            foreach (array_merge([$from->phone], (array) ($from->phone_aliases ?? [])) as $p) {
                if ($p !== null && $p !== '' && $p !== $into->phone) { $aliases[] = (string) $p; }
            }
            $isPlaceholder = fn (?string $n, ?string $phone) => $n === null || trim($n) === '' || preg_replace('/\D+/', '', $n) === preg_replace('/\D+/', '', (string) $phone);
            $into->forceFill(array_filter([
                'name'  => $isPlaceholder($into->name, $into->phone) && !$isPlaceholder($from->name, $from->phone) ? $from->name : null,
                'email' => $into->email ?: $from->email,
                'wa_id' => $into->wa_id ?: $from->wa_id,
                'last_message_at' => $from->last_message_at && (!$into->last_message_at || $from->last_message_at->gt($into->last_message_at)) ? $from->last_message_at : null,
                'current_assignment_id' => $into->current_assignment_id ?: $from->current_assignment_id,
            ], fn ($v) => $v !== null && $v !== ''));
            if (Contact::aliasesAvailable()) { $into->phone_aliases = array_slice(array_values(array_unique($aliases)), -10) ?: null; }
            if (!$from->opted_in || !$into->opted_in) {   // consent only ever tightens
                $into->opted_in = false;
                $into->opted_out_at = collect([$into->opted_out_at, $from->opted_out_at])->filter()->sort()->first() ?? now();
            }
            $into->custom_fields = array_merge((array) ($from->custom_fields ?? []), (array) ($into->custom_fields ?? []));
            $into->total_leads_count = Lead::where('contact_id', $into->id)->count();
            $into->save();

            // the duplicate has handed everything over — free its slot (its phone stays reserved by the deleted row)
            $from->forceFill(['wa_id' => null, 'total_leads_count' => 0, 'current_assignment_id' => null])->saveQuietly();
            $from->delete();

            foreach ($leadIds as $id) {
                LeadEvent::create(['lead_id' => $id, 'company_id' => $into->company_id, 'user_id' => auth()->id(), 'event' => 'contact_merged',
                    'payload' => ['from_contact' => $from->id, 'from_phone' => $from->phone, 'into_contact' => $into->id, 'into_phone' => $into->phone]]);
            }
            return ['moved' => $moved, 'into' => $into->fresh(['labels'])];
        });
    }
}
