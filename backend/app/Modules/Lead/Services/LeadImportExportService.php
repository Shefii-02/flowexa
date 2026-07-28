<?php
namespace App\Modules\Lead\Services;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadImport;
use Illuminate\Support\Facades\Storage;

class LeadImportExportService
{
    // ── Import leads from CSV ─────────────────────────────────────────────────
    public function import(int $companyId, int $userId, \Illuminate\Http\UploadedFile $file): LeadImport
    {
        $path   = $file->store("lead-imports/{$companyId}", 'local');
        $import = LeadImport::create([
            'company_id' => $companyId,
            'user_id'    => $userId,
            'file_path'  => $path,
            'status'     => 'processing',
        ]);

        // Process synchronously (for small files) or dispatch job for large
        $this->processImport($import, $companyId);

        return $import->fresh();
    }

    private function processImport(LeadImport $import, int $companyId): void
    {
        $handle   = fopen(Storage::path($import->file_path), 'r');
        $headers  = array_map('trim', fgetcsv($handle));
        $imported = 0; $skipped = 0; $failed = 0; $errors = [];
        $row = 0;

        while (($line = fgetcsv($handle)) !== false) {
            $row++;
            if (count($line) < count($headers)) { $skipped++; continue; }
            $data  = array_combine($headers, $line);
            $phone = preg_replace('/\D/', '', trim($data['phone'] ?? ''));

            if (!$phone) { $errors[] = "Row {$row}: missing phone"; $failed++; continue; }

            try {
                // Find or create contact
                $contact = Contact::firstOrCreate(
                    ['company_id' => $companyId, 'phone' => $phone],
                    ['name' => $data['name'] ?? null, 'email' => $data['email'] ?? null, 'opted_in' => true]
                );

                // Skip if active lead already exists
                $existing = Lead::where('contact_id', $contact->id)->where('company_id', $companyId)
                    ->whereNotIn('stage', ['enrolled','lost'])->exists();
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

    // ── Export leads to CSV ───────────────────────────────────────────────────
    public function export(int $companyId, array $filters = []): string
    {
        $leads = Lead::with(['contact', 'assignedTo'])
            ->where('company_id', $companyId)
            ->when($filters['stage']    ?? null, fn($q) => $q->where('stage', $filters['stage']))
            ->when($filters['category'] ?? null, fn($q) => $q->where('category', $filters['category']))
            ->get();

        $filename = "leads_export_" . now()->format('Ymd_His') . ".csv";
        $path     = "exports/{$filename}";
        $handle   = fopen(Storage::path($path), 'w');

        fputcsv($handle, ['id','phone','name','email','stage','priority','category','source','assigned_to','notes','created_at']);

        foreach ($leads as $l) {
            fputcsv($handle, [
                $l->id,
                $l->contact?->phone,
                $l->contact?->name,
                $l->contact?->email,
                $l->stage,
                $l->priority,
                $l->category,
                $l->source,
                $l->assignedTo?->name,
                $l->notes,
                $l->created_at->toDateTimeString(),
            ]);
        }

        fclose($handle);
        return $path;
    }
}
