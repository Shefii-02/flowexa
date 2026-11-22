<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** GET/POST/DELETE hr/documents/me — a staff member's own documents. Each row is scoped to the signed-in user, nobody else's. */
class StaffDocumentController extends Controller
{
    private const CATEGORIES = 'identity,address,education,experience,contract,other';

    private function mine()
    {
        $u = auth()->user();

        return DB::table('hr_staff_documents')->where('company_id', $u->company_id)->where('user_id', $u->id);
    }

    private function shape($d): array
    {
        return [
            'id' => $d->id, 'title' => $d->title, 'category' => $d->category, 'mime' => $d->mime, 'size' => (int) $d->size,
            'url' => Storage::disk('public')->url($d->path), 'expires_on' => $d->expires_on, 'created_at' => $d->created_at,
        ];
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->mine()->orderByDesc('id')->get()->map(fn ($d) => $this->shape($d))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'title'      => ['required', 'string', 'max:150'],
            'category'   => ['nullable', 'in:' . self::CATEGORIES],
            'expires_on' => ['nullable', 'date'],
            'file'       => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ]);
        $u = auth()->user();
        $file = $request->file('file');
        $id = DB::table('hr_staff_documents')->insertGetId([
            'company_id' => $u->company_id, 'user_id' => $u->id, 'title' => $d['title'], 'category' => $d['category'] ?? 'other',
            'path' => $file->store("staff-documents/{$u->company_id}/{$u->id}", 'public'), 'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(), 'expires_on' => $d['expires_on'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->shape(DB::table('hr_staff_documents')->find($id))], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $doc = $this->mine()->where('id', $id)->first();
        abort_unless($doc, 404);
        Storage::disk('public')->delete($doc->path);
        DB::table('hr_staff_documents')->where('id', $id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
