<?php

namespace App\Modules\Contact\Repositories;

use App\Models\ContactLabel;
use App\Modules\Contact\DTOs\CreateLabelDTO;
use App\Modules\Contact\DTOs\UpdateLabelDTO;
use App\Modules\Contact\Repositories\Interfaces\LabelRepositoryInterface;
use Illuminate\Support\Collection;

class LabelRepository implements LabelRepositoryInterface
{
    public function allForCompany(int $companyId): Collection
    {
        return ContactLabel::where('company_id', $companyId)
            ->withCount('contacts')
            ->orderBy('name')
            ->get();
    }

    public function findById(int $id, int $companyId): ?ContactLabel
    {
        return ContactLabel::where('id', $id)
            ->where('company_id', $companyId)
            ->withCount('contacts')
            ->first();
    }

    public function create(int $companyId, CreateLabelDTO $dto): ContactLabel
    {
        return ContactLabel::create([
            'company_id' => $companyId,
            'name'       => $dto->name,
            'color'      => $dto->color,
        ]);
    }

    public function update(ContactLabel $label, UpdateLabelDTO $dto): ContactLabel
    {
        $data = array_filter([
            'name'  => $dto->name,
            'color' => $dto->color,
        ], fn($v) => !is_null($v));

        $label->update($data);
        return $label->fresh();
    }

    public function delete(ContactLabel $label): void
    {
        // Pivot rows auto-deleted by cascadeOnDelete on migration
        $label->delete();
    }

    public function nameExists(string $name, int $companyId, ?int $excludeId = null): bool
    {
        return ContactLabel::where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }
}
