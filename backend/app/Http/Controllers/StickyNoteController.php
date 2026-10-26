<?php

namespace App\Http\Controllers;

use App\Models\StickyNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The floating sticky-notes widget. Everything is scoped to the signed-in USER (not just the company): each person
 * has their own notes, colours, positions and circle placement.
 */
class StickyNoteController extends Controller
{
    private const MAX_NOTES = 30;

    private function mine()
    {
        $u = auth()->user();
        return StickyNote::where('company_id', $u->company_id)->where('user_id', $u->id);
    }

    private function settings(): array
    {
        $r = DB::table('sticky_note_settings')->where('user_id', auth()->id())->first();
        return [
            'bubble_x'      => $r->bubble_x ?? null,
            'bubble_y'      => $r->bubble_y ?? null,
            'notes_visible' => (bool) ($r->notes_visible ?? false),
            'default_color' => $r->default_color ?? '#fde68a',
            'todo_visible'  => (bool) ($r->todo_visible ?? false),
            'todo_x'        => $r->todo_x ?? null,
            'todo_y'        => $r->todo_y ?? null,
            'todo_bubble_x' => $r->todo_bubble_x ?? null,
            'todo_bubble_y' => $r->todo_bubble_y ?? null,
            'notes_enabled' => (bool) ($r->notes_enabled ?? true),
            'todo_enabled'  => (bool) ($r->todo_enabled ?? true),
            'assistant_enabled' => (bool) ($r->assistant_enabled ?? true),
        ];
    }

    // GET /sticky-notes
    public function index(): JsonResponse
    {
        return response()->json(['notes' => $this->mine()->orderBy('sort_order')->orderBy('id')->get(), 'settings' => $this->settings()]);
    }

    // POST /sticky-notes
    public function store(Request $request): JsonResponse
    {
        $u = auth()->user();
        if ($this->mine()->count() >= self::MAX_NOTES) {
            return response()->json(['message' => 'You can keep up to ' . self::MAX_NOTES . ' sticky notes. Delete one first.'], 422);
        }
        $d = $request->validate($this->rules());
        $top = (int) $this->mine()->max('sort_order');
        $count = $this->mine()->count();
        $note = StickyNote::create([
            'company_id' => $u->company_id, 'user_id' => $u->id,
            'title' => $d['title'] ?? null, 'content' => $d['content'] ?? null,
            'color' => $d['color'] ?? $this->settings()['default_color'],
            // new notes fan out a little so they do not land exactly on top of each other
            'x' => $d['x'] ?? 120 + ($count % 8) * 28, 'y' => $d['y'] ?? 120 + ($count % 8) * 28,
            'width' => $d['width'] ?? 240, 'height' => $d['height'] ?? 200, 'minimized' => false, 'sort_order' => $top + 1,
        ]);
        return response()->json(['note' => $note], 201);
    }

    // PUT /sticky-notes/{id}  — text, colour, position, size, folded state; also "bring to front"
    public function update(Request $request, int $id): JsonResponse
    {
        $note = $this->mine()->where('id', $id)->firstOrFail();
        $d = $request->validate($this->rules() + ['bring_to_front' => ['sometimes', 'boolean']]);
        if (!empty($d['bring_to_front'])) {
            $d['sort_order'] = (int) $this->mine()->max('sort_order') + 1;
        }
        unset($d['bring_to_front']);
        $note->update($d);
        return response()->json(['note' => $note->fresh()]);
    }

    // DELETE /sticky-notes/{id}
    public function destroy(int $id): JsonResponse
    {
        $this->mine()->where('id', $id)->firstOrFail()->delete();
        return response()->json(['message' => 'Note deleted.']);
    }

    // PUT /sticky-notes/settings  — circle position, notes shown / hidden, default colour for new notes
    public function updateSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'bubble_x'      => ['sometimes', 'nullable', 'integer', 'min:-200', 'max:10000'],
            'bubble_y'      => ['sometimes', 'nullable', 'integer', 'min:-200', 'max:10000'],
            'notes_visible' => ['sometimes', 'boolean'],
            'default_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'todo_visible'  => ['sometimes', 'boolean'],
            'todo_x'        => ['sometimes', 'nullable', 'integer', 'min:-200', 'max:10000'],
            'todo_y'        => ['sometimes', 'nullable', 'integer', 'min:-200', 'max:10000'],
            'todo_bubble_x' => ['sometimes', 'nullable', 'integer', 'min:-200', 'max:10000'],
            'todo_bubble_y' => ['sometimes', 'nullable', 'integer', 'min:-200', 'max:10000'],
            'notes_enabled' => ['sometimes', 'boolean'],
            'todo_enabled'  => ['sometimes', 'boolean'],
            'assistant_enabled' => ['sometimes', 'boolean'],
        ]);
        $uid = auth()->id();
        $exists = DB::table('sticky_note_settings')->where('user_id', $uid)->exists();
        $d['updated_at'] = now();
        if ($exists) { DB::table('sticky_note_settings')->where('user_id', $uid)->update($d); }
        else { DB::table('sticky_note_settings')->insert($d + ['user_id' => $uid, 'created_at' => now()]); }
        return response()->json(['settings' => $this->settings()]);
    }

    private function rules(): array
    {
        return [
            'title'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'content' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'color'   => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'x'       => ['sometimes', 'integer', 'min:-2000', 'max:10000'],
            'y'       => ['sometimes', 'integer', 'min:-2000', 'max:10000'],
            'width'   => ['sometimes', 'integer', 'min:160', 'max:800'],
            'height'  => ['sometimes', 'integer', 'min:100', 'max:800'],
            'minimized' => ['sometimes', 'boolean'],
        ];
    }
}
