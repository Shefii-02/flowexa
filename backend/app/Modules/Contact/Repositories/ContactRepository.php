<?php

namespace App\Modules\Contact\Repositories;

use App\Models\Contact;
use App\Models\ContactLabel;
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

        return Contact::with(['labels', 'currentAssignment.staff'])
            ->where('company_id', $companyId)
            ->when($filter->search, function ($q) use ($filter) {
                $term    = $filter->search;
                $stripped = preg_replace('/@[a-z.]+$/i', '', $term);
                $digits   = preg_replace('/[^0-9]/', '', $stripped);
                $last10   = strlen($digits) >= 7 ? substr($digits, -10) : null;
                $q->where(function ($inner) use ($term, $last10) {
                    $inner->where('name',  'like', "%{$term}%")
                          ->orWhere('phone', 'like', "%{$term}%")
                          ->orWhere('email', 'like', "%{$term}%")
                          // Exact match on the stored WhatsApp id: the one reliable way to find a
                          // contact by an @lid chat id, whose digits aren't a phone number at all.
                          ->orWhere('wa_id', $term);
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
        return Contact::with(['labels', 'currentAssignment.staff', 'leads.assignedTo', 'messages' => fn($q) => $q->latest()->limit(20)])
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
            'wa_id'         => $dto->waId,
            'custom_fields' => $dto->customFields,
            'opted_in'      => $dto->optedIn,
        ]);

        if ($dto->labelIds) {
            $contact->labels()->sync($dto->labelIds);
        }

        return $contact->load(['labels', 'currentAssignment.staff']);
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
        return $contact->fresh(['labels', 'currentAssignment.staff']);
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

    /** Labels chosen in the import form (already limited to this company's own labels). */
    private array $importLabelIds = [];

    // ─── CSV Import ───────────────────────────────────────────────────────────
    public function import(int $companyId, ImportContactDTO $dto): ImportResultDTO
    {
        $result = new ImportResultDTO();
        $path   = Storage::path($dto->filePath);

        if (!file_exists($path)) {
            $result->addError('Uploaded file not found on server.');
            return $result;
        }

        $handle = fopen($path, 'r');
        $raw    = fgetcsv($handle);
        if ($raw === false || $raw === [null]) {
            fclose($handle);
            Storage::delete($dto->filePath);
            $result->addError('The CSV file is empty.');
            return $result;
        }

        // Excel/Sheets exports often start with a UTF-8 BOM, which glues itself to the first header
        // ("\xEF\xBB\xBFphone") so a perfectly good "phone" column is not recognised. Also tolerate
        // quotes/case and the usual alternative names for the number column.
        $headers = array_map(function ($h) {
            $h = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $h), " \t\n\r\0\x0B\"'"));
            return in_array($h, ['phone_number', 'phone number', 'mobile', 'mobile_number', 'mobile number', 'whatsapp', 'whatsapp_number', 'whatsapp number', 'number', 'contact_number', 'contact number'], true)
                ? 'phone' : $h;
        }, $raw);

        if (!in_array('phone', $headers, true)) {
            fclose($handle);                     // was fclose($path): a string, which crashed the request with a 500
            Storage::delete($dto->filePath);
            $result->addError('CSV must contain a "phone" column (found: ' . implode(', ', array_filter($headers)) . ').');
            return $result;
        }

        // Labels: (1) the ones picked in the import form, applied to EVERY contact in the file — new and
        // already-existing alike, so a label can be used to build an audience from a CSV; limited to this
        // company's own labels (the request only checks the id exists somewhere). (2) an optional
        // "labels"/"label" column of existing label names (comma, semicolon or | separated).
        $this->importLabelIds = ContactLabel::where('company_id', $companyId)
            ->whereIn('id', $dto->labelIds)->pluck('id')->all();
        $labelByName = ContactLabel::where('company_id', $companyId)->get()
            ->keyBy(fn ($l) => strtolower(trim($l->name)));
        $labelCol   = collect(['labels', 'label', 'tags', 'tag'])->first(fn ($c) => in_array($c, $headers, true));
        $unknownLabels = [];

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

            $rowLabels = [];
            if ($labelCol && !empty($data[$labelCol])) {
                foreach (preg_split('/[,;|]/', (string) $data[$labelCol]) as $name) {
                    $name = strtolower(trim($name));
                    if ($name === '') continue;
                    if ($labelByName->has($name)) $rowLabels[] = $labelByName[$name]->id;
                    else $unknownLabels[$name] = true;
                }
            }

            $phones[] = $phone;
            $batch[]  = [
                '_labels'       => $rowLabels,   // stripped in processBatch before the insert
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

        if ($unknownLabels) {
            // informational only (not counted as failed rows): these names don't exist, so nothing was attached
            array_unshift($result->errors, 'Labels not found and ignored: ' . implode(', ', array_keys($unknownLabels)) . '. Create them first, then re-import.');
        }
        $this->importLabelIds = [];

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
            $labelIds = array_values(array_unique(array_merge($this->importLabelIds, $row['_labels'] ?? [])));
            unset($row['_labels']);

            if (isset($existing[$row['phone']])) {
                // Already in the system: never touch its data when skipping duplicates, but DO give it the
                // import's labels — otherwise a label-based import silently misses everyone already known.
                if ($labelIds) {
                    Contact::where('company_id', $companyId)->find($existing[$row['phone']])?->labels()->syncWithoutDetaching($labelIds);
                }
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
                if ($labelIds) {
                    $contact->labels()->sync($labelIds);
                }
                $result->incrementImported();
            } catch (\Exception $e) {
                $result->addError("Phone {$row['phone']}: " . $e->getMessage());
            }
        }
    }
}
