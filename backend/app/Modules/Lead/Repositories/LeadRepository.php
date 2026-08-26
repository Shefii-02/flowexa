<?php

namespace App\Modules\Lead\Repositories;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\User;
use App\Models\Contact;
use App\Models\LeadImport;
use App\Modules\Lead\DTOs\CreateLeadDTO;
use App\Modules\Lead\DTOs\ImportLeadDTO;
use App\Modules\Lead\DTOs\LeadFilterDTO;
use App\Modules\Lead\DTOs\UpdateLeadDTO;
use App\Modules\Lead\Repositories\Interfaces\LeadRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;

class LeadRepository implements LeadRepositoryInterface
{
    public function paginate(int $companyId, int $userId, bool $viewAll, LeadFilterDTO $filter): LengthAwarePaginator
    {
        return Lead::with(['contact:id,name,phone,email', 'assignedTo:id,name,email,department'])
            ->where('company_id', $companyId)
            ->when(!$viewAll, fn($q) => $q->where('assigned_to', $userId))
            ->when($filter->stage,      fn($q) => $q->where('stage', $filter->stage))
            ->when($filter->priority,   fn($q) => $q->where('priority', $filter->priority))
            ->when($filter->category,   fn($q) => $q->where('category', $filter->category))
            ->when($filter->assignedTo, fn($q) => $q->where('assigned_to', $filter->assignedTo))
            ->when($filter->source,     fn($q) => $q->where('source', $filter->source))
            ->when($filter->search,     fn($q) =>
                $q->whereHas('contact', fn($c) =>
                    $c->where('name',  'like', "%{$filter->search}%")
                      ->orWhere('phone','like', "%{$filter->search}%")
                )
            )
            ->latest()
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    public function findById(int $id, int $companyId): ?Lead
    {
        return Lead::with([
            'contact.labels',
            'assignedTo:id,name,email,department',
            'assignedBy:id,name',
            'flowNode:id,title,type',
            'campaign:id,name',
            'events' => fn($q) => $q->latest()->with('user:id,name'),
        ])->where('id', $id)->where('company_id', $companyId)->first();
    }

    public function findByContact(int $contactId, int $companyId, string $category = null): ?Lead
    {

        $lead = Lead::where('contact_id', $contactId)->where('company_id', $companyId);
        if($category != null){
            $lead = $lead->where('category',$category);
        }
      return  $lead = $lead->first();
    }

    public function create(int $companyId, CreateLeadDTO $dto): Lead
    {
        $lead = Lead::create([
            'company_id'   => $companyId,
            'contact_id'   => $dto->contactId,
            'assigned_to'  => $dto->assignedTo,
            'assigned_by'  => $dto->assignedTo ? auth()->id() : null,
            'assigned_at'  => $dto->assignedTo ? now() : null,
            'flow_node_id' => $dto->flowNodeId,
            'campaign_id'  => $dto->campaignId,
            'category'     => $dto->category,
            'source'       => $dto->source,
            'stage'        => 'new',
            'priority'     => $dto->priority,
            'notes'        => $dto->notes,
        ]);

        $this->logEvent($lead, 'lead_created', ['source' => $dto->source, 'category' => $dto->category]);

        return $lead->load(['contact:id,name,phone', 'assignedTo:id,name']);
    }

    public function update(Lead $lead, UpdateLeadDTO $dto): Lead
    {
        $oldStage = $lead->stage;
        $data     = array_filter([
            'stage'         => $dto->stage,
            'priority'      => $dto->priority,
            'category'      => $dto->category,
            'notes'         => $dto->notes,
            'followed_up_at'=> $dto->followedUpAt,
        ], fn($v) => !is_null($v));

        if (isset($data['stage']) && $data['stage'] === 'enrolled') {
            $data['enrolled_at'] = now();
        }

        $lead->update($data);

        if ($dto->stage && $dto->stage !== $oldStage) {
            $this->logEvent($lead, 'stage_changed', ['from' => $oldStage, 'to' => $dto->stage]);
        }

        return $lead->fresh(['contact', 'assignedTo']);
    }

    public function assign(Lead $lead, int $assignedTo, int $assignedBy): Lead
    {
        $oldAssignee = $lead->assigned_to;
        $lead->update([
            'assigned_to' => $assignedTo,
            'assigned_by' => $assignedBy,
            'assigned_at' => now(),
        ]);

        $this->logEvent($lead, 'assigned', [
            'from'          => $oldAssignee,
            'to'            => $assignedTo,
            'assigned_by'   => $assignedBy,
        ]);

        return $lead->fresh(['contact', 'assignedTo']);
    }

    public function delete(Lead $lead): void
    {
        $lead->delete();
    }

    public function logEvent(Lead $lead, string $event, array $payload): LeadEvent
    {
        return LeadEvent::create([
            'lead_id'    => $lead->id,
            'company_id' => $lead->company_id,
            'user_id'    => auth()->id(),
            'event'      => $event,
            'payload'    => $payload,
        ]);
    }

    public function findCounsellor(int $userId, int $companyId): ?User
    {
        return User::where('id', $userId)->where('company_id', $companyId)->first();
    }

    public function countActiveLeadsFor(int $userId): int
    {
        return Lead::where('assigned_to', $userId)->whereNotIn('stage', ['enrolled', 'lost'])->count();
    }

    public function analytics(int $companyId): array
    {
        $leads = Lead::where('company_id', $companyId);
        $total = (clone $leads)->count();

        return [
            'total'           => $total,
            'by_stage'        => (clone $leads)->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage'),
            'by_category'     => (clone $leads)->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category'),
            'by_source'       => (clone $leads)->selectRaw('source, count(*) as total')->groupBy('source')->pluck('total', 'source'),
            'by_priority'     => (clone $leads)->selectRaw('priority, count(*) as total')->groupBy('priority')->pluck('total', 'priority'),
            'conversion_rate' => $total > 0
                ? round(((clone $leads)->where('stage', 'enrolled')->count() / $total) * 100, 1)
                : 0,
        ];
    }

    public function pushCrmOutbox(Lead $lead, string $event): void
    {
        try {
            DB::table('crm_sync_outbox')->insert([
                'company_id'  => $lead->company_id,
                'entity_type' => 'lead',
                'entity_id'   => $lead->id,
                'event'       => $event,
                'payload'     => json_encode([
                    'lead_id'     => $lead->id,
                    'stage'       => $lead->stage,
                    'category'    => $lead->category,
                    'priority'    => $lead->priority,
                    'source'      => $lead->source,
                    'assigned_to' => $lead->assigned_to,
                    'crm_id'      => $lead->crm_id,
                    'contact'     => $lead->contact ? ['phone' => $lead->contact->phone, 'name' => $lead->contact->name] : null,
                    'occurred_at' => now()->toIso8601String(),
                ]),
                'status'      => 'pending',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('CRM outbox push failed: ' . $e->getMessage());
        }
    }

    public function exportAll(int $companyId, LeadFilterDTO $filter): Collection
    {
        return Lead::with(['contact', 'assignedTo', 'contact.labels'])
            ->where('company_id', $companyId)
            ->when($filter->stage, fn($q) => $q->where('stage', $filter->stage))
            ->when($filter->priority, fn($q) => $q->where('priority', $filter->priority))
            ->when($filter->category, fn($q) => $q->where('category', $filter->category))
            ->when($filter->assignedTo, fn($q) => $q->where('assigned_to', $filter->assignedTo))
            ->when($filter->source, fn($q) => $q->where('source', $filter->source))
            ->when($filter->search, fn($q) =>
                $q->whereHas('contact', fn($c) =>
                    $c->where('name', 'like', "%{$filter->search}%")
                      ->orWhere('phone', 'like', "%{$filter->search}%")
                )
            )
            ->latest()
            ->get();
    }

    public function import(int $companyId, int $userId, ImportLeadDTO $dto): LeadImport
    {
        $path = $dto->file->store("lead-imports/{$companyId}", 'local');

        $import = LeadImport::create([
            'company_id' => $companyId,
            'user_id'    => $userId,
            'file_path'  => $path,
            'status'     => 'processing',
        ]);

        $this->processImport($import, $companyId);

        return $import->fresh();
    }

    private function processImport(LeadImport $import, int $companyId): void
    {
        $filePath = Storage::path($import->file_path);
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            $import->update(['status' => 'failed', 'errors' => ['Unable to open import file.']]);
            return;
        }

        $headers = array_map('trim', fgetcsv($handle));
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $row = 0;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;
            if (count($line) < count($headers)) { $skipped++; continue; }

            $data = array_combine($headers, $line);
            $phone = preg_replace('/\D/', '', trim($data['phone'] ?? ''));

            if (!$phone) { $errors[] = "Row {$row}: missing phone"; $failed++; continue; }

            try {
                $contact = Contact::firstOrCreate(
                    ['company_id' => $companyId, 'phone' => $phone],
                    ['name' => $data['name'] ?? null, 'email' => $data['email'] ?? null, 'opted_in' => true]
                );

                $existing = Lead::where('contact_id', $contact->id)
                    ->where('company_id', $companyId)
                    ->whereNotIn('stage', ['enrolled', 'lost'])
                    ->exists();

                if ($existing) { $skipped++; continue; }

                Lead::create([
                    'company_id' => $companyId,
                    'contact_id' => $contact->id,
                    'stage'      => $data['stage']    ?? 'new',
                    'priority'   => $data['priority'] ?? 'medium',
                    'category'   => $data['category'] ?? null,
                    'source'     => 'import',
                    'notes'      => $data['notes']    ?? null,
                ]);

                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Row {$row}: " . $e->getMessage();
                $failed++;
            }
        }

        fclose($handle);
        Storage::delete($import->file_path);

        $import->update([
            'status'   => 'done',
            'total'    => $imported + $skipped + $failed,
            'imported' => $imported,
            'skipped'  => $skipped,
            'failed'   => $failed,
            'errors'   => array_slice($errors, 0, 50),
        ]);
    }
}
