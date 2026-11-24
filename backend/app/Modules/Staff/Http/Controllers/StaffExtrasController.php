<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Storage;

/**
 * Staff profile extras kept next to the staff form (all need staff.manage):
 *   designations list · document types list · a person's documents (upload / delete) ·
 *   PUT /staff/{id}/extras — designation, joining date and "accessible users" (whose leads this person may also see).
 */
class StaffExtrasController extends Controller
{
    private const DEFAULT_DOCUMENT_TYPES = ['ID proof', 'Address proof', 'Education certificate', 'Experience letter', 'Offer / contract', 'Other'];

    private function cid(): int { return (int) auth()->user()->company_id; }

    private function person(int $id): User { return User::where('company_id', $this->cid())->findOrFail($id); }

    // ── designations ────────────────────────────────────────────────────────────
    public function designations(): JsonResponse
    {
        return response()->json(['data' => DB::table('staff_designations')->where('company_id', $this->cid())->orderBy('name')->get(['id', 'name'])]);
    }

    public function storeDesignation(Request $r): JsonResponse
    {
        $name = trim($r->validate(['name' => ['required', 'string', 'max:100']])['name']);
        DB::table('staff_designations')->updateOrInsert(['company_id' => $this->cid(), 'name' => $name], ['updated_at' => now(), 'created_at' => now()]);

        return $this->designations();
    }

    public function destroyDesignation(int $id): JsonResponse
    {
        DB::table('staff_designations')->where('company_id', $this->cid())->where('id', $id)->delete();

        return $this->designations();
    }

    // ── document types ─────────────────────────────────────────────────────────
    public function documentTypes(): JsonResponse
    {
        $t = fn () => DB::table('staff_document_types')->where('company_id', $this->cid());
        if (! $t()->exists()) {   // first use: seed the usual ones
            foreach (self::DEFAULT_DOCUMENT_TYPES as $n) {
                DB::table('staff_document_types')->insert(['company_id' => $this->cid(), 'name' => $n, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return response()->json(['data' => $t()->orderBy('name')->get(['id', 'name'])]);
    }

    public function storeDocumentType(Request $r): JsonResponse
    {
        $name = trim($r->validate(['name' => ['required', 'string', 'max:30']])['name']);   // hr_staff_documents.category is 30 chars
        DB::table('staff_document_types')->updateOrInsert(['company_id' => $this->cid(), 'name' => $name], ['updated_at' => now(), 'created_at' => now()]);

        return $this->documentTypes();
    }

    public function destroyDocumentType(int $id): JsonResponse
    {
        DB::table('staff_document_types')->where('company_id', $this->cid())->where('id', $id)->delete();

        return $this->documentTypes();
    }

    // ── a person's documents ───────────────────────────────────────────────────
    public function documents(int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $rows = DB::table('hr_staff_documents')->where('company_id', $this->cid())->where('user_id', $u->id)->orderByDesc('id')->get()
            ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'type' => $d->category, 'mime' => $d->mime, 'size' => (int) $d->size,
                'url' => Storage::disk('public')->url($d->path), 'created_at' => $d->created_at]);

        return response()->json(['data' => $rows]);
    }

    public function uploadDocument(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $d = $r->validate([
            'type' => ['required', 'string', 'max:30'],
            'title' => ['nullable', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ]);
        $file = $r->file('file');
        DB::table('hr_staff_documents')->insert([
            'company_id' => $this->cid(), 'user_id' => $u->id, 'title' => $d['title'] ?: $d['type'], 'category' => $d['type'],
            'path' => $file->store("staff-documents/{$this->cid()}/{$u->id}", 'public'), 'mime' => $file->getClientMimeType(), 'size' => $file->getSize(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->documents($staff);
    }

    public function destroyDocument(int $staff, int $id): JsonResponse
    {
        $u = $this->person($staff);
        $doc = DB::table('hr_staff_documents')->where('company_id', $this->cid())->where('user_id', $u->id)->where('id', $id)->first();
        abort_unless($doc, 404);
        Storage::disk('public')->delete($doc->path);
        DB::table('hr_staff_documents')->where('id', $id)->delete();

        return $this->documents($staff);
    }

    // ── designation / joining date / accessible users ──────────────────────────
    public function show(int $staff): JsonResponse
    {
        $u = $this->person($staff);

        return response()->json(['designation' => $u->designation, 'joined_on' => $u->joined_on?->toDateString(), 'accessible_user_ids' => array_values((array) ($u->accessible_user_ids ?? []))]);
    }

    public function update(Request $r, int $staff): JsonResponse
    {
        $u = $this->person($staff);
        $d = $r->validate([
            'designation' => ['nullable', 'string', 'max:100'],
            'joined_on' => ['nullable', 'date'],
            'accessible_user_ids' => ['nullable', 'array', 'max:200'],
            'accessible_user_ids.*' => ['integer'],
        ]);
        $ids = User::where('company_id', $this->cid())->whereIn('id', $d['accessible_user_ids'] ?? [])->where('id', '!=', $u->id)->pluck('id')->all();
        $u->update(['designation' => $d['designation'] ?? null, 'joined_on' => $d['joined_on'] ?? null, 'accessible_user_ids' => $ids]);
        if (! empty($d['designation'])) {   // a designation typed in once joins the company's list
            DB::table('staff_designations')->updateOrInsert(['company_id' => $this->cid(), 'name' => trim($d['designation'])], ['updated_at' => now(), 'created_at' => now()]);
        }

        return $this->show($staff);
    }

    /**
     * POST /staff/{staff}/login-as — the owner (or anyone granted `staff.login_as`) signs in as one of their staff (to see exactly what that person
     * sees, or help them). Returns a token for that user; the web keeps the owner's own token to switch back.
     */
    public function loginAs(Request $request, int $staff): JsonResponse
    {
        $me = auth()->user();
        if (!$me->isOwner() && !$me->hasPermission('staff.login_as')) return response()->json(['message' => 'You do not have permission to sign in as staff.'], 403);

        $target = User::where('company_id', $me->company_id)->with('role')->findOrFail($staff);
        if ($target->id === $me->id) return response()->json(['message' => 'That is your own account.'], 422);
        if ($target->isOwner())      return response()->json(['message' => 'You cannot sign in as another owner.'], 422);
        if (!$target->is_active)     return response()->json(['message' => 'Activate this staff member first.'], 422);
        // no climbing the ladder: someone who can sign in as staff can't sign in as another person who can
        if (!$me->isOwner() && $target->hasPermission('staff.login_as')) return response()->json(['message' => 'You cannot sign in as this person.'], 403);

        Log::info('staff.login_as', ['by' => $me->id, 'as' => $target->id, 'company' => $me->company_id]);
        return response()->json([
            'message'      => "Signed in as {$target->name}.",
            'access_token' => JWTAuth::fromUser($target),
            'name'         => $target->name,
        ]);
    }
}
