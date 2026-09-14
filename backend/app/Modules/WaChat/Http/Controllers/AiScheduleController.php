<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Models\WaPhoneNumber;
use App\Modules\WaChat\Models\AgentPlaybook;
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/**
 * Per-account "always on vs scheduled hours" AI agent control, across all three channels the
 * agent runs on. A company can have several WA Chat sessions, several WA Cloud numbers, and
 * several Instagram accounts — each gets its own row here rather than one setting for the
 * whole channel, since e.g. one WhatsApp number might need 24/7 coverage while another only
 * needs it during business hours. WA Chat and WA Cloud both resolve to an AgentPlaybook
 * (scoped by session_id, same mechanism already used for company-wide vs per-session
 * playbooks); Instagram doesn't have an equivalent scoping model, so its schedule lives
 * directly on the InstagramAccount row.
 */
class AiScheduleController extends Controller
{
    private const SCHEDULE_RULES = [
        'ai_schedule_mode'     => 'required|string|in:always,scheduled',
        // Legacy shape — kept for old clients/rows; superseded by ai_schedule_hours whenever present.
        'ai_schedule_days'     => 'nullable|array',
        'ai_schedule_days.*'   => 'integer|min:0|max:6',
        'ai_schedule_start'    => 'nullable|date_format:H:i',
        'ai_schedule_end'      => 'nullable|date_format:H:i',
        'ai_schedule_timezone' => 'nullable|string|max:64',
        // Per-day hours: { "0": {"start":"09:00","end":"18:00"}, ... } keyed 0=Sunday..6=Saturday.
        // A day missing from the map means the AI is off that day. `sometimes` (not `nullable`)
        // so sending an empty object `{}` — every day switched off — is distinguishable from not
        // sending the field at all.
        'ai_schedule_hours'                => 'sometimes|array',
        'ai_schedule_hours.*.start'        => 'required|date_format:H:i',
        'ai_schedule_hours.*.end'          => 'required|date_format:H:i',
    ];

    public function index(): JsonResponse
    {
        $companyId = Auth::user()->company_id;

        $playbooks = AgentPlaybook::where('company_id', $companyId)
            ->whereNotNull('session_id')
            ->get()
            ->keyBy('session_id');

        $default = AgentPlaybook::where('company_id', $companyId)->whereNull('session_id')->first();

        $waChat = WahaSession::where('company_id', $companyId)->get()->map(function ($s) use ($playbooks) {
            return $this->channelRow($s->session_name, $s->display_name ?: $s->session_name, $playbooks->get($s->session_name));
        })->values();

        $waCloud = WaPhoneNumber::where('company_id', $companyId)->get()->map(function ($n) use ($playbooks) {
            $label = $n->label ?: $n->display_number ?: $n->phone_number_id;
            return $this->channelRow($n->phone_number_id, $label, $playbooks->get($n->phone_number_id));
        })->values();

        $instagram = InstagramAccount::where('company_id', $companyId)->get()->map(fn (InstagramAccount $a) => [
            'id'        => $a->id,
            'label'     => $a->username ? "@{$a->username}" : ($a->name ?: 'Instagram account'),
            'schedule'  => [
                'mode'     => $a->ai_schedule_mode ?? 'always',
                'days'     => $a->ai_schedule_days,
                'start'    => $a->ai_schedule_start,
                'end'      => $a->ai_schedule_end,
                'timezone' => $a->ai_schedule_timezone,
                'hours'    => $a->ai_schedule_hours,
            ],
        ])->values();

        return response()->json([
            'wa_chat'   => $waChat,
            'wa_cloud'  => $waCloud,
            'instagram' => $instagram,
            'default_schedule' => $default ? [
                'mode'     => $default->ai_schedule_mode ?? 'always',
                'days'     => $default->ai_schedule_days,
                'start'    => $default->ai_schedule_start,
                'end'      => $default->ai_schedule_end,
                'timezone' => $default->ai_schedule_timezone,
                'hours'    => $default->ai_schedule_hours,
            ] : null,
        ]);
    }

    private function channelRow(string $sessionKey, string $label, ?AgentPlaybook $playbook): array
    {
        return [
            'session_id' => $sessionKey,
            'label'      => $label,
            'has_own_playbook' => (bool) $playbook,
            'schedule'   => [
                'mode'     => $playbook->ai_schedule_mode ?? 'always',
                'days'     => $playbook?->ai_schedule_days,
                'start'    => $playbook?->ai_schedule_start,
                'end'      => $playbook?->ai_schedule_end,
                'timezone' => $playbook?->ai_schedule_timezone,
                'hours'    => $playbook?->ai_schedule_hours,
            ],
        ];
    }

    /**
     * WA Chat and WA Cloud share this — both are just AgentPlaybook rows keyed by session_id.
     * If this session doesn't have its own playbook yet, one is cloned from the company-wide
     * default so the schedule has somewhere to live without silently changing every other
     * session's behavior too.
     */
    public function updateWaSchedule(Request $request, string $sessionId): JsonResponse
    {
        $data      = $request->validate(self::SCHEDULE_RULES);
        $companyId = Auth::user()->company_id;

        $playbook = AgentPlaybook::where('company_id', $companyId)->where('session_id', $sessionId)->first();

        if (!$playbook) {
            $default = AgentPlaybook::where('company_id', $companyId)->whereNull('session_id')->first();
            $base    = $default ? Arr::only($default->toArray(), [
                'template_key', 'business_type', 'agent_name', 'tone', 'languages', 'system_prompt',
                'greeting_new', 'greeting_returning', 'closing_message', 'fallback_transfer_message',
                'qualification_questions', 'handoff', 'escalation', 'payment',
            ]) : [];

            $playbook = AgentPlaybook::create(array_merge($base, [
                'company_id' => $companyId,
                'session_id' => $sessionId,
                'is_active'  => true,
            ]));
        }

        $playbook->update($data);

        return response()->json(['message' => 'Saved.', 'data' => $playbook]);
    }

    public function updateInstagramSchedule(Request $request, int $accountId): JsonResponse
    {
        $data = $request->validate(self::SCHEDULE_RULES);

        $account = InstagramAccount::where('company_id', Auth::user()->company_id)->findOrFail($accountId);
        $account->update($data);

        return response()->json(['message' => 'Saved.', 'data' => $account]);
    }
}
