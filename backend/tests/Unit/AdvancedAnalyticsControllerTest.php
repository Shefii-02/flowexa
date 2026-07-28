<?php

namespace Tests\Unit;

use App\Modules\Analytics\Http\Controllers\AdvancedAnalyticsController;
use App\Modules\Analytics\Services\AdvancedAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdvancedAnalyticsControllerTest extends TestCase
{
    public function test_cohort_returns_json_from_service(): void
    {
        $service = $this->createMock(AdvancedAnalyticsService::class);
        $service->expects($this->once())
            ->method('cohortAnalysis')
            ->with(42, 3)
            ->willReturn([['cohort_month' => '2024-01']]);

        Auth::shouldReceive('user')->andReturn((object) ['company_id' => 42]);

        $controller = new AdvancedAnalyticsController($service);
        $response = $controller->cohort(new Request(['months' => '3']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([['cohort_month' => '2024-01']], $response->getData(true));
    }
}
