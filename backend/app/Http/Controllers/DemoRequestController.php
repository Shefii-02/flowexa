<?php

namespace App\Http\Controllers;

use App\Models\DemoRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DemoRequestController extends Controller
{
    // ─── POST /demo-requests (public) ─────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        // Honeypot: real users never fill the hidden "website" field. Pretend success so bots don't retry.
        if (filled($request->input('website'))) {
            return response()->json(['message' => 'Thanks! We will contact you shortly.'], 201);
        }

        $d = $request->validate([
            'name'           => ['required', 'string', 'max:100'],
            'company_name'   => ['required', 'string', 'max:150'],
            'email'          => ['required', 'email:rfc', 'max:150'],
            'phone'          => ['required', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'business_type'  => ['nullable', 'string', 'max:40'],
            'team_size'      => ['nullable', 'string', 'max:20'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'preferred_slot' => ['nullable', Rule::in(['morning', 'afternoon', 'evening'])],
            'message'        => ['nullable', 'string', 'max:1000'],
            'source'         => ['nullable', 'string', 'max:100'],
        ]);

        // Same email asking again within a day → one lead, not a pile of duplicates.
        $existing = DemoRequest::where('email', $d['email'])->where('created_at', '>=', now()->subDay())->first();
        if (!$existing) {
            DemoRequest::create($d + ['ip' => $request->ip()]);
        }

        return response()->json(['message' => 'Thanks! We will contact you shortly to confirm your demo.'], 201);
    }

    // ─── GET /superadmin/demo-requests ────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $q = DemoRequest::with('assignee:id,name')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->assigned_to, fn($q, $a) => $a === 'none' ? $q->whereNull('assigned_to') : $q->where('assigned_to', $a))
            ->when($request->search, function ($q, $s) {
                $q->where(fn($w) => $w->where('name', 'like', "%$s%")->orWhere('company_name', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")->orWhere('phone', 'like', "%$s%"));
            })
            ->latest();

        $counts = DemoRequest::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json([
            'requests' => $q->paginate(20),
            'counts'   => collect(DemoRequest::STATUSES)->mapWithKeys(fn($s) => [$s => (int) ($counts[$s] ?? 0)]),
            'staff'    => User::whereHas('role', fn($r) => $r->whereIn('name', ['superadmin', 'superadmin_staff']))->get(['id', 'name']),
        ]);
    }

    // ─── PUT /superadmin/demo-requests/{id} ───────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $req = DemoRequest::findOrFail($id);
        $d = $request->validate([
            'status'            => ['sometimes', Rule::in(DemoRequest::STATUSES)],
            'assigned_to'       => ['sometimes', 'nullable', 'exists:users,id'],
            'scheduled_at'      => ['sometimes', 'nullable', 'date'],
            'notes'             => ['sometimes', 'nullable', 'string', 'max:5000'],
            'last_contacted_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (($d['status'] ?? null) === 'contacted' && !$req->last_contacted_at && !array_key_exists('last_contacted_at', $d)) {
            $d['last_contacted_at'] = now();
        }
        $req->update($d);

        return response()->json(['message' => 'Updated.', 'request' => $req->load('assignee:id,name')]);
    }

    // ─── DELETE /superadmin/demo-requests/{id} ────────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        DemoRequest::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted.']);
    }
}
