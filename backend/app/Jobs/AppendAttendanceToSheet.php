<?php

namespace App\Jobs;

use App\Modules\Hr\Support\AttendanceSheet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Appends one attendance punch to the company's Google Sheet. Retried a few times; a failure never touches the punch itself. */
class AppendAttendanceToSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public int $companyId, public array $row) {}

    public function backoff(): array { return [30, 120, 600]; }

    public function handle(AttendanceSheet $sheet): void
    {
        $sheet->append($this->companyId, $this->row);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning("HR attendance sheet: gave up appending a row for company {$this->companyId}: " . $e->getMessage());
    }
}
