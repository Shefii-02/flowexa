<?php

namespace App\Modules\Contact\Services;

use App\Models\Contact;
use App\Modules\Contact\DTOs\ContactFilterDTO;
use App\Modules\Contact\DTOs\CreateContactDTO;
use App\Modules\Contact\DTOs\ImportContactDTO;
use App\Modules\Contact\DTOs\ImportResultDTO;
use App\Modules\Contact\DTOs\UpdateContactDTO;
use App\Modules\Contact\Exceptions\ContactException;
use App\Modules\Contact\Repositories\Interfaces\ContactRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ContactService
{
    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
    ) {}

    // ─── List ─────────────────────────────────────────────────────────────────
    public function list(int $companyId, ContactFilterDTO $filter): LengthAwarePaginator
    {
        return $this->contactRepository->paginate($companyId, $filter);
    }

    // ─── Show ─────────────────────────────────────────────────────────────────
    public function show(int $id, int $companyId): Contact
    {
        $contact = $this->contactRepository->findById($id, $companyId);
        if (!$contact) throw ContactException::notFound();
        return $contact;
    }

    // ─── Create ───────────────────────────────────────────────────────────────
    public function create(int $companyId, CreateContactDTO $dto): Contact
    {
        if ($this->contactRepository->phoneExists($dto->phone, $companyId)) {
            throw ContactException::phoneDuplicate($dto->phone);
        }

        return $this->contactRepository->create($companyId, $dto);
    }

    // ─── Update ───────────────────────────────────────────────────────────────
    public function update(int $id, int $companyId, UpdateContactDTO $dto): Contact
    {
        $contact = $this->contactRepository->findById($id, $companyId);
        if (!$contact) throw ContactException::notFound();

        return $this->contactRepository->update($contact, $dto);
    }

    // ─── Sync labels ──────────────────────────────────────────────────────────
    public function syncLabels(int $id, int $companyId, array $labelIds): Contact
    {
        $contact = $this->contactRepository->findById($id, $companyId);
        if (!$contact) throw ContactException::notFound();

        $this->contactRepository->syncLabels($contact, $labelIds);
        return $contact->fresh('labels');
    }

    // ─── Remove label ─────────────────────────────────────────────────────────
    public function removeLabel(int $contactId, int $companyId, int $labelId): Contact
    {
        $contact = $this->contactRepository->findById($contactId, $companyId);
        if (!$contact) throw ContactException::notFound();

        $this->contactRepository->removeLabel($contact, $labelId);
        return $contact->fresh('labels');
    }

    // ─── Opt out ──────────────────────────────────────────────────────────────
    public function optOut(int $id, int $companyId): Contact
    {
        $contact = $this->contactRepository->findById($id, $companyId);
        if (!$contact) throw ContactException::notFound();

        if (!$contact->opted_in) {
            throw ContactException::alreadyOptedOut();
        }

        return $this->contactRepository->optOut($contact);
    }

    // ─── Opt in ───────────────────────────────────────────────────────────────
    public function optIn(int $id, int $companyId): Contact
    {
        $contact = $this->contactRepository->findById($id, $companyId);
        if (!$contact) throw ContactException::notFound();

        if ($contact->opted_in) {
            throw ContactException::alreadyOptedIn();
        }

        return $this->contactRepository->optIn($contact);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    public function delete(int $id, int $companyId): void
    {
        $contact = $this->contactRepository->findById($id, $companyId);
        if (!$contact) throw ContactException::notFound();

        $this->contactRepository->delete($contact);
    }

    // ─── Import CSV ───────────────────────────────────────────────────────────
    public function import(int $companyId, ImportContactDTO $dto): ImportResultDTO
    {
        return $this->contactRepository->import($companyId, $dto);
    }

    // ─── Export CSV ───────────────────────────────────────────────────────────
    public function export(int $companyId, ContactFilterDTO $filter): string
    {
        $contacts = $this->contactRepository->exportAll($companyId, $filter);

        $filename = 'contacts_export_' . now()->format('Ymd_His') . '.csv';
        $path     = 'exports/' . $filename;

        // Ensure the exports directory exists on this disk before writing to it
        if (!Storage::exists('exports')) {
            Storage::makeDirectory('exports');
        }

        $handle = fopen(Storage::path($path), 'w');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open export file for writing: {$path}");
        }

        // Header row
        fputcsv($handle, ['phone', 'name', 'email', 'opted_in', 'labels', 'created_at']);

        foreach ($contacts as $contact) {
            fputcsv($handle, [
                $contact->phone,
                $contact->name,
                $contact->email,
                $contact->opted_in ? 'yes' : 'no',
                $contact->labels->pluck('name')->implode(', '),
                $contact->created_at->toDateTimeString(),
            ]);
        }


        fclose($handle);
        return $path;
    }
}
