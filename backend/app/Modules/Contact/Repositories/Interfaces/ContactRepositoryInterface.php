<?php

namespace App\Modules\Contact\Repositories\Interfaces;

use App\Models\Contact;
use App\Models\ContactLabel;
use App\Modules\Contact\DTOs\ContactFilterDTO;
use App\Modules\Contact\DTOs\CreateContactDTO;
use App\Modules\Contact\DTOs\CreateLabelDTO;
use App\Modules\Contact\DTOs\ImportContactDTO;
use App\Modules\Contact\DTOs\ImportResultDTO;
use App\Modules\Contact\DTOs\UpdateContactDTO;
use App\Modules\Contact\DTOs\UpdateLabelDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

// ─── Contact Repository Interface ────────────────────────────────────────────
interface ContactRepositoryInterface
{
    public function paginate(int $companyId, ContactFilterDTO $filter): LengthAwarePaginator;

    public function findById(int $id, int $companyId): ?Contact;

    public function findByPhone(string $phone, int $companyId): ?Contact;

    public function create(int $companyId, CreateContactDTO $dto): Contact;

    public function update(Contact $contact, UpdateContactDTO $dto): Contact;

    public function syncLabels(Contact $contact, array $labelIds): void;

    public function removeLabel(Contact $contact, int $labelId): void;

    public function optOut(Contact $contact): Contact;

    public function optIn(Contact $contact): Contact;

    public function delete(Contact $contact): void;

    public function import(int $companyId, ImportContactDTO $dto): ImportResultDTO;

    public function exportAll(int $companyId, ContactFilterDTO $filter): Collection;

    public function phoneExists(string $phone, int $companyId, ?int $excludeId = null): bool;
}
