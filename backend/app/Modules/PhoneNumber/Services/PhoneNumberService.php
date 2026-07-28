<?php
namespace App\Modules\PhoneNumber\Services;

use App\Models\WaPhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;

class PhoneNumberService
{
    public function list(int $companyId): Collection
    {
        return WaPhoneNumber::where('company_id', $companyId)->get();
    }

    public function create(int $companyId, array $data): WaPhoneNumber
    {
        $num = WaPhoneNumber::create([
            'company_id'          => $companyId,
            'label'               => $data['label'],
            'phone_number_id'     => $data['phone_number_id'],
            'access_token'        => encrypt($data['access_token']),
            'business_account_id' => $data['business_account_id'] ?? null,
            'display_number'      => $data['display_number'] ?? null,
            'is_active'           => true,
            'is_default'          => WaPhoneNumber::where('company_id', $companyId)->count() === 0,
        ]);

        return $num;
    }

    public function update(int $id, int $companyId, array $data): WaPhoneNumber
    {
        $num = WaPhoneNumber::where('id', $id)->where('company_id', $companyId)->firstOrFail();
        $payload = array_filter([
            'label'               => $data['label'] ?? null,
            'display_number'      => $data['display_number'] ?? null,
            'is_active'           => isset($data['is_active']) ? (bool)$data['is_active'] : null,
        ], fn($v) => !is_null($v));
        if (!empty($data['access_token'])) {
            $payload['access_token'] = encrypt($data['access_token']);
        }
        $num->update($payload);
        return $num->fresh();
    }

    public function delete(int $id, int $companyId): void
    {
        $num = WaPhoneNumber::where('id', $id)->where('company_id', $companyId)->firstOrFail();
        if ($num->is_default) {
            throw new \Exception('Cannot delete the default phone number. Set another as default first.');
        }
        $num->delete();
    }

    public function setDefault(int $id, int $companyId): WaPhoneNumber
    {
        WaPhoneNumber::where('company_id', $companyId)->update(['is_default' => false]);
        $num = WaPhoneNumber::where('id', $id)->where('company_id', $companyId)->firstOrFail();
        $num->update(['is_default' => true]);
        return $num->fresh();
    }

    public function verify(int $id, int $companyId): array
    {
        $num = WaPhoneNumber::where('id', $id)->where('company_id', $companyId)->firstOrFail();
        try {
            $response = Http::withToken(decrypt($num->access_token))
                ->timeout(10)
                ->get("https://graph.facebook.com/v21.0/{$num->phone_number_id}");

            if ($response->successful()) {
                $num->update(['status' => 'active', 'last_verified_at' => now(), 'last_error' => null]);
                return ['verified' => true, 'data' => $response->json()];
            }

            $err = $response->json('error.message') ?? 'Unknown error';
            $num->update(['status' => 'error', 'last_error' => $err]);
            return ['verified' => false, 'error' => $err];
        } catch (\Exception $e) {
            $num->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            return ['verified' => false, 'error' => $e->getMessage()];
        }
    }
}
