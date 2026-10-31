<?php

namespace Tests\Unit;

use App\Modules\Catalog\Support\ListingCsv;
use PHPUnit\Framework\TestCase;

class ListingCsvTest extends TestCase
{
    private function education(): array
    {
        return [
            'listing_type' => 'course',
            'attribute_schema' => [
                ['key' => 'mode', 'label' => 'Mode', 'type' => 'enum', 'options' => ['online', 'offline', 'hybrid']],
                ['key' => 'duration', 'label' => 'Duration', 'type' => 'text'],
                ['key' => 'total_fee', 'label' => 'Total fee', 'type' => 'number'],
                ['key' => 'emi_available', 'label' => 'EMI available', 'type' => 'boolean'],
            ],
        ];
    }

    public function test_sample_has_one_column_per_business_type_field_and_two_example_rows(): void
    {
        $csv = ListingCsv::sample($this->education());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);                       // Excel opens it as UTF-8
        [$h, $d] = ListingCsv::open($csv);
        $rows = [];
        while (($r = fgetcsv($h, 0, $d, '"', '\\')) !== false) $rows[] = $r;
        $this->assertSame(['title', 'description', 'status', 'price', 'price_unit', 'currency', 'location', 'incentive_percentage',
            'attr_mode', 'attr_duration', 'attr_total_fee', 'attr_emi_available', 'media_urls'], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame('Sample course 1', $rows[1][0]);
        $this->assertSame('online', $rows[1][8]);
        $this->assertSame('offline', $rows[2][8]);                                  // second row shows another allowed value
    }

    public function test_header_survives_excel_bom_semicolons_and_ansi_encoding(): void
    {
        [$h, $d] = ListingCsv::open("\xEF\xBB\xBFtitle;price\nA;100\n");
        $this->assertSame(';', $d);
        $this->assertSame(['title', 'price'], fgetcsv($h, 0, $d, '"', '\\'));                  // BOM would have made this "\xEF\xBB\xBFtitle"

        [$h] = ListingCsv::open("title,location\nCaf\xE9,Kochi\n");                 // Windows-1252 "é"
        fgetcsv($h, 0, ',', '"', '\\');
        $this->assertSame(['Café', 'Kochi'], fgetcsv($h, 0, ',', '"', '\\'));
    }

    public function test_values_are_typed_by_the_field_and_bad_ones_are_reported_not_fatal(): void
    {
        $f = $this->education()['attribute_schema'];
        $this->assertSame([45000, null], ListingCsv::typed($f[2], '₹45,000'));
        $this->assertSame([true, null], ListingCsv::typed($f[3], 'Yes'));
        $this->assertSame(['hybrid', null], ListingCsv::typed($f[0], 'HYBRID'));
        $this->assertNull(ListingCsv::typed($f[0], 'remote')[0]);
        $this->assertStringContainsString('one of online, offline, hybrid', ListingCsv::typed($f[0], 'remote')[1]);
        $this->assertSame([null, null], ListingCsv::typed($f[1], '   '));
    }
}
