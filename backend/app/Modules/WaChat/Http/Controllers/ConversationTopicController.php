<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ConversationTopic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Review what WA Chat conversations are about (shadow mode): the list with counts, a staff correction, and the per-company switches. */
class ConversationTopicController extends Controller
{
    /** GET /waha/topics?topic=&source=&search=&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['topic' => ['nullable', Rule::in(array_keys(ConversationTopic::TOPICS))], 'source' => ['nullable', 'in:rule,ai,staff'],
            'search' => ['nullable', 'string', 'max:80'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100'], 'low_confidence' => ['nullable', 'boolean']]);
        $user = auth()->user();
        $allowed = $user->allowedAccountIds('wa_session');     // staff limited to some WA sessions only see those chats

        $base = ConversationTopic::where('company_id', $user->company_id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('session_id', $allowed))
            ->when($d['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('phone', 'like', "%{$s}%")->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->when($d['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->when($request->boolean('low_confidence'), fn ($q) => $q->where('confidence', '<', 0.6));

        $counts = (clone $base)->selectRaw('topic, count(*) c')->groupBy('topic')->pluck('c', 'topic');
        $page = $base->when($d['topic'] ?? null, fn ($q, $t) => $q->where('topic', $t))->with('contact:id,name,phone')
            ->orderByDesc('classified_at')->paginate((int) ($d['per_page'] ?? 25));

        $page->getCollection()->transform(fn (ConversationTopic $t) => [
            'id' => $t->id, 'chat_id' => $t->chat_id, 'phone' => $t->phone, 'contact' => $t->contact ? ['id' => $t->contact->id, 'name' => $t->contact->name] : null,
            'topic' => $t->topic, 'label' => ConversationTopic::TOPICS[$t->topic] ?? $t->topic, 'confidence' => $t->confidence, 'reason' => $t->reason,
            'language' => $t->language, 'source' => $t->source, 'message_count' => $t->message_count, 'classified_at' => $t->classified_at?->toIso8601String(),
        ]);

        return response()->json($page->toArray() + [
            'counts' => collect(ConversationTopic::TOPICS)->map(fn ($label, $k) => ['topic' => $k, 'label' => $label, 'count' => (int) ($counts[$k] ?? 0)])->values(),
        ]);
    }

    /** PUT /waha/topics/{id} {topic} — a person corrects the label; it then stays put. */
    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['topic' => ['required', Rule::in(array_keys(ConversationTopic::TOPICS))]]);
        $row = ConversationTopic::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $row->update(['topic' => $d['topic'], 'source' => 'staff', 'confidence' => 1, 'reason' => 'Set by ' . auth()->user()->name, 'classified_at' => now()]);
        return response()->json(['message' => 'Topic updated.', 'topic' => $row->topic]);
    }

    /** GET /waha/topics/settings */
    public function settings(): JsonResponse
    {
        return response()->json(['data' => auth()->user()->company->topicSettings() + ['topics' => ConversationTopic::TOPICS]]);
    }

    /** PUT /waha/topics/settings {enabled?, use_ai?, ai_daily_cap?} */
    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['enabled' => ['sometimes', 'boolean'], 'use_ai' => ['sometimes', 'boolean'], 'ai_daily_cap' => ['sometimes', 'integer', 'min:0', 'max:5000']]);
        $company = auth()->user()->company;
        $settings = (array) $company->settings;
        $settings['topic_classifier'] = array_merge($company->topicSettings(), $d);
        $company->forceFill(['settings' => $settings])->save();
        return response()->json(['message' => 'Saved.', 'data' => $company->topicSettings()]);
    }
}
