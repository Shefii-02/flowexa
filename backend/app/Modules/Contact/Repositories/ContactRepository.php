<?php

namespace App\Modules\Contact\Repositories;

use App\Models\Contact;
use App\Modules\Contact\DTOs\ContactFilterDTO;
use App\Modules\Contact\DTOs\CreateContactDTO;
use App\Modules\Contact\DTOs\ImportContactDTO;
use App\Modules\Contact\DTOs\ImportResultDTO;
use App\Modules\Contact\DTOs\UpdateContactDTO;
use App\Modules\Contact\Repositories\Interfaces\ContactRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ContactRepository implements ContactRepositoryInterface
{
    private const ALLOWED_SORT = ['name', 'phone', 'created_at', 'last_message_at'];

    // ─── Paginate ─────────────────────────────────────────────────────────────
    public function paginate(int $companyId, ContactFilterDTO $filter): LengthAwarePaginator
    {
        $sortBy  = in_array($filter->sortBy, self::ALLOWED_SORT) ? $filter->sortBy : 'created_at';
        $sortDir = $filter->sortDir === 'asc' ? 'asc' : 'desc';

        return Contact::with('labels')
            ->where('company_id', $companyId)
            ->when($filter->search, function ($q) use ($filter) {
                $term    = $filter->search;
                $stripped = preg_replace('/@[a-z.]+$/i', '', $term);
                $digits   = preg_replace('/[^0-9]/', '', $stripped);
                $last10   = strlen($digits) >= 7 ? substr($digits, -10) : null;
                $q->where(function ($inner) use ($term, $last10) {
                    $inner->where('name',  'like', "%{$term}%")
                          ->orWhere('phone', 'like', "%{$term}%")
                          ->orWhere('email', 'like', "%{$term}%");
                    if ($last10) {
                        $inner->orWhere('phone', 'like', "%{$last10}");
                    }
                });
            })
            ->when($filter->labelId, fn($q) =>
                $q->whereHas('labels', fn($l) =>
                    $l->where('contact_labels.id', $filter->labelId)
                )
            )
            ->when(!is_null($filter->optedIn), fn($q) =>
                $q->where('opted_in', $filter->optedIn)
            )
            ->orderBy($sortBy, $sortDir)
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    // ─── Find by ID ───────────────────────────────────────────────────────────
    public function findById(int $id, int $companyId): ?Contact
    {
        return Contact::with(['labels', 'leads.assignedTo', 'messages' => fn($q) => $q->latest()->limit(20)])
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();
    }

    // ─── Find by phone ────────────────────────────────────────────────────────
    public function findByPhone(string $phone, int $companyId): ?Contact
    {
        return Contact::where('phone', $phone)
            ->where('company_id', $companyId)
            ->first();
    }

    // ─── Create ───────────────────────────────────────────────────────────────
    public function create(int $companyId, CreateContactDTO $dto): Contact
    {
        $contact = Contact::create([
            'company_id'    => $companyId,
            'phone'         => $dto->phone,
            'name'          => $dto->name,
            'email'         => $dto->email,
            'custom_fields' => $dto->customFields,
            'opted_in'      => $dto->optedIn,
        ]);

        if ($dto->labelIds) {
            $contact->labels()->sync($dto->labelIds);
        }

        return $contact->load('labels');
    }

    // ─── Update ───────────────────────────────────────────────────────────────
    public function update(Contact $contact, UpdateContactDTO $dto): Contact
    {
        $data = array_filter([
            'name'          => $dto->name,
            'email'         => $dto->email,
            'custom_fields' => $dto->customFields,
            'opted_in'      => $dto->optedIn,
            'opted_out_at' => $dto->optedIn ? null : now(),
        ], fn($v) => !is_null($v));

        $contact->update($data);
        return $contact->fresh('labels');
    }

    // ─── Sync labels ──────────────────────────────────────────────────────────
    public function syncLabels(Contact $contact, array $labelIds): void
    {
        $contact->labels()->sync($labelIds);
    }

    public function removeLabel(Contact $contact, int $labelId): void
    {
        $contact->labels()->detach($labelId);
    }


    // ─── Opt out ──────────────────────────────────────────────────────────────
    public function optOut(Contact $contact): Contact
    {
        $contact->update([
            'opted_in'      => false,
            'opted_out_at'  => now(),
        ]);
        return $contact->fresh();
    }

    // ─── Opt in ───────────────────────────────────────────────────────────────
    public function optIn(Contact $contact): Contact
    {
        $contact->update([
            'opted_in'     => true,
            'opted_out_at' => null,
        ]);
        return $contact->fresh();
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    public function delete(Contact $contact): void
    {
        $contact->delete();
    }

    // ─── CSV Import ───────────────────────────────────────────────────────────
    public function import(int $companyId, ImportContactDTO $dto): ImportResultDTO
    {
        $result = new ImportResultDTO();
        $path   = Storage::path($dto->filePath);

        if (!file_exists($path)) {
            $result->addError('Uploaded file not found on server.');
            return $result;
        }

        $handle  = fopen($path, 'r');
        $raw     = fgetcsv($handle);
        $headers = array_map(fn($h) => strtolower(trim($h)), $raw);

        if (!in_array('phone', $headers)) {
            fclose($path);
            $result->addError('CSV must contain a "phone" column.');
            return $result;
        }

        $batch  = [];
        $phones = [];
        $row    = 0;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;
            if (count($line) !== count($headers)) continue;

            $data  = array_combine($headers, $line);
            $phone = preg_replace('/\D/', '', trim($data['phone'] ?? ''));

            if (!$phone || strlen($phone) < 7) {
                $result->addError("Row {$row}: Invalid phone '{$data['phone']}'");
                continue;
            }

            // Skip in-batch duplicate
            if (in_array($phone, $phones)) {
                $result->incrementSkipped();
                continue;
            }

            $phones[] = $phone;
            $batch[]  = [
                'company_id'    => $companyId,
                'phone'         => $phone,
                'name'          => $data['name']  ?? null,
                'email'         => $data['email'] ?? null,
                'opted_in'      => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];

            // Process in chunks of 200
            if (count($batch) >= 200) {
                $this->processBatch($batch, $companyId, $dto, $result);
                $batch  = [];
                $phones = [];
            }
        }

        if ($batch) {
            $this->processBatch($batch, $companyId, $dto, $result);
        }

        fclose($handle);
        Storage::delete($dto->filePath);

        return $result;
    }

    // ─── Export all as collection ─────────────────────────────────────────────
    public function exportAll(int $companyId, ContactFilterDTO $filter): Collection
    {
        $sortBy  = in_array($filter->sortBy, self::ALLOWED_SORT) ? $filter->sortBy : 'created_at';

        return Contact::with('labels')
            ->where('company_id', $companyId)
            ->when($filter->search, fn($q) =>
                $q->where('name','like',"%{$filter->search}%")
                  ->orWhere('phone','like',"%{$filter->search}%")
            )
            ->when($filter->labelId, fn($q) =>
                $q->whereHas('labels', fn($l) => $l->where('contact_labels.id', $filter->labelId))
            )
            ->when(!is_null($filter->optedIn), fn($q) => $q->where('opted_in', $filter->optedIn))
            ->orderBy($sortBy, $filter->sortDir ?? 'desc')
            ->get();
    }

    // ─── Check phone uniqueness ───────────────────────────────────────────────
    public function phoneExists(string $phone, int $companyId, ?int $excludeId = null): bool
    {
        return Contact::where('company_id', $companyId)
            ->where('phone', $phone)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    // ─── Private: process a batch ─────────────────────────────────────────────
    private function processBatch(array $batch, int $companyId, ImportContactDTO $dto, ImportResultDTO &$result): void
    {
        $existing = Contact::where('company_id', $companyId)
            ->whereIn('phone', array_column($batch, 'phone'))
            ->pluck('id', 'phone')
            ->toArray();

        foreach ($batch as $row) {
            if (isset($existing[$row['phone']])) {
                if ($dto->skipDupes) {
                    $result->incrementSkipped();
                } else {
                    Contact::where('id', $existing[$row['phone']])->update([
                        'name'  => $row['name']  ?? null,
                        'email' => $row['email'] ?? null,
                    ]);
                    $result->incrementImported();
                }
                continue;
            }

            try {
                $contact = Contact::create($row);
                if ($dto->labelIds) {
                    $contact->labels()->sync($dto->labelIds);
                }
                $result->incrementImported();
            } catch (\Exception $e) {
                $result->addError("Phone {$row['phone']}: " . $e->getMessage());
            }
        }
    }
}
