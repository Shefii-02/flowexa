<?php

namespace App\Modules\Lead\Services;

use App\Models\Lead;
use App\Modules\Lead\DTOs\AssignLeadDTO;
use App\Modules\Lead\DTOs\BulkAssignDTO;
use App\Modules\Lead\DTOs\CreateLeadDTO;
use App\Modules\Lead\DTOs\CreateNoteDTO;
use App\Modules\Lead\DTOs\ImportLeadDTO;
use App\Modules\Lead\DTOs\LeadFilterDTO;
use App\Modules\Lead\DTOs\UpdateLeadDTO;
use App\Modules\Lead\Exceptions\LeadException;
use App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class LeadService
{
    public function __construct(
        private readonly LeadRepositoryInterface $leadRepository,
    ) {}

    public function list(int $companyId, int $userId, bool $viewAll, LeadFilterDTO $filter): LengthAwarePaginator
    {
        return $this->leadRepository->paginate($companyId, $userId, $viewAll, $filter);
    }

    public function show(int $id, int $companyId, int $userId, bool $viewAll): Lead
    {
        $lead = $this->leadRepository->findById($id, $companyId);
        if (!$lead) throw LeadException::notFound();

        if (!$viewAll && $lead->assigned_to !== $userId) {
            throw LeadException::forbidden();
        }

        return $lead;
    }

    public function create(int $companyId, CreateLeadDTO $dto): Lead
    {
        // Prevent duplicate lead for same contact
        $existing = $this->leadRepository->findByContact($dto->contactId, $companyId);
        if ($existing && !in_array($existing->stage, ['enrolled', 'lost'])) {
            throw LeadException::duplicateLead();
        }

        if ($dto->assignedTo) {
            $this->validateCounsellor($dto->assignedTo, $companyId);
        }

        return $this->leadRepository->create($companyId, $dto);
    }

    public function update(int $id, int $companyId, int $userId, bool $viewAll, UpdateLeadDTO $dto): Lead
    {
        $lead = $this->show($id, $companyId, $userId, $viewAll);
        return $this->leadRepository->update($lead, $dto);
    }

    public function assign(int $id, int $companyId, AssignLeadDTO $dto): Lead
    {
        $lead       = $this->leadRepository->findById($id, $companyId);
        if (!$lead) throw LeadException::notFound();

        $counsellor = $this->validateCounsellor($dto->userId, $companyId);
        $lead       = $this->leadRepository->assign($lead, $counsellor->id, auth()->id());
        $this->leadRepository->pushCrmOutbox($lead, 'assigned');

        return $lead;
    }

    public function bulkAssign(int $companyId, BulkAssignDTO $dto): int
    {
        $counsellors = collect($dto->userIds)->map(fn($uid) => $this->validateCounsellor($uid, $companyId));
        $assigned    = 0;
        $index       = 0;

        foreach ($dto->leadIds as $leadId) {
            $lead = $this->leadRepository->findById($leadId, $companyId);
            if (!$lead) continue;

            $counsellor = $counsellors[$index % count($dto->userIds)];
            $this->leadRepository->assign($lead, $counsellor->id, auth()->id());
            $assigned++;
            $index++;
        }

        return $assigned;
    }

    public function addNote(int $leadId, int $companyId, int $userId, bool $viewAll, CreateNoteDTO $dto): void
    {
        $lead = $this->show($leadId, $companyId, $userId, $viewAll);
        $this->leadRepository->logEvent($lead, 'note_added', ['content' => $dto->content]);
    }

    public function delete(int $id, int $companyId): void
    {
        $lead = $this->leadRepository->findById($id, $companyId);
        if (!$lead) throw LeadException::notFound();
        $this->leadRepository->delete($lead);
    }

    public function crmSync(int $id, int $companyId): void
    {
        $lead = $this->leadRepository->findById($id, $companyId);
        if (!$lead) throw LeadException::notFound();
        $this->leadRepository->pushCrmOutbox($lead, 'manual_sync');
    }

    public function analytics(int $companyId): array
    {
        return $this->leadRepository->analytics($companyId);
    }

    public function import(int $companyId, int $userId, ImportLeadDTO $dto): \App\Models\LeadImport
    {
        return $this->leadRepository->import($companyId, $userId, $dto);
    }

    public function export(int $companyId, LeadFilterDTO $filter): string
    {
        $leads = $this->leadRepository->exportAll($companyId, $filter);

        $filename = 'leads_export_' . now()->format('Ymd_His') . '.csv';
        $path     = 'exports/' . $filename;

        if (!Storage::exists('exports')) {
            Storage::makeDirectory('exports');
        }

        $handle = fopen(Storage::path($path), 'w');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open export file for writing: {$path}");
        }

        fputcsv($handle, ['id', 'phone', 'name', 'email', 'stage', 'priority', 'category', 'source', 'assigned_to', 'notes', 'created_at']);

        foreach ($leads as $lead) {
            fputcsv($handle, [
                $lead->id,
                $lead->contact?->phone,
                $lead->contact?->name,
                $lead->contact?->email,
                $lead->stage,
                $lead->priority,
                $lead->category,
                $lead->source,
                $lead->assignedTo?->name,
                $lead->notes,
                $lead->created_at?->toDateTimeString(),
            ]);
        }

        fclose($handle);
        return $path;
    }

    private function validateCounsellor(int $userId, int $companyId)
    {
        $user = $this->leadRepository->findCounsellor($userId, $companyId);
        if (!$user) throw LeadException::counsellorNotFound();

        $active = $this->leadRepository->countActiveLeadsFor($userId);
        if ($active >= $user->max_leads) {
            throw LeadException::counsellorAtCapacity($user->name, $user->max_leads);
        }

        return $user;
    }
}
