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

    /**
     * Meta Embedded Signup: exchange the popup's code for a token, read the number's details
     * (display number, verified name) and save it exactly like a manual "Advanced" connect.
     */
    public function connectEmbedded(int $companyId, string $code, string $phoneNumberId, ?string $wabaId, ?string $label): WaPhoneNumber
    {
        $cfg   = config('services.messenger');
        $graph = 'https://graph.facebook.com/v21.0';

        $tok = Http::timeout(15)->get("$graph/oauth/access_token", [
            'client_id' => $cfg['app_id'], 'client_secret' => $cfg['app_secret'], 'code' => $code,
        ]);
        $token = $tok->json('access_token');
        if (!$token) {
            throw new \Exception($tok->json('error.message') ?? 'Meta did not return an access token.');
        }

        $info = Http::withToken($token)->timeout(15)
            ->get("$graph/$phoneNumberId", ['fields' => 'display_phone_number,verified_name'])->json() ?? [];

        if ($wabaId) {
            // Route this WABA's webhooks to our app, and register the number for Cloud API sending.
            Http::withToken($token)->timeout(15)->post("$graph/$wabaId/subscribed_apps");
        }
        Http::withToken($token)->timeout(15)->post("$graph/$phoneNumberId/register", [
            'messaging_product' => 'whatsapp', 'pin' => '000000',
        ]);

        $fields = [
            'label'               => $label ?: ($info['verified_name'] ?? 'WhatsApp'),
            'access_token'        => encrypt($token),
            'business_account_id' => $wabaId,
            'display_number'      => $info['display_phone_number'] ?? null,
            'is_active'           => true,
            'status'              => 'active',
            'last_verified_at'    => now(),
            'last_error'          => null,
        ];

        $existing = WaPhoneNumber::where('company_id', $companyId)->where('phone_number_id', $phoneNumberId)->first();
        if ($existing) {
            $existing->update($fields);
            return $existing->fresh();
        }
        return WaPhoneNumber::create($fields + [
            'company_id'      => $companyId,
            'phone_number_id' => $phoneNumberId,
            'is_default'      => WaPhoneNumber::where('company_id', $companyId)->count() === 0,
        ]);
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
