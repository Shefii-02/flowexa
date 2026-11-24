<?php

namespace Tests\Unit;

use App\Modules\Catalog\WidgetLeadCategories;
use PHPUnit\Framework\TestCase;

/** A flow node's lead category can name one category or several. */
class WidgetLeadCategoriesTest extends TestCase
{
    public function test_a_node_category_is_split_into_names(): void
    {
        $this->assertSame(['Admission'], WidgetLeadCategories::split('Admission'));
        $this->assertSame(['Admission', 'Fees'], WidgetLeadCategories::split(' Admission , Fees ;'));
        $this->assertSame([], WidgetLeadCategories::split('  '));
    }
}
