<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Lead;
use App\Modules\Lead\Repositories\LeadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Referred by": link a lead to the existing (or a new) contact who sent them, and list what a contact has referred. */
class LeadReferralController extends Controller
{
    public function __construct(private readonly LeadRepository $repo) {}

    /** PUT /leads/{lead}/referrer  { contact_id } | { name, phone } | { contact_id: null } to clear */
    public function set(Request $request, int $lead): JsonResponse
    {
        $cid = auth()->user()->company_id;
        $l = Lead::where('company_id', $cid)->findOrFail($lead);
        $d = $request->validate([
            'contact_id' => ['nullable', 'integer'],
            'name'       => ['nullable', 'string', 'max:150'],
            'phone'      => ['nullable', 'string', 'regex:/^\+?[0-9 ()\-]{7,20}$/'],
        ]);

        $before = $l->referred_by_contact_id;
        if (!empty($d['phone'])) {
            $digits = preg_replace('/\D+/', '', $d['phone']);
            $ref = Contact::findByPhone($cid, $digits) ?? Contact::create(['company_id' => $cid, 'phone' => $digits, 'name' => $d['name'] ?? null, 'source' => 'referral', 'opted_in' => true]);
        } elseif (!empty($d['contact_id'])) {
            $ref = Contact::where('company_id', $cid)->find($d['contact_id']);
            abort_if(!$ref, 422, 'That contact was not found.');
        } else {
            $ref = null;
        }
        abort_if($ref && (int) $ref->id === (int) $l->contact_id, 422, 'A lead cannot be referred by its own contact.');

        $l->forceFill(['referred_by_contact_id' => $ref?->id])->save();
        if ((int) $before !== (int) ($ref?->id)) {
            $this->repo->logEvent($l, $ref ? 'referrer_set' : 'referrer_cleared', ['contact_id' => $ref?->id, 'name' => $ref?->name ?: $ref?->phone, 'previous' => $before]);
        }
        return response()->json(['referred_by' => $this->shape($ref), 'message' => $ref ? 'Referrer saved.' : 'Referrer removed.']);
    }

    /** GET /contacts/{contact}/referrals — the leads this contact has sent in */
    public function referrals(int $contact): JsonResponse
    {
        $cid = auth()->user()->company_id;
        $c = Contact::where('company_id', $cid)->findOrFail($contact);
        $rows = Lead::where('company_id', $cid)->where('referred_by_contact_id', $c->id)->with('contact:id,name,phone', 'assignedTo:id,name')->latest()->limit(200)->get();
        return response()->json([
            'contact' => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone],
            'total' => $rows->count(), 'converted' => $rows->where('stage', 'enrolled')->count(),
            'data' => $rows->map(fn (Lead $l) => ['id' => $l->id, 'stage' => $l->stage, 'contact_name' => $l->contact?->name, 'contact_phone' => $l->contact?->phone, 'staff' => $l->assignedTo?->name, 'created_at' => $l->created_at?->toIso8601String()]),
        ]);
    }

    public static function shape(?Contact $c): ?array
    {
        return $c ? ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'phone_is_real' => $c->phoneLooksReal()] : null;
    }
}
