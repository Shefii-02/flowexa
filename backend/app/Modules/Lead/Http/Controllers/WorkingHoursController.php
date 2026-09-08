<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanyHoliday;
use App\Models\CompanyWorkingHour;
use App\Models\LeadAssignmentRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkingHoursController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    /** GET /working-hours — 7 weekday rows (auto-seeded) + holiday overrides. */
    public function index(): JsonResponse
    {
        $companyId = $this->companyId();

        $rule = LeadAssignmentRule::where('company_id', $companyId)->first();
        CompanyWorkingHour::ensureForCompany(
            $companyId,
            $rule?->working_days ?? [1, 2, 3, 4, 5],
            (string) ($rule?->working_hours_start ?? '09:00:00'),
            (string) ($rule?->working_hours_end ?? '18:00:00'),
        );

        return response()->json([
            'hours'    => CompanyWorkingHour::where('company_id', $companyId)->orderBy('weekday')->get(),
            'holidays' => CompanyHoliday::where('company_id', $companyId)
                ->where('date', '>=', now()->subMonth())->orderBy('date')->get(),
        ]);
    }

    /** PUT /working-hours — bulk upsert the weekday rows. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hours'              => 'required|array|max:7',
            'hours.*.weekday'    => 'required|integer|between:0,6',
            'hours.*.is_open'    => 'required|boolean',
            'hours.*.start_time' => 'required|date_format:H:i',
            'hours.*.end_time'   => 'required|date_format:H:i',
        ]);

        foreach ($data['hours'] as $row) {
            CompanyWorkingHour::updateOrCreate(
                ['company_id' => $this->companyId(), 'weekday' => $row['weekday']],
                ['is_open' => $row['is_open'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time']],
            );
        }

        return response()->json([
            'hours' => CompanyWorkingHour::where('company_id', $this->companyId())->orderBy('weekday')->get(),
        ]);
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => 'required|date',
            'name' => 'required|string|max:120',
        ]);

        $holiday = CompanyHoliday::updateOrCreate(
            ['company_id' => $this->companyId(), 'date' => $data['date']],
            ['name' => $data['name']],
        );

        return response()->json(['data' => $holiday], 201);
    }

    public function destroyHoliday(int $id): JsonResponse
    {
        CompanyHoliday::where('company_id', $this->companyId())->findOrFail($id)->delete();

        return response()->json(['message' => 'Removed.']);
    }
}
