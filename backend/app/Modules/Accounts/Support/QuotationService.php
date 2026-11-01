<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Models\AccQuotation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quotation → Invoice. A quotation is draft → sent → accepted | rejected, then becomes `invoiced` when converted.
 * Only draft/sent quotations can be edited; an accepted price is never silently changed (duplicate it to revise).
 */
class QuotationService
{
    private const NEXT = [
        'draft'    => ['sent', 'accepted', 'rejected'],
        'sent'     => ['draft', 'accepted', 'rejected'],
        'accepted' => [],
        'rejected' => ['draft'],
        'invoiced' => [],
    ];

    public function __construct(private readonly AccountsService $svc) {}

    private function fail(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => [$msg]]);
    }

    public function nextNumber(int $cid): string
    {
        $last = AccQuotation::where('company_id', $cid)->orderByDesc('id')->value('number');
        $n = $last && preg_match('/(\d+)$/', $last, $m) ? (int) $m[1] + 1 : 1;
        return 'QT-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function find(int $cid, int $id): AccQuotation
    {
        return AccQuotation::with(['items', 'party'])->where('company_id', $cid)->findOrFail($id);
    }

    public function save(int $cid, ?int $userId, array $d, ?AccQuotation $existing = null): AccQuotation
    {
        $this->svc->seedDefaults($cid);
        if ($existing && !in_array($existing->status, ['draft', 'sent'], true)) {
            $this->fail('status', "A {$existing->status} quotation cannot be edited — duplicate it to make a revised offer.");
        }
        $party = AccLedger::where('company_id', $cid)->where('group', 'debtor')->find($d['party_ledger_id'] ?? 0);
        if (!$party) { $this->fail('party_ledger_id', 'Select the customer.'); }

        $date = Carbon::parse($d['date']);
        $valid = !empty($d['valid_until']) ? Carbon::parse($d['valid_until']) : $date->copy()->addDays($this->svc->settings($cid)->quotation_validity_days ?: 15);
        if ($valid->lt($date)) { $this->fail('valid_until', 'The validity date cannot be before the quotation date.'); }

        $p = $this->svc->priceLines($cid, $party, $d['place_of_supply'] ?? null, $d['items'] ?? []);
        if (!$p['items']) { $this->fail('items', 'Add at least one line with an amount greater than zero.'); }

        return DB::transaction(function () use ($cid, $userId, $d, $existing, $party, $date, $valid, $p) {
            $attrs = ['company_id' => $cid, 'date' => $date->toDateString(), 'valid_until' => $valid->toDateString(), 'party_ledger_id' => $party->id,
                      'place_of_supply' => $p['pos'], 'is_interstate' => $p['inter'], 'taxable' => $p['taxable'], 'cgst' => $p['cgst'], 'sgst' => $p['sgst'],
                      'igst' => $p['igst'], 'total' => $p['total'], 'subject' => $d['subject'] ?? null, 'notes' => $d['notes'] ?? null, 'terms' => $d['terms'] ?? null];
            if ($existing) { $existing->update($attrs); $existing->items()->delete(); $q = $existing; }
            else { $q = AccQuotation::create($attrs + ['number' => $this->nextNumber($cid), 'status' => 'draft', 'created_by' => $userId]); }
            foreach ($p['items'] as $it) { $q->items()->create($it); }
            return $q->load(['items', 'party']);
        });
    }

    public function setStatus(int $cid, int $id, string $to): AccQuotation
    {
        $q = $this->find($cid, $id);
        if (!in_array($to, self::NEXT[$q->status] ?? [], true)) { $this->fail('status', "A {$q->status} quotation cannot be marked {$to}."); }
        if (in_array($to, ['sent', 'accepted'], true) && $q->effective_status === 'expired') {
            $this->fail('status', 'This quotation has expired — extend its validity date first.');
        }
        $q->update(['status' => $to]);
        return $q->fresh(['items', 'party']);
    }

    /** Raise the sales invoice for this quotation. One quotation → one invoice, exactly once. */
    public function convert(int $cid, ?int $userId, int $id, array $opts = []): array
    {
        $q = $this->find($cid, $id);
        if ($q->status === 'invoiced') { $this->fail('status', 'This quotation has already been invoiced.'); }
        if ($q->status === 'rejected') { $this->fail('status', 'A rejected quotation cannot be invoiced.'); }
        if ($q->effective_status === 'expired') { $this->fail('status', 'This quotation has expired — extend its validity date first.'); }

        return DB::transaction(function () use ($cid, $userId, $q, $opts) {
            $v = $this->svc->saveVoucher($cid, $userId, [
                'type' => 'sales', 'date' => $opts['date'] ?? now()->toDateString(), 'due_date' => $opts['due_date'] ?? null,
                'party_ledger_id' => $q->party_ledger_id, 'place_of_supply' => $q->place_of_supply,
                'reference_no' => $q->number, 'narration' => trim('Against quotation ' . $q->number . ($q->subject ? " — {$q->subject}" : '')),
                'credit_override' => !empty($opts['credit_override']),
                'items' => $q->items->map(fn ($i) => ['item_id' => $i->item_id, 'description' => $i->description, 'hsn' => $i->hsn, 'qty' => $i->qty, 'rate' => $i->rate, 'gst_rate' => $i->gst_rate])->all(),
            ]);
            $q->update(['status' => 'invoiced', 'converted_voucher_id' => $v->id]);
            return [$q->fresh(['items', 'party']), $v];
        });
    }

    /** Copy into a fresh draft (e.g. to revise an accepted/rejected offer). */
    public function duplicate(int $cid, ?int $userId, int $id): AccQuotation
    {
        $q = $this->find($cid, $id);
        return $this->save($cid, $userId, [
            'date' => now()->toDateString(), 'party_ledger_id' => $q->party_ledger_id, 'place_of_supply' => $q->place_of_supply,
            'subject' => $q->subject, 'notes' => $q->notes, 'terms' => $q->terms,
            'items' => $q->items->map(fn ($i) => ['item_id' => $i->item_id, 'description' => $i->description, 'hsn' => $i->hsn, 'qty' => $i->qty, 'rate' => $i->rate, 'gst_rate' => $i->gst_rate])->all(),
        ]);
    }

    public function delete(int $cid, int $id): void
    {
        $q = $this->find($cid, $id);
        if ($q->status === 'invoiced') { $this->fail('status', 'This quotation was converted to an invoice — delete the invoice first.'); }
        DB::transaction(function () use ($q) { $q->items()->delete(); $q->delete(); });
    }

    public function summary(int $cid): array
    {
        $rows = AccQuotation::where('company_id', $cid)->get();
        $by = fn (string $s) => $rows->filter(fn ($q) => $q->effective_status === $s);
        $decided = $by('accepted')->count() + $by('invoiced')->count() + $by('rejected')->count();
        return ['counts' => collect(['draft', 'sent', 'accepted', 'rejected', 'invoiced', 'expired'])->mapWithKeys(fn ($s) => [$s => $by($s)->count()]),
                'open_value' => round($by('draft')->sum('total') + $by('sent')->sum('total'), 2),
                'won_value' => round($by('accepted')->sum('total') + $by('invoiced')->sum('total'), 2),
                'win_rate' => $decided ? round(($by('accepted')->count() + $by('invoiced')->count()) / $decided * 100, 1) : null];
    }
}
