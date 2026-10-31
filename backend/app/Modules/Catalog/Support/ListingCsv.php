<?php

namespace App\Modules\Catalog\Support;

/**
 * The CSV a company downloads, fills in and uploads to bulk-add catalog items. The columns follow the company's
 * business type: the same title / price / status columns for everyone, plus one `attr_<field>` column for each
 * field that business type tracks (a property has bedrooms, a course has duration and mode…). Filling plain
 * columns in Excel is far less error-prone than typing a JSON object into one cell.
 */
class ListingCsv
{
    public const BASE = ['title', 'description', 'status', 'price', 'price_unit', 'currency', 'location', 'incentive_percentage'];
    public const STATUSES = ['active', 'draft', 'inactive', 'sold'];

    /** @return array<int, array{column:string,label:string,required:bool,help:string,example:string}> */
    public static function columns(array $template): array
    {
        $type = $template['listing_type'] ?? 'product';
        $cols = [
            ['column' => 'title', 'label' => 'Title', 'required' => true, 'help' => "Name of the {$type}. A row whose title already exists is skipped (or updated, if you choose that).", 'example' => 'Sample ' . $type . ' 1'],
            ['column' => 'description', 'label' => 'Description', 'required' => false, 'help' => 'What the AI agent tells customers.', 'example' => 'Describe it here'],
            ['column' => 'status', 'label' => 'Status', 'required' => false, 'help' => 'active, draft, inactive or sold. Blank = active.', 'example' => 'active'],
            ['column' => 'price', 'label' => 'Price', 'required' => false, 'help' => 'Number only, e.g. 10000.', 'example' => '10000'],
            ['column' => 'price_unit', 'label' => 'Price unit', 'required' => false, 'help' => 'e.g. total, per month, per session.', 'example' => 'total'],
            ['column' => 'currency', 'label' => 'Currency', 'required' => false, 'help' => 'Blank = INR.', 'example' => 'INR'],
            ['column' => 'location', 'label' => 'Location / area', 'required' => false, 'help' => '', 'example' => 'Kochi'],
            ['column' => 'incentive_percentage', 'label' => 'Staff incentive %', 'required' => false, 'help' => 'Number, e.g. 5.', 'example' => ''],
        ];
        foreach ($template['attribute_schema'] ?? [] as $f) {
            $type = $f['type'] ?? 'text';
            $cols[] = [
                'column'   => 'attr_' . $f['key'],
                'label'    => $f['label'] ?? $f['key'],
                'required' => false,
                'help'     => match ($type) {
                    'enum'    => 'One of: ' . implode(', ', $f['options'] ?? []),
                    'number'  => 'Number only.',
                    'boolean' => 'yes or no.',
                    default   => 'Free text.',
                },
                'example'  => self::example($f, 0),
            ];
        }
        $cols[] = ['column' => 'media_urls', 'label' => 'Photos / videos', 'required' => false, 'help' => 'Links to the photos or videos. Several links separated by |', 'example' => 'https://example.com/photo1.jpg|https://example.com/photo2.jpg'];
        return $cols;
    }

    public static function example(array $field, int $row): string
    {
        return match ($field['type'] ?? 'text') {
            'enum'    => (string) (($field['options'][$row] ?? $field['options'][0] ?? '')),
            'number'  => (string) ($row + 1),
            'boolean' => $row === 0 ? 'yes' : 'no',
            default   => 'Sample ' . ($field['label'] ?? $field['key']),
        };
    }

    /** The downloadable file: header + two example rows, UTF-8 with a BOM so Excel shows every language correctly. */
    public static function sample(array $template): string
    {
        $cols = self::columns($template);
        $type = $template['listing_type'] ?? 'product';
        $fields = collect($template['attribute_schema'] ?? [])->keyBy(fn ($f) => 'attr_' . $f['key']);

        $out = fopen('php://temp', 'w+');
        fputcsv($out, array_column($cols, 'column'), ',', '"', '\\');
        foreach ([0, 1] as $r) {
            fputcsv($out, array_map(function ($c) use ($r, $type, $fields) {
                return match (true) {
                    $c['column'] === 'title' => 'Sample ' . $type . ' ' . ($r + 1),
                    $c['column'] === 'price' => (string) (10000 + $r * 5000),
                    $c['column'] === 'media_urls' => $r === 0 ? $c['example'] : '',
                    isset($fields[$c['column']]) => self::example($fields[$c['column']], $r),
                    default => $c['example'],
                };
            }, $cols), ',', '"', '\\');
        }
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }

    /** Excel may save as UTF-8 with BOM, UTF-16 or Windows-1252, with , ; or tab separators. Returns [stream, delimiter]. */
    public static function open(string $raw): array
    {
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        } elseif (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        $first = strtok($raw, "\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delimiter = array_key_first($counts);

        $h = fopen('php://temp', 'w+');
        fwrite($h, $raw);
        rewind($h);
        return [$h, $delimiter];
    }

    /** A cell value converted to the field's type; [value, problem|null]. Blank cells give [null, null]. */
    public static function typed(array $field, string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [null, null];
        $label = $field['label'] ?? $field['key'];
        switch ($field['type'] ?? 'text') {
            case 'number':
                $n = preg_replace('/[^0-9.\-]/', '', $raw);
                return is_numeric($n) ? [$n + 0, null] : [null, "“{$label}” must be a number (got “{$raw}”) — left blank"];
            case 'boolean':
                $v = mb_strtolower($raw);
                if (in_array($v, ['yes', 'y', 'true', '1'], true)) return [true, null];
                if (in_array($v, ['no', 'n', 'false', '0'], true)) return [false, null];
                return [null, "“{$label}” must be yes or no (got “{$raw}”) — left blank"];
            case 'enum':
                foreach ($field['options'] ?? [] as $opt) {
                    if (mb_strtolower((string) $opt) === mb_strtolower($raw)) return [$opt, null];
                }
                return [null, "“{$label}” must be one of " . implode(', ', $field['options'] ?? []) . " (got “{$raw}”) — left blank"];
            default:
                return [$raw, null];
        }
    }
}
