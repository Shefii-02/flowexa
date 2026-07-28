<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\Lead;
use App\Modules\Lead\DTOs\ImportLeadDTO;
use App\Modules\Lead\DTOs\LeadFilterDTO;
use App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface;
use App\Modules\Lead\Services\LeadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadServiceImportExportTest extends TestCase
{
    public function test_export_writes_csv_using_repository_data(): void
    {
        Storage::fake('local');

        $repository = new class implements LeadRepositoryInterface {
            public function paginate(int $companyId, int $userId, bool $viewAll, LeadFilterDTO $filter): LengthAwarePaginator
            {
                return new LengthAwarePaginator([], 0, 15);
            }

            public function findById(int $id, int $companyId): ?Lead { return null; }
            public function findByContact(int $contactId, int $companyId): ?Lead { return null; }
            public function create(int $companyId, $dto): Lead { return new Lead(); }
            public function update(Lead $lead, $dto): Lead { return $lead; }
            public function assign(Lead $lead, int $assignedTo, int $assignedBy): Lead { return $lead; }
            public function exportAll(int $companyId, LeadFilterDTO $filter): \Illuminate\Support\Collection { return collect([ $this->makeLead() ]); }
            public function import(int $companyId, int $userId, \App\Modules\Lead\DTOs\ImportLeadDTO $dto): \App\Models\LeadImport { return new \App\Models\LeadImport(); }
            public function delete(Lead $lead): void {}
            public function logEvent(Lead $lead, string $event, array $payload): \App\Models\LeadEvent { return new \App\Models\LeadEvent(); }
            public function findCounsellor(int $userId, int $companyId): ?\App\Models\User { return null; }
            public function countActiveLeadsFor(int $userId): int { return 0; }
            public function analytics(int $companyId): array { return []; }
            public function pushCrmOutbox(Lead $lead, string $event): void {}
            private function makeLead(): Lead
            {
                $lead = new Lead();
                $lead->setRelation('contact', new Contact(['phone' => '123456789', 'name' => 'Jane', 'email' => 'jane@example.com', 'opted_in' => true]));
                $lead->setRelation('labels', collect([]));
                return $lead;
            }
        };

        $service = new LeadService($repository);

        $path = $service->export(1, new LeadFilterDTO());

        $this->assertSame('exports/', substr($path, 0, 8));
        $this->assertFileExists(Storage::path($path));
    }

    public function test_import_dto_reads_uploaded_file(): void
    {
        $file = new UploadedFile(__FILE__, 'LeadServiceImportExportTest.php', 'text/php', null, true);
        $dto = ImportLeadDTO::fromRequest(['file' => $file]);

        $this->assertSame($file, $dto->file);
    }
}
