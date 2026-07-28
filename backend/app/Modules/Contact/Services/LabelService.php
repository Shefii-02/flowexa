<?php

namespace App\Modules\Contact\Services;

use App\Models\ContactLabel;
use App\Modules\Contact\DTOs\CreateLabelDTO;
use App\Modules\Contact\DTOs\UpdateLabelDTO;
use App\Modules\Contact\Exceptions\ContactException;
use App\Modules\Contact\Repositories\Interfaces\LabelRepositoryInterface;
use Illuminate\Support\Collection;

class LabelService
{
    public function __construct(
        private readonly LabelRepositoryInterface $labelRepository,
    ) {}

    public function list(int $companyId): Collection
    {
        return $this->labelRepository->allForCompany($companyId);
    }

    public function show(int $id, int $companyId): ContactLabel
    {
        $label = $this->labelRepository->findById($id, $companyId);
        if (!$label) throw ContactException::labelNotFound();
        return $label;
    }

    public function create(int $companyId, CreateLabelDTO $dto): ContactLabel
    {
        if ($this->labelRepository->nameExists($dto->name, $companyId)) {
            throw ContactException::labelNameDuplicate($dto->name);
        }
        return $this->labelRepository->create($companyId, $dto);
    }

    public function update(int $id, int $companyId, UpdateLabelDTO $dto): ContactLabel
    {
        $label = $this->labelRepository->findById($id, $companyId);
        if (!$label) throw ContactException::labelNotFound();

        if ($dto->name && $this->labelRepository->nameExists($dto->name, $companyId, $id)) {
            throw ContactException::labelNameDuplicate($dto->name);
        }

        return $this->labelRepository->update($label, $dto);
    }

    public function delete(int $id, int $companyId): void
    {
        $label = $this->labelRepository->findById($id, $companyId);
        if (!$label) throw ContactException::labelNotFound();
        $this->labelRepository->delete($label);
    }
}
