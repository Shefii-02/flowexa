<?php

namespace App\Modules\Email\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailIntegration;
use App\Models\EmailLog;
use App\Modules\Email\Services\CompanyMailerService;
use App\Services\ApiKeyEncryption;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class EmailIntegrationController extends Controller
{
    public function __construct(private readonly CompanyMailerService $mailer) {}

    public function status(): JsonResponse
    {
        $i = EmailIntegration::where('company_id', auth()->user()->company_id)->first();
        return response()->json(['integration' => $i?->makeHidden(['smtp_password'])]);
    }

    /** Sends a test email against the submitted (not-yet-saved) credentials before they're persisted. */
    public function test(Request $request): JsonResponse
    {
        $d = $this->rules($request, true);
        $result = $this->mailer->testConnection($d, $d['test_to'] ?? auth()->user()->email);
        return response()->json($result, $result['valid'] ? 200 : 422);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->rules($request, true);
        $company = $this->company();

        $test = $this->mailer->testConnection($d, $d['test_to'] ?? auth()->user()->email);
        if (!$test['valid']) {
            return response()->json(['message' => 'Could not connect: ' . $test['message']], 422);
        }

        $integration = EmailIntegration::updateOrCreate(
            ['company_id' => $company->id],
            [
                'connected_by'  => auth()->id(),
                'smtp_host'     => $d['smtp_host'],
                'smtp_port'     => $d['smtp_port'],
                'smtp_username' => $d['smtp_username'],
                'smtp_password' => ApiKeyEncryption::encrypt($d['smtp_password']),
                'encryption'    => $d['encryption'],
                'from_email'    => $d['from_email'],
                'from_name'     => $d['from_name'] ?? $company->name,
                'is_active'     => true,
                'is_verified'   => true,
                'last_verified_at' => now(),
                'last_error'    => null,
            ]
        );

        return response()->json(['message' => 'Email connected.', 'integration' => $integration->makeHidden(['smtp_password'])], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $integration = EmailIntegration::where('company_id', auth()->user()->company_id)->firstOrFail();
        $d = $request->validate([
            'from_name' => ['sometimes', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $integration->update($d);
        return response()->json(['integration' => $integration->fresh()->makeHidden(['smtp_password'])]);
    }

    public function destroy(): JsonResponse
    {
        EmailIntegration::where('company_id', auth()->user()->company_id)->delete();
        return response()->json(['message' => 'Email integration disconnected.']);
    }

    // ── Sending ──────────────────────────────────────────────────────────

    /** One-off alert/notification — used both by staff (from a contact's page) and internally. */
    public function send(Request $request): JsonResponse
    {
        $d = $request->validate([
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'to_email'   => ['required_without:contact_id', 'nullable', 'email'],
            'subject'    => ['required', 'string', 'max:200'],
            'body'       => ['required', 'string', 'max:20000'],
            'kind'       => ['nullable', Rule::in(['alert', 'notification', 'announcement'])],
        ]);
        $company = $this->company();

        $toEmail = $d['to_email'] ?? null;
        $toName  = null;
        if (!empty($d['contact_id'])) {
            $contact = Contact::where('id', $d['contact_id'])->where('company_id', $company->id)->firstOrFail();
            $toEmail = $toEmail ?: $contact->email;
            $toName  = $contact->name;
            if (!$toEmail) {
                return response()->json(['message' => 'This contact has no email address on file.'], 422);
            }
        }

        $result = $this->mailer->send($company, $toEmail, $toName, $d['subject'], nl2br(e($d['body'])), $d['kind'] ?? 'notification', $d['contact_id'] ?? null);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Announcement broadcast — same message to every contact in the given list (or all contacts with an email on file). */
    public function broadcast(Request $request): JsonResponse
    {
        $d = $request->validate([
            'contact_ids' => ['nullable', 'array'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
            'subject'    => ['required', 'string', 'max:200'],
            'body'       => ['required', 'string', 'max:20000'],
        ]);
        $company = $this->company();

        $contacts = Contact::where('company_id', $company->id)
            ->whereNotNull('email')
            ->when(!empty($d['contact_ids']), fn ($q) => $q->whereIn('id', $d['contact_ids']))
            ->get(['id', 'name', 'email']);

        $sent = 0; $failed = 0;
        foreach ($contacts as $contact) {
            $result = $this->mailer->send($company, $contact->email, $contact->name, $d['subject'], nl2br(e($d['body'])), 'announcement', $contact->id);
            $result['success'] ? $sent++ : $failed++;
        }

        return response()->json(['message' => "Sent to {$sent} contact(s)" . ($failed ? ", {$failed} failed." : '.'), 'sent' => $sent, 'failed' => $failed]);
    }

    public function logs(Request $request): JsonResponse
    {
        $logs = EmailLog::where('company_id', auth()->user()->company_id)
            ->orderByDesc('created_at')
            ->paginate(30);
        return response()->json($logs);
    }

    // ── helpers ──

    private function rules(Request $request, bool $requireCreds): array
    {
        return $request->validate([
            'smtp_host'     => [$requireCreds ? 'required' : 'sometimes', 'string', 'max:200'],
            'smtp_port'     => [$requireCreds ? 'required' : 'sometimes', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => [$requireCreds ? 'required' : 'sometimes', 'string', 'max:200'],
            'smtp_password' => [$requireCreds ? 'required' : 'sometimes', 'string', 'max:500'],
            'encryption'    => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'from_email'    => [$requireCreds ? 'required' : 'sometimes', 'email'],
            'from_name'     => ['nullable', 'string', 'max:150'],
            'test_to'       => ['nullable', 'email'],
        ]) + ['encryption' => $request->input('encryption', 'tls')];
    }

    private function company(): Company
    {
        return Company::findOrFail(auth()->user()->company_id);
    }
}
