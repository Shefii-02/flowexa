<?php

namespace App\Modules\ShortLink\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ShortLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Company-scoped CRUD for custom short links (public redirect lives in ShortLinkRedirectController). */
class ShortLinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $links = ShortLink::where('company_id', auth()->user()->company_id)
            ->when($request->search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$request->search}%")
                ->orWhere('title', 'like', "%{$request->search}%")
                ->orWhere('target_url', 'like', "%{$request->search}%")))
            ->latest()
            ->paginate((int) $request->input('per_page', 20));

        return response()->json($links);
    }

    /** Live availability check for the "custom short code" box (codes are unique across all companies). */
    public function checkCode(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code', ''));
        if (!preg_match('/^[A-Za-z0-9_-]{3,40}$/', $code)) {
            return response()->json(['available' => false, 'message' => 'Use 3–40 letters, numbers, - or _.']);
        }
        $taken = ShortLink::where('code', $code)
            ->when($request->query('ignore_id'), fn ($q, $id) => $q->where('id', '!=', (int) $id))
            ->exists();

        return response()->json([
            'available' => !$taken,
            'message'   => $taken ? 'That short code is already taken — choose another.' : 'Available',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);

        $link = ShortLink::create([
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
            'code'       => $d['code'] ?? $this->generateCode(),
            'target_url' => $d['target_url'],
            'title'      => $d['title'] ?? null,
            'is_active'  => $d['is_active'] ?? true,
            'expires_at' => $d['expires_at'] ?? null,
        ]);

        return response()->json(['message' => 'Short link created.', 'link' => $link], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $link = $this->findOwned($id);
        $d = $this->validated($request, $link);

        $link->update([
            'code'       => $d['code'] ?? $link->code,
            'target_url' => $d['target_url'],
            'title'      => $d['title'] ?? null,
            'is_active'  => $d['is_active'] ?? $link->is_active,
            'expires_at' => $d['expires_at'] ?? null,
        ]);

        return response()->json(['message' => 'Short link updated.', 'link' => $link->fresh()]);
    }

    /** Day-based click analytics for one link: counted clicks per day (one per IP per day), device and referrer split. */
    public function analytics(int $id, Request $request): JsonResponse
    {
        $link = $this->findOwned($id);
        $days = max(1, min(365, (int) $request->query('days', 30)));
        $from = now()->subDays($days - 1)->toDateString();

        $base = \DB::table('short_link_clicks')->where('short_link_id', $link->id)->where('click_date', '>=', $from);

        $byDay = (clone $base)->selectRaw('click_date, count(*) as clicks')->groupBy('click_date')->pluck('clicks', 'click_date');
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $series[] = ['date' => $d, 'clicks' => (int) ($byDay[$d] ?? 0)];
        }

        return response()->json([
            'link'      => ['id' => $link->id, 'code' => $link->code, 'short_url' => $link->short_url, 'total_clicks' => $link->clicks],
            'days'      => $days,
            'period_clicks' => array_sum(array_column($series, 'clicks')),
            'series'    => $series,
            'devices'   => (clone $base)->selectRaw('device, count(*) as clicks')->groupBy('device')->orderByDesc('clicks')->get(),
            'referrers' => (clone $base)->selectRaw("coalesce(referrer, 'Direct') as referrer, count(*) as clicks")->groupBy('referrer')->orderByDesc('clicks')->limit(10)->get(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->findOwned($id)->delete();
        return response()->json(['message' => 'Short link deleted.']);
    }

    private function findOwned(int $id): ShortLink
    {
        return ShortLink::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }

    private function validated(Request $request, ?ShortLink $existing = null): array
    {
        return $request->validate([
            'target_url' => ['required', 'url:http,https', 'max:2000'],
            'code'       => ['nullable', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                             Rule::unique('short_links', 'code')->ignore($existing?->id)],
            'title'      => ['nullable', 'string', 'max:255'],
            'is_active'  => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date'],
        ], [
            'code.unique' => 'That short code is already taken — choose another.',
            'code.regex'  => 'Short code can only contain letters, numbers, dashes and underscores.',
        ]);
    }

    private function generateCode(): string
    {
        do {
            $code = Str::lower(Str::random(6));
        } while (ShortLink::where('code', $code)->exists());

        return $code;
    }
}
