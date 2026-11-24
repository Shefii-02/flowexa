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
use Illuminate\Support\Collection;
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

    public function recipients(int $companyId, int $userId, bool $viewAll, LeadFilterDTO $filter): Collection
    {
        return $this->leadRepository->recipients($companyId, $userId, $viewAll, $filter);
    }

    public function show(int $id, int $companyId, int $userId, bool $viewAll): Lead
    {
        $lead = $this->leadRepository->findById($id, $companyId);
        if (!$lead) throw LeadException::notFound();

        if (!$viewAll && $lead->assigned_to !== $userId) {
            $user = \App\Models\User::find($userId);
            $scopedOrigin = $user ? Lead::scopedOriginAccessFor($user) : [];
            if (!$lead->isVisibleByOrigin($scopedOrigin)) {
                throw LeadException::forbidden();
            }
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

        $lead = $this->leadRepository->create($companyId, $dto);

        // "Auto-assign new leads" — route an unowned lead through the engine right away
        // (the leads:process-unassigned cron is only the safety net).
        if (!$dto->assignedTo && \App\Models\LeadAssignmentRule::routesInstantly($companyId)) {
            try {
                $contact = $lead->contact ? \App\Models\Contact::find($lead->contact->id) : null;
                $company = \App\Models\Company::find($companyId);
                if ($contact && $company) {
                    app(\App\Services\LeadAssignment\LeadAssignmentEngine::class)
                        ->assign($company, $contact, $lead->source ?: 'organic', $lead->campaign_id, null, 'auto', $lead);
                    $lead = $lead->fresh(['contact:id,name,phone', 'assignedTo:id,name']) ?? $lead;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Lead auto-assign failed: ' . $e->getMessage(), ['lead_id' => $lead->id]);
            }
        }

        return $lead;
    }

    public function update(int $id, int $companyId, int $userId, bool $viewAll, UpdateLeadDTO $dto): Lead
    {
        $lead = $this->show($id, $companyId, $userId, $viewAll);
        if ($dto->stage !== null) {
            self::assertStageNote($lead->stage, $dto->stage, $dto->reason);
        }
        return $this->leadRepository->update($lead, $dto);
    }

    private const CLOSED_STAGES = ['enrolled', 'lost'];

    /**
     * Does moving a lead from one stage to another need a note?
     *   'required'  closing it as Lost, reopening a closed lead, or changing a closed lead's outcome
     *   'optional'  converting it (a note is asked for but may be skipped)
     *   null        any ordinary pipeline move
     */
    public static function stageNoteRule(?string $from, string $to): ?string
    {
        if ($from === $to) { return null; }
        $wasClosed = in_array($from, self::CLOSED_STAGES, true);
        if ($to === 'lost' || $wasClosed) { return 'required'; }
        return $to === 'enrolled' ? 'optional' : null;
    }

    /** Throws 422 when a required note is missing. */
    public static function assertStageNote(?string $from, string $to, ?string $reason): void
    {
        if (self::stageNoteRule($from, $to) === 'required' && trim((string) $reason) === '') {
            throw LeadException::reasonRequired(in_array($from, self::CLOSED_STAGES, true) ? ($to === 'lost' || in_array($to, self::CLOSED_STAGES, true) ? 'change the outcome of a closed lead' : 'reopen this lead') : 'close this lead as lost');
        }
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

    /**
     * "Auto assign" button — route the given leads (or every unowned open lead when none given)
     * through the engine with the method the admin picked, assigning instantly.
     * @return array{assigned:int, skipped:int}
     */
    public function autoAssign(int $companyId, array $leadIds, string $strategy, ?string $staffScope = null, ?array $staffIds = null, ?bool $notify = null): array
    {
        // null scope = follow the routing rules; otherwise the chosen pool (all / attendance-online / selected / selected+online) decides who may get leads
        \App\Models\StaffAvailability::recountWorkload($companyId);   // lead limits are judged on what people really hold
        $pool = $staffScope === null ? false : app(\App\Services\LeadAssignment\StaffPoolResolver::class)->resolve($companyId, $staffScope, $staffIds);
        $company = \App\Models\Company::findOrFail($companyId);
        $engine  = app(\App\Services\LeadAssignment\LeadAssignmentEngine::class);

        $leads = \App\Models\Lead::where('company_id', $companyId)
            ->when($leadIds, fn ($q) => $q->whereIn('id', $leadIds), fn ($q) => $q->whereNull('assigned_to')->limit(500))
            ->whereNotIn('stage', ['enrolled', 'lost'])
            ->with('contact')->get();

        $assigned = 0; $skipped = 0; $noOne = false;
        foreach ($leads as $i => $lead) {
            if (!$lead->contact) { $skipped++; continue; }
            try {
                // release + assign are ONE step: if nobody can take the lead, its current owner keeps it
                \Illuminate\Support\Facades\DB::transaction(function () use ($lead, $engine, $company, $strategy, $pool, $notify, &$assigned, &$skipped) {
                    if ($lead->assigned_to) { $this->releaseLead($lead, 'auto_reassign'); $lead->refresh(); }
                    $a = $engine->assign($company, $lead->contact, $lead->source ?: 'organic', $lead->campaign_id, null, 'manual', $lead, null, $strategy, $pool, $notify);
                    $a->staff_id ? $assigned++ : $skipped++;
                });
            } catch (\App\Services\LeadAssignment\NoEligibleStaffException) {
                $skipped++; $noOne = true;
                if ($pool !== false) {            // a hand-picked pool is the same for every lead — if nobody can take this one, nobody can take the rest
                    $skipped += $leads->count() - $i - 1;
                    break;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Lead auto-assign (button) failed: ' . $e->getMessage(), ['lead_id' => $lead->id]);
                $skipped++;
            }
        }

        return ['assigned' => $assigned, 'skipped' => $skipped, 'reasons' => $noOne ? $this->whyNoStaff($companyId, $pool) : []];
    }

    /**
     * Plain-language reasons the people in this pool could not take a lead — shown when an Auto assign run finds nobody.
     * @param array|false|null $pool false = the routing rules decide (availability applies), null = everyone, array = exactly these
     * @return string[]
     */
    public function whyNoStaff(int $companyId, array|bool|null $pool): array
    {
        if (is_array($pool) && $pool === [\App\Services\LeadAssignment\StaffPoolResolver::NOBODY]) {
            return ['Nobody in the chosen pool is clocked in right now (attendance-based). Choose "All staff" or "Selected staff", or ask them to clock in.'];
        }
        $users = \App\Models\User::where('company_id', $companyId)->where('is_active', true)->with('role')
            ->when(is_array($pool), fn ($q) => $q->whereIn('id', $pool))->orderBy('name')->limit(60)->get();
        if ($users->isEmpty()) {
            return ['No active staff match the chosen pool.'];
        }
        $avail = \App\Models\StaffAvailability::whereIn('staff_id', $users->pluck('id'))->get()->keyBy('staff_id');
        // "All staff" / "Selected staff — without checking attendance" never ask whether someone is online, so offline is not a reason
        $scope = $pool === false ? \App\Models\LeadAssignmentRule::where('company_id', $companyId)->value('staff_scope') : null;
        $asksAvailability = $pool === false && !app(\App\Services\LeadAssignment\StaffPoolResolver::class)->ignoresAvailability($scope);
        $out = [];
        foreach ($users as $u) {
            $a = $avail[$u->id] ?? null;
            if (!$u->hasAnyPermission(['leads.view', 'leads.manage'])) {
                $out[] = "{$u->name} — their role cannot open leads (add the Leads permission)";
            } elseif ($u->max_leads > 0 && $a && $a->current_leads_count >= $u->max_leads) {
                $out[] = "{$u->name} — already at their lead limit ({$a->current_leads_count}/{$u->max_leads})";
            } elseif ($asksAvailability && $a && !$a->isEffectivelyAvailable()) {
                $out[] = "{$u->name} — marked " . ($a->status !== 'online' ? $a->status : 'unavailable') . ' (not available)';
            }
        }
        return array_slice($out, 0, 8) ?: ['Nobody in the pool could take the leads.'];
    }

    /** Open leads that are assigned to someone but nobody has touched yet (still "New", no follow-up, no logged activity). */
    private function recallQuery(int $companyId, array $leadIds, bool $onlyUntouched, ?int $staffId, ?int $olderThanHours)
    {
        return \App\Models\Lead::where('company_id', $companyId)
            ->whereNotNull('assigned_to')
            ->whereNotIn('stage', ['enrolled', 'lost'])
            ->when($leadIds, fn ($q) => $q->whereIn('id', $leadIds))
            ->when($staffId, fn ($q) => $q->where('assigned_to', $staffId))
            ->when($olderThanHours, fn ($q) => $q->where('assigned_at', '<=', now()->subHours($olderThanHours)))
            ->when($onlyUntouched, function ($q) {
                $q->where('stage', 'new')->whereNull('followed_up_at');
                if (\Illuminate\Support\Facades\Schema::hasTable('lead_activities')) {
                    $q->whereNotExists(fn ($a) => $a->select(\Illuminate\Support\Facades\DB::raw(1))->from('lead_activities')->whereColumn('lead_activities.lead_id', 'leads.id'));
                }
            });
    }

    /**
     * Take assigned-but-pending leads back from their staff (many at once) so they are unassigned again.
     * Open assignments are dropped and each staff member's open-lead count goes back down.
     * @return array{count:int, sample:array<int,array>}   preview=true only counts, nothing changes
     */
    public function recall(int $companyId, array $leadIds, bool $onlyUntouched, ?int $staffId, ?int $olderThanHours, bool $preview = false): array
    {
        $q = $this->recallQuery($companyId, $leadIds, $onlyUntouched, $staffId, $olderThanHours);
        $total = (clone $q)->count();
        $sample = (clone $q)->with(['contact:id,name,phone', 'assignedTo:id,name'])->orderBy('assigned_at')->limit(20)->get()
            ->map(fn ($l) => ['id' => $l->id, 'name' => $l->contact?->name ?: $l->contact?->phone, 'staff' => $l->assignedTo?->name, 'assigned_at' => $l->assigned_at?->toIso8601String()])->all();
        if ($preview || $total === 0) {
            return ['count' => $total, 'sample' => $sample];
        }

        $done = 0;
        $q->with('contact')->chunkById(200, function ($leads) use (&$done) {
            foreach ($leads as $lead) {
                $this->releaseLead($lead);
                $done++;
            }
        });

        \App\Models\StaffAvailability::recountWorkload($companyId);   // give every owner's slots back, whatever way the lead was assigned
        return ['count' => $done, 'sample' => $sample];
    }

    /** Take one lead back from its owner: unassign it, drop its open assignments and give the owner's workload slot back. */
    private function releaseLead(\App\Models\Lead $lead, string $reason = 'recalled'): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($lead, $reason) {
            $from = $lead->assigned_to;
            $lead->update(['assigned_to' => null, 'assigned_by' => null, 'assigned_at' => null]);

            $open = \App\Models\LeadAssignment::where('lead_id', $lead->id)
                ->whereIn('status', ['pending', 'notified', 'assigned', 'accepted', 'ai_offered', 'ai_handling', 'transferred'])->get();
            foreach ($open as $a) {
                if ($a->staff_id && in_array($a->status, ['assigned', 'accepted'], true)) {
                    $av = \App\Models\StaffAvailability::where('staff_id', $a->staff_id)->first();
                    if ($av && $av->current_leads_count > 0) {
                        $av->decrement('current_leads_count');
                        $max = (int) \App\Models\User::where('id', $a->staff_id)->value('max_leads');
                        if ($av->fresh()->status === 'busy' && ($max <= 0 || $av->fresh()->current_leads_count < $max)) {
                            $av->update(['status' => 'online', 'is_available' => true]);
                        }
                    }
                }
                $a->update(['status' => 'dropped', 'transfer_reason' => $reason === 'recalled' ? 'Recalled by ' . (auth()->user()?->name ?? 'admin') : 'Re-assigned by auto assign']);
            }
            if ($lead->contact) { $lead->contact->update(['current_assignment_id' => null]); }
            \App\Models\WaConversation::where('company_id', $lead->company_id)->where('contact_id', $lead->contact_id)->update(['assigned_to' => null]);
            $this->leadRepository->logEvent($lead, 'lead_recalled', ['from' => $from, 'recalled_by' => auth()->id(), 'reason' => $reason]);
        });
    }

    /** Bulk-switch every (open, unless includeClosed) lead from one employee to another. No capacity gate — this is a deliberate administrative transfer, not a new assignment. */
    public function bulkReassign(int $companyId, \App\Modules\Lead\DTOs\BulkReassignDTO $dto): int
    {
        $from = $this->leadRepository->findCounsellor($dto->fromUserId, $companyId);
        $to   = $this->leadRepository->findCounsellor($dto->toUserId, $companyId);
        if (!$from || !$to) throw LeadException::counsellorNotFound();

        return $this->leadRepository->reassignBulk($companyId, $dto->fromUserId, $dto->toUserId, $dto->includeClosed);
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

    public function bulkDelete(int $companyId, \App\Modules\Lead\DTOs\BulkDeleteDTO $dto): int
    {
        return $this->leadRepository->deleteBulk($companyId, $dto->leadIds);
    }

    /** Set the stage and/or priority of many leads at once (same side effects as editing one: events, enrolled/lost dates, auto-sale). Returns how many changed. */
    /**
     * Change stage / priority and/or categories of many leads. $categories are applied per $categoryMode:
     * add (keep what they have), replace (exactly these), remove (take these away) or clear (take all away).
     */
    public function bulkStatus(int $companyId, array $leadIds, ?string $stage, ?string $priority, array $categories = [], string $categoryMode = 'add', ?string $reason = null): int
    {
        if ($stage !== null && trim((string) $reason) === '') {
            // check the whole selection first — nothing is changed if any lead needs a note that was not given
            foreach (array_unique($leadIds) as $id) {
                $l = $this->leadRepository->findById((int) $id, $companyId);
                if ($l) { self::assertStageNote($l->stage, $stage, null); }
            }
        }
        $categorizer = app(\App\Services\LeadCategorizer::class);
        $wantsCategories = $categories !== [] || $categoryMode === 'clear';
        $changed = 0;
        foreach (array_unique($leadIds) as $id) {
            $lead = $this->leadRepository->findById((int) $id, $companyId);
            if (!$lead) { continue; }

            $changesRest = ($stage !== null && $lead->stage !== $stage) || ($priority !== null && $lead->priority !== $priority);
            $before = $lead->categoryNames();
            $after = !$wantsCategories ? $before : match ($categoryMode) {
                'replace' => $categorizer->planReplace($lead, $categories),
                'remove'  => $categorizer->planRemove($lead, $categories),
                'clear'   => [],
                default   => $categorizer->planAdd($lead, $categories),
            };
            $changesCategories = $wantsCategories && array_map('mb_strtolower', $before) !== array_map('mb_strtolower', $after);
            if (!$changesRest && !$changesCategories) { continue; }   // nothing would change

            if ($changesRest) {
                // normal update path: stage events, enrolled / lost dates, auto-sale
                $this->leadRepository->update($lead, new \App\Modules\Lead\DTOs\UpdateLeadDTO(stage: $stage, priority: $priority, reason: $reason));
                $lead = $lead->fresh();
            }
            if ($changesCategories) {
                $categorizer->apply($lead, $after);
                $this->leadRepository->logEvent($lead, 'category_changed', ['from' => $before, 'to' => $after]);
            }
            $this->leadRepository->pushCrmOutbox($lead->fresh(), 'updated');
            $changed++;
        }
        return $changed;
    }

    public function crmSync(int $id, int $companyId): void
    {
        $lead = $this->leadRepository->findById($id, $companyId);
        if (!$lead) throw LeadException::notFound();
        $this->leadRepository->pushCrmOutbox($lead, 'manual_sync');
    }

    public function logs(int $companyId, int $userId, bool $viewAll, array $filter): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->leadRepository->logs($companyId, $userId, $viewAll, $filter);
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

        fputcsv($handle, ['id', 'phone', 'name', 'email', 'stage', 'priority', 'category', 'source', 'assigned_to', 'notes', 'created_at', 'closed_at']);

        foreach ($leads as $lead) {
            fputcsv($handle, [
                $lead->id,
                $lead->contact?->phone,
                $lead->contact?->name,
                $lead->contact?->email,
                $lead->stage,
                $lead->priority,
                implode(', ', $lead->categoryNames()),
                $lead->source,
                $lead->assignedTo?->name,
                $lead->notes,
                $lead->created_at?->toDateTimeString(),
                $lead->closed_at?->toDateTimeString(),
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
