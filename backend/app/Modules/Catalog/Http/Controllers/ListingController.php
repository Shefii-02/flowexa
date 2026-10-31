<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingCustomField;
use App\Modules\Catalog\IndustryTemplates;
use App\Modules\Catalog\ListingMatcher;
use App\Modules\Catalog\Support\ListingCsv;
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
            ->with('customFields:id,listing_id,key,value')
            ->orderBy('sort_order')->orderByDesc('updated_at')
            ->paginate(50);
        return response()->json($listings);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);
        $customFields = $d['custom_fields'] ?? null;
        unset($d['custom_fields']);

        $listing = Listing::create(array_merge($d, [
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
        ]));
        $this->syncCustomFields($listing, $customFields);

        return response()->json(['message' => 'Listing added.', 'listing' => $listing->load('customFields')], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['listing' => $this->find($id)->load('customFields')]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $listing = $this->find($id);
        $d = $this->validated($request, false);
        $customFields = $d['custom_fields'] ?? null;
        unset($d['custom_fields']);

        $listing->update($d);
        if ($request->has('custom_fields')) {
            $this->syncCustomFields($listing, $customFields);
        }

        return response()->json(['message' => 'Listing updated.', 'listing' => $listing->fresh()->load('customFields')]);
    }

    /**
     * Replaces this listing's extra key/value fields wholesale with $fields (each
     * {key, value}) — the editor always sends its full current set, so a full sync (rather than
     * a diff) keeps this simple and correct even when a field was renamed or removed.
     */
    private function syncCustomFields(Listing $listing, ?array $fields): void
    {
        if ($fields === null) {
            return;
        }
        $listing->customFields()->delete();

        $rows = collect($fields)
            ->map(fn ($f) => ['key' => trim((string) ($f['key'] ?? '')), 'value' => $f['value'] ?? null])
            ->filter(fn ($f) => $f['key'] !== '')
            ->unique('key')
            ->values();

        if ($rows->isEmpty()) {
            return;
        }

        $now = now();
        $listing->customFields()->insert($rows->map(fn ($f, $i) => [
            'listing_id' => $listing->id,
            'company_id' => $listing->company_id,
            'key'        => $f['key'],
            'value'      => $f['value'],
            'sort_order' => $i,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
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
            ->with('customFields:id,listing_id,key,value')
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

        fputcsv($handle, ['id', 'type', 'title', 'description', 'status', 'price', 'price_unit', 'currency', 'location', 'incentive_percentage', 'attributes_json', 'custom_fields_json', 'media_urls', 'created_at']);
        foreach ($listings as $l) {
            fputcsv($handle, [
                $l->id, $l->type, $l->title, $l->description, $l->status,
                $l->price, $l->price_unit, $l->currency, $l->location, $l->incentive_percentage,
                $l->attributes ? json_encode($l->attributes) : '',
                $l->customFields->isNotEmpty() ? json_encode($l->customFields->map->only(['key', 'value'])->all()) : '',
                $l->media ? implode('|', array_filter(array_column($l->media, 'url'))) : '',
                $l->created_at?->toDateTimeString(),
            ]);
        }
        fclose($handle);

        return Storage::download($path, 'listings.csv');
    }

    private function companyTemplate(): array
    {
        return IndustryTemplates::get(auth()->user()->company->industry_template ?? null);
    }

    /** The columns this company's business type uses in an import file — shown next to the upload box. */
    public function importColumns(): JsonResponse
    {
        $t = $this->companyTemplate();
        return response()->json(['business_type' => $t['name'] ?? 'Other business', 'listing_type' => $t['listing_type'] ?? 'product', 'columns' => ListingCsv::columns($t)]);
    }

    /** A ready-to-fill sample file for this company's business type (header + two example rows). */
    public function importTemplate(): \Symfony\Component\HttpFoundation\Response
    {
        $t = $this->companyTemplate();
        $type = $t['listing_type'] ?? 'product';
        return response(ListingCsv::sample($t), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$type}_import_sample.csv\"",
        ]);
    }

    /**
     * CSV import. Reads the plain columns of this business type (title, price… plus `attr_<field>` per tracked field,
     * `custom:<Name>` for extra fields) and still accepts the older attributes_json / custom_fields_json / type columns,
     * so an exported file re-imports. A row whose (type, title) already exists is skipped, or updated when
     * on_duplicate=update. Problems in one row never stop the others.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file'         => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'on_duplicate' => ['nullable', Rule::in(['skip', 'update'])],
        ]);
        $update      = $request->input('on_duplicate') === 'update';
        $companyId   = auth()->user()->company_id;
        $template    = $this->companyTemplate();
        $defaultType = $template['listing_type'] ?? 'product';
        $schema      = collect($template['attribute_schema'] ?? [])->keyBy('key');

        [$handle, $delimiter] = ListingCsv::open((string) file_get_contents($request->file('file')->getRealPath()));
        $headers = array_map(fn ($h) => trim((string) $h), fgetcsv($handle, 0, $delimiter, '"', '\\') ?: []);
        $lower   = array_map('mb_strtolower', $headers);
        if (!in_array('title', $lower, true)) {
            fclose($handle);
            return response()->json(['message' => 'The first row must be a header row containing a “title” column. Download the sample file to see the layout.'], 422);
        }

        $imported = $updated = $skipped = $failed = 0; $errors = []; $warnings = []; $row = 0;

        while (($line = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $row++;
            if (count(array_filter($line, fn ($c) => trim((string) $c) !== '')) === 0) continue;       // blank line
            $line = array_pad($line, count($headers), '');
            $data = [];
            foreach ($headers as $i => $h) $data[mb_strtolower($h)] = (string) ($line[$i] ?? '');

            $title = trim($data['title'] ?? '');
            if ($title === '') { $errors[] = "Row {$row}: missing title"; $failed++; continue; }
            $type = trim($data['type'] ?? '') ?: $defaultType;

            try {
                // attributes: the JSON column first, then the plain attr_ columns on top
                $attributes = [];
                if (trim($data['attributes_json'] ?? '') !== '') {
                    $decoded = json_decode($data['attributes_json'], true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) { $errors[] = "Row {$row}: attributes_json is not valid JSON"; $failed++; continue; }
                    $attributes = $decoded;
                }
                foreach ($schema as $key => $field) {
                    $col = 'attr_' . mb_strtolower($key);
                    if (!array_key_exists($col, $data)) continue;
                    [$val, $problem] = ListingCsv::typed($field, $data[$col]);
                    if ($problem) $warnings[] = "Row {$row}: {$problem}";
                    if ($val !== null) $attributes[$key] = $val;
                }

                // extra fields: custom_fields_json, then "custom:<Name>" columns
                $customFields = null;
                if (trim($data['custom_fields_json'] ?? '') !== '') {
                    $decoded = json_decode($data['custom_fields_json'], true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) { $errors[] = "Row {$row}: custom_fields_json is not valid JSON"; $failed++; continue; }
                    $customFields = $decoded;
                }
                foreach ($headers as $i => $h) {
                    if (!preg_match('/^custom\s*[:_]\s*(.+)$/i', $h, $m) || trim((string) ($line[$i] ?? '')) === '') continue;
                    $customFields[] = ['key' => trim($m[1]), 'value' => trim((string) $line[$i])];
                }

                $media = null;
                if (trim($data['media_urls'] ?? '') !== '') {
                    $media = collect(preg_split('/[|\s]+|,(?=https?:)/', $data['media_urls']))
                        ->map(fn ($u) => trim($u))->filter()->map(fn ($u) => ['url' => $u])->values()->all();
                }

                $status = mb_strtolower(trim($data['status'] ?? ''));
                $price  = preg_replace('/[^0-9.]/', '', $data['price'] ?? '');
                $incent = preg_replace('/[^0-9.]/', '', $data['incentive_percentage'] ?? '');
                $fields = [
                    'description'  => trim($data['description'] ?? '') ?: null,
                    'status'       => in_array($status, ListingCsv::STATUSES, true) ? $status : 'active',
                    'price'        => is_numeric($price) ? $price : null,
                    'price_unit'   => trim($data['price_unit'] ?? '') ?: null,
                    'currency'     => strtoupper(trim($data['currency'] ?? '')) ?: 'INR',
                    'location'     => trim($data['location'] ?? '') ?: null,
                    'incentive_percentage' => is_numeric($incent) ? $incent : null,
                ];

                $existing = Listing::where('company_id', $companyId)->where('type', $type)->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])->first();
                if ($existing) {
                    if (!$update) { $skipped++; continue; }
                    $existing->update($fields + ['attributes' => array_merge($existing->attributes ?? [], $attributes) ?: null] + ($media !== null ? ['media' => $media] : []));
                    $this->syncCustomFields($existing, $customFields);
                    $updated++;
                    continue;
                }

                $listing = Listing::create($fields + [
                    'company_id' => $companyId, 'created_by' => auth()->id(), 'type' => $type, 'title' => $title,
                    'attributes' => $attributes ?: null, 'media' => $media,
                ]);
                $this->syncCustomFields($listing, $customFields);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$row}: " . $e->getMessage();
                $failed++;
            }
        }
        fclose($handle);

        $parts = ["{$imported} added"];
        if ($update) $parts[] = "{$updated} updated";
        $parts[] = "{$skipped} skipped (already exist)";
        $parts[] = "{$failed} failed";
        return response()->json([
            'message'  => implode(', ', $parts) . '.',
            'imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'failed' => $failed,
            'errors'   => array_slice($errors, 0, 50),
            'warnings' => array_slice($warnings, 0, 50),
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
            // Ad-hoc extra fields beyond the industry's fixed attribute_schema — stored in their
            // own table (see Listing::customFields()), never merged into `attributes`.
            'custom_fields'         => ['nullable', 'array'],
            'custom_fields.*.key'   => ['required_with:custom_fields', 'string', 'max:100'],
            'custom_fields.*.value' => ['nullable', 'string', 'max:2000'],
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
