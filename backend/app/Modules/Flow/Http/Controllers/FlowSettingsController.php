<?php

namespace App\Modules\Flow\Http\Controllers;

use App\Models\FlowBuilder;
use App\Modules\Flow\Support\FlowMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Per-flow bot messages and timings: resolved check, no-response follow-up, session timeout. */
class FlowSettingsController extends Controller
{
    private function builder(int $id): FlowBuilder
    {
        return FlowBuilder::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }

    // GET /flow-builders/{id}/settings
    public function show(int $id): JsonResponse
    {
        $b = $this->builder($id);
        return response()->json(['settings' => FlowMessages::merge($b->settings ?? []), 'defaults' => FlowMessages::DEFAULTS]);
    }

    // PUT /flow-builders/{id}/settings
    public function update(Request $request, int $id): JsonResponse
    {
        $b = $this->builder($id);

        $d = $request->validate([
            'nosession_action'       => ['nullable', 'in:message,welcome,both'],
            'unknown_message'        => ['nullable', 'string', 'max:1024'],
            'resolved_enabled'       => ['sometimes', 'boolean'],
            'resolved_question'      => ['nullable', 'string', 'max:1024'],
            'resolved_yes_label'     => ['nullable', 'string', 'max:20'],   // WhatsApp reply-button title limit
            'resolved_no_label'      => ['nullable', 'string', 'max:20'],
            'resolved_yes_reply'     => ['nullable', 'string', 'max:1024'],
            'resolved_no_reply'      => ['nullable', 'string', 'max:1024'],
            'issue_received_reply'   => ['nullable', 'string', 'max:1024'],
            'closed_reply'           => ['nullable', 'string', 'max:1024'],
            'followup_enabled'       => ['sometimes', 'boolean'],
            'followup_after_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'followup_message'       => ['nullable', 'string', 'max:1024'],
            'timeout_minutes'        => ['nullable', 'integer', 'min:5', 'max:1440'],
            'timeout_message'        => ['nullable', 'string', 'max:1024'],
        ]);

        $merged = FlowMessages::merge($d);
        if ($merged['followup_enabled'] && $merged['followup_after_minutes'] >= $merged['timeout_minutes']) {
            return response()->json(['message' => 'The follow-up must come before the session times out. Make the follow-up time shorter than the timeout.'], 422);
        }

        $b->update(['settings' => $merged]);
        return response()->json(['message' => 'Saved.', 'settings' => $merged]);
    }
}
