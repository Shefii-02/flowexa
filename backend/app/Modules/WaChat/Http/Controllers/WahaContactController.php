<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactLabel;
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Proxies a WA Chat session's contacts/labels to the open-wa gateway (see
 * https://docs.open-wa.org/api-reference/contacts and /labels), and syncs them into this
 * company's own CRM `contacts` / `contact_labels` tables — the gateway is the source of
 * truth for what's actually in someone's WhatsApp account, the CRM tables are what the rest
 * of this app (leads, campaigns, segments, …) already runs on, and these two are otherwise
 * never connected to each other.
 */
class WahaContactController extends Controller
{
    // Same shape as WahaSessionController's private helpers — kept local rather than shared
    // since there's no existing gateway-client service class for lifecycle-style proxy calls
    // in this module (only OpenWaMessageService/OpenWaGateway, which are message-sending
    // specific).
    private function wahaBase(): string
    {
        return rtrim(preg_replace('#/api/?$#', '', (string) config('services.open_wa.base_url')), '/');
    }

    private function wahaHeaders(): array
    {
        return ['X-API-Key' => (string) (auth()->user()?->company?->wa_chat_token ?? '')];
    }

    private function session(int $id): WahaSession
    {
        return WahaSession::where('company_id', auth()->user()->company_id)->findOrFail($id);
    }

    /** A WA contact object's shape isn't documented beyond "a bare array" — read every
     *  plausible field name defensively rather than assume one engine's exact schema. */
    private function waContactId(array $c): ?string
    {
        return $c['id'] ?? $c['_serialized'] ?? $c['contactId'] ?? $c['number'] ?? null;
    }

    private function waContactName(array $c): ?string
    {
        return $c['name'] ?? $c['pushname'] ?? $c['formattedName'] ?? $c['shortName'] ?? null;
    }

    private function waContactPhone(array $c): ?string
    {
        $raw = $c['number'] ?? $this->waContactId($c);
        if (!is_string($raw)) return null;
        // WA ids look like "9198765XXXXX@c.us" / "...@s.whatsapp.net" — keep the digits.
        return preg_replace('/@.*$/', '', $raw) ?: null;
    }

    // ── Contacts ─────────────────────────────────────────────────────────────

    /** GET /waha/sessions/{id}/contacts — the gateway's own list, unmodified. */
    public function contacts(int $id): JsonResponse
    {
        $session = $this->session($id);
        $res = Http::withHeaders($this->wahaHeaders())
            ->get("{$this->wahaBase()}/api/sessions/{$session->session_name}/contacts");
        return response()->json(['data' => $res->successful() ? $res->json() : []]);
    }

    /** GET /waha/sessions/{id}/contacts/{contactId}/profile-picture */
    public function contactProfilePicture(int $id, string $contactId): JsonResponse
    {
        $session = $this->session($id);
        $res = Http::withHeaders($this->wahaHeaders())->get(
            "{$this->wahaBase()}/api/sessions/{$session->session_name}/contacts/" . rawurlencode($contactId) . '/profile-picture'
        );
        return response()->json($res->successful() ? $res->json() : ['url' => null]);
    }

    /** GET /waha/sessions/{id}/contacts/check/{number} — is this number on WhatsApp at all. */
    public function checkContactExists(int $id, string $number): JsonResponse
    {
        $session = $this->session($id);
        $res = Http::withHeaders($this->wahaHeaders())->get(
            "{$this->wahaBase()}/api/sessions/{$session->session_name}/contacts/check/" . rawurlencode($number)
        );
        if (! $res->successful()) {
            return response()->json(['message' => 'Lookup failed (HTTP ' . $res->status() . ').'], 502);
        }
        return response()->json($res->json());
    }

    /** POST /waha/sessions/{id}/contacts/{contactId}/block */
    public function blockContact(int $id, string $contactId): JsonResponse
    {
        $session = $this->session($id);
        Http::withHeaders($this->wahaHeaders())->post(
            "{$this->wahaBase()}/api/sessions/{$session->session_name}/contacts/" . rawurlencode($contactId) . '/block'
        );
        return response()->json(['message' => 'Contact blocked.']);
    }

    /** DELETE /waha/sessions/{id}/contacts/{contactId}/block — unblock. */
    public function unblockContact(int $id, string $contactId): JsonResponse
    {
        $session = $this->session($id);
        Http::withHeaders($this->wahaHeaders())->delete(
            "{$this->wahaBase()}/api/sessions/{$session->session_name}/contacts/" . rawurlencode($contactId) . '/block'
        );
        return response()->json(['message' => 'Contact unblocked.']);
    }

    /**
     * POST /waha/sessions/{id}/contacts/{contactId}/sync — "single tap" sync of ONE WA
     * contact into this company's CRM contacts (see Contact::$fillable: wa_id/phone/name).
     * Matches on wa_id first (stable across a contact renaming itself), falling back to
     * phone for a contact this company already has from another channel.
     */
    public function syncContact(int $id, string $contactId): JsonResponse
    {
        $session = $this->session($id);
        $res = Http::withHeaders($this->wahaHeaders())->get(
            "{$this->wahaBase()}/api/sessions/{$session->session_name}/contacts/" . rawurlencode($contactId)
        );
        if (! $res->successful()) {
            return response()->json(['message' => 'Could not fetch that contact from the gateway.'], 502);
        }

        $contact = $this->upsertContact(auth()->user()->company_id, $res->json());
        if ($contact === null) {
            return response()->json(['message' => 'That WA contact has no usable id/phone.'], 422);
        }

        return response()->json(['message' => 'Synced to CRM contacts.', 'data' => $contact]);
    }

    /**
     * POST /waha/sessions/{id}/contacts/sync — bulk version of the above, for "sync all my
     * WhatsApp contacts into the CRM" in one action. Optionally tags every synced contact
     * with a label — either an existing one (`label_id`) or a brand-new one (`label_name`,
     * optional `label_color`) — so the sync and the labeling happen as one action instead of
     * requiring a second trip through the CRM's own per-contact label editor afterward.
     */
    public function syncAllContacts(Request $request, int $id): JsonResponse
    {
        $session = $this->session($id);
        $companyId = auth()->user()->company_id;

        $label = $this->resolveLabel($request, $companyId);
        if ($label instanceof JsonResponse) return $label;

        $res = Http::withHeaders($this->wahaHeaders())
            ->get("{$this->wahaBase()}/api/sessions/{$session->session_name}/contacts");
        if (! $res->successful()) {
            return response()->json(['message' => 'Could not fetch contacts from the gateway.'], 502);
        }

        $synced = 0;
        $skipped = 0;
        $contactIds = [];
        foreach ((array) $res->json() as $waContact) {
            if (!is_array($waContact)) { $skipped++; continue; }
            $contact = $this->upsertContact($companyId, $waContact);
            if ($contact !== null) {
                $synced++;
                $contactIds[] = $contact->id;
            } else {
                $skipped++;
            }
        }

        // syncWithoutDetaching (not sync()) — adding this label must never strip labels a
        // contact already carries from elsewhere in the CRM.
        if ($label !== null && $contactIds !== []) {
            $label->contacts()->syncWithoutDetaching($contactIds);
        }

        return response()->json([
            'message' => "Synced {$synced} contacts." . ($label !== null ? " Tagged with \"{$label->name}\"." : ''),
            'synced'  => $synced,
            'skipped' => $skipped,
            'label'   => $label,
        ]);
    }

    /**
     * Resolves the label to tag synced contacts with, from either an existing label id or a
     * new label name — mirrors LabelController::store()'s plan-limit check for the "create a
     * new label" path. Returns null if the request asked for no label at all (plain sync,
     * unchanged behavior), or a JsonResponse to short-circuit the caller on a validation error.
     */
    private function resolveLabel(Request $request, int $companyId): ContactLabel|JsonResponse|null
    {
        $labelId = $request->input('label_id');
        if ($labelId !== null) {
            $label = ContactLabel::where('company_id', $companyId)->find($labelId);
            return $label ?? response()->json(['message' => 'Label not found.'], 422);
        }

        $labelName = trim((string) $request->input('label_name', ''));
        if ($labelName === '') return null;

        $existing = ContactLabel::where('company_id', $companyId)->where('name', $labelName)->first();
        if ($existing !== null) return $existing;

        $limit = auth()->user()->company?->plan?->max_labels;
        if ($limit !== null && ContactLabel::where('company_id', $companyId)->count() >= $limit) {
            return response()->json(['message' => "Label limit ({$limit}) reached on your plan."], 422);
        }

        $color = $request->input('label_color');
        if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#25D366';
        }

        return ContactLabel::create(['company_id' => $companyId, 'name' => $labelName, 'color' => $color]);
    }

    private function upsertContact(int $companyId, array $waContact): ?Contact
    {
        $waId = $this->waContactId($waContact);
        $phone = $this->waContactPhone($waContact);
        if ($waId === null && $phone === null) return null;

        $match = $waId !== null
            ? ['company_id' => $companyId, 'wa_id' => $waId]
            : ['company_id' => $companyId, 'phone' => $phone];

        return Contact::updateOrCreate($match, array_filter([
            'company_id' => $companyId,
            'wa_id'      => $waId,
            'phone'      => $phone,
            'name'       => $this->waContactName($waContact),
        ], fn ($v) => $v !== null));
    }

    // ── Labels (WhatsApp Business only — see docs.open-wa.org/api-reference/labels) ────

    /** GET /waha/sessions/{id}/labels — the gateway's own list, unmodified. 400s if the
     *  session isn't a WhatsApp Business account. */
    public function labels(int $id): JsonResponse
    {
        $session = $this->session($id);
        $res = Http::withHeaders($this->wahaHeaders())
            ->get("{$this->wahaBase()}/api/sessions/{$session->session_name}/labels");
        if (! $res->successful()) {
            return response()->json([
                'data' => [],
                'message' => $res->status() === 400
                    ? 'This session is not a WhatsApp Business account — labels are unavailable.'
                    : 'Could not fetch labels (HTTP ' . $res->status() . ').',
            ]);
        }
        return response()->json(['data' => $res->json()]);
    }

    /**
     * POST /waha/sessions/{id}/labels/sync — pulls this session's WhatsApp Business labels
     * into the CRM's own Labels feature (ContactLabel — the same list shown at /labels
     * elsewhere in the app), matched by name so re-running this is idempotent.
     */
    public function syncLabels(int $id): JsonResponse
    {
        $session = $this->session($id);
        $res = Http::withHeaders($this->wahaHeaders())
            ->get("{$this->wahaBase()}/api/sessions/{$session->session_name}/labels");
        if (! $res->successful()) {
            return response()->json([
                'message' => $res->status() === 400
                    ? 'This session is not a WhatsApp Business account — nothing to sync.'
                    : 'Could not fetch labels from the gateway.',
            ], $res->status() === 400 ? 422 : 502);
        }

        $companyId = auth()->user()->company_id;
        $palette = ['#25D366', '#0EA5E9', '#F59E0B', '#EF4444', '#8B5CF6', '#14B8A6', '#EC4899', '#84CC16'];
        $synced = 0;

        foreach ((array) $res->json() as $i => $waLabel) {
            if (!is_array($waLabel)) continue;
            $name = $waLabel['name'] ?? $waLabel['label'] ?? null;
            if (!filled($name)) continue;

            ContactLabel::firstOrCreate(
                ['company_id' => $companyId, 'name' => $name],
                ['color' => $palette[$i % count($palette)]],
            );
            $synced++;
        }

        return response()->json(['message' => "Synced {$synced} labels.", 'synced' => $synced]);
    }
}
