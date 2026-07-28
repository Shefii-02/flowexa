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


// ─── Label Repository Interface ───────────────────────────────────────────────
interface LabelRepositoryInterface
{
    public function allForCompany(int $companyId): Collection;

    public function findById(int $id, int $companyId): ?ContactLabel;

    public function create(int $companyId, CreateLabelDTO $dto): ContactLabel;

    public function update(ContactLabel $label, UpdateLabelDTO $dto): ContactLabel;

    public function delete(ContactLabel $label): void;

    public function nameExists(string $name, int $companyId, ?int $excludeId = null): bool;
}
