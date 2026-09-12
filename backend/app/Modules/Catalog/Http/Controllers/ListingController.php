<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Modules\Catalog\IndustryTemplates;
use App\Modules\Catalog\ListingMatcher;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListingController extends Controller
{
    public function __construct(private readonly ListingMatcher $matcher) {}

    /** Templates + this company's chosen vertical — powers the dynamic form. */
    public function templates(): JsonResponse
    {
        return response()->json([
            'templates' => IndustryTemplates::all(),
            'active'    => auth()->user()->company->industry_template ?? 'generic',
        ]);
    }

    public function setTemplate(Request $request): JsonResponse
    {
        $key = $request->validate([
            'industry_template' => ['required', Rule::in(IndustryTemplates::keys())],
        ])['industry_template'];
        auth()->user()->company->update(['industry_template' => $key]);
        return response()->json(['industry_template' => $key]);
    }

    public function index(Request $request): JsonResponse
    {
        $listings = Listing::where('company_id', auth()->user()->company_id)
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->q, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderBy('sort_order')->orderByDesc('updated_at')
            ->paginate(50);
        return response()->json($listings);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);
        $listing = Listing::create(array_merge($d, [
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
        ]));
        return response()->json(['message' => 'Listing added.', 'listing' => $listing], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['listing' => $this->find($id)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $listing = $this->find($id);
        $listing->update($this->validated($request, false));
        return response()->json(['message' => 'Listing updated.', 'listing' => $listing->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();
        return response()->json(['message' => 'Listing deleted.']);
    }

    /** CSV export — same filters as index(). Structured fields (attributes/media) travel as JSON columns so a full round-trip (export → edit → import) is possible. */
    public function export(Request $request): StreamedResponse
    {
        $listings = Listing::where('company_id', auth()->user()->company_id)
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->q, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderBy('sort_order')->orderByDesc('updated_at')
            ->get();

        $filename = 'listings_export_' . now()->format('Ymd_His') . '.csv';
        $path     = 'exports/' . $filename;
        if (!Storage::exists('exports')) {
            Storage::makeDirectory('exports');
        }

        $handle = fopen(Storage::path($path), 'w');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open export file for writing: {$path}");
        }

        fputcsv($handle, ['id', 'type', 'title', 'description', 'status', 'price', 'price_unit', 'currency', 'location', 'incentive_percentage', 'attributes_json', 'media_urls', 'created_at']);
        foreach ($listings as $l) {
            fputcsv($handle, [
                $l->id, $l->type, $l->title, $l->description, $l->status,
                $l->price, $l->price_unit, $l->currency, $l->location, $l->incentive_percentage,
                $l->attributes ? json_encode($l->attributes) : '',
                $l->media ? implode('|', array_filter(array_column($l->media, 'url'))) : '',
                $l->created_at?->toDateTimeString(),
            ]);
        }
        fclose($handle);

        return Storage::download($path, 'listings.csv');
    }

    /** CSV import — matches export()'s column set. Skips a row if a listing with the same (type, title) already exists. */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);
        $companyId    = auth()->user()->company_id;
        $defaultType  = IndustryTemplates::listingType(auth()->user()->company->industry_template ?? null);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Unable to open the uploaded file.'], 422);
        }

        $headers = array_map('trim', fgetcsv($handle));
        $imported = 0; $skipped = 0; $failed = 0; $errors = []; $row = 0;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;
            if (count($line) < count($headers)) { $skipped++; continue; }
            $data = array_combine($headers, $line);
            $title = trim($data['title'] ?? '');
            if (!$title) { $errors[] = "Row {$row}: missing title"; $failed++; continue; }

            $type = trim($data['type'] ?? '') ?: $defaultType;

            try {
                $exists = Listing::where('company_id', $companyId)
                    ->where('type', $type)
                    ->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])
                    ->exists();
                if ($exists) { $skipped++; continue; }

                $attributes = null;
                if (!empty($data['attributes_json'])) {
                    $decoded = json_decode($data['attributes_json'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) { $errors[] = "Row {$row}: attributes_json is not valid JSON"; $failed++; continue; }
                    $attributes = $decoded;
                }

                $media = null;
                if (!empty($data['media_urls'])) {
                    $media = collect(preg_split('/[|,]/', $data['media_urls']))
                        ->map(fn ($u) => trim($u))->filter()->map(fn ($u) => ['url' => $u])->values()->all();
                }

                Listing::create([
                    'company_id'   => $companyId,
                    'created_by'   => auth()->id(),
                    'type'         => $type,
                    'title'        => $title,
                    'description'  => $data['description'] ?? null,
                    'status'       => in_array($data['status'] ?? null, ['draft', 'active', 'inactive', 'sold'], true) ? $data['status'] : 'active',
                    'price'        => is_numeric($data['price'] ?? null) ? $data['price'] : null,
                    'price_unit'   => $data['price_unit'] ?? null,
                    'currency'     => $data['currency'] ?? 'INR',
                    'location'     => $data['location'] ?? null,
                    'incentive_percentage' => is_numeric($data['incentive_percentage'] ?? null) ? $data['incentive_percentage'] : null,
                    'attributes'   => $attributes,
                    'media'        => $media,
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$row}: " . $e->getMessage();
                $failed++;
            }
        }
        fclose($handle);

        return response()->json([
            'message'  => "{$imported} imported, {$skipped} skipped, {$failed} failed.",
            'imported' => $imported, 'skipped' => $skipped, 'failed' => $failed,
            'errors'   => array_slice($errors, 0, 50),
        ], 202);
    }

    /** Dry-run the matcher (used by the agent, and to preview from the dashboard). */
    public function match(Request $request): JsonResponse
    {
        $requirements = $request->validate([
            'requirements' => ['required', 'array'],
        ])['requirements'];

        $company = auth()->user()->company;
        $results = $this->matcher->match($company->id, $company->industry_template, $requirements, $request->integer('limit', 5) ?: 5);

        return response()->json([
            'matches' => $results->map(fn ($r) => [
                'listing' => $r['listing'],
                'score'   => $r['score'],
                'reasons' => $r['reasons'],
            ]),
        ]);
    }

    private function validated(Request $request, bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return $request->validate([
            'type'         => [$req, 'string', 'max:20'],
            'title'        => [$req, 'string', 'max:200'],
            'description'  => ['nullable', 'string', 'max:5000'],
            'status'       => ['sometimes', Rule::in(['draft', 'active', 'inactive', 'sold'])],
            'price'        => ['nullable', 'numeric', 'min:0'],
            'incentive_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'price_unit'   => ['nullable', 'string', 'max:20'],
            'currency'     => ['sometimes', 'string', 'max:8'],
            'location'     => ['nullable', 'string', 'max:160'],
            'attributes'   => ['nullable', 'array'],
            'media'        => ['nullable', 'array'],
            'media.*.url'  => ['required_with:media', 'string'],
            'sort_order'   => ['sometimes', 'integer'],
        ]);
    }

    private function find(int $id): Listing
    {
        return Listing::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }
}
