<?php
namespace App\Modules\PhoneNumber\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PhoneNumber\Services\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneNumberController extends Controller
{
    public function __construct(private readonly PhoneNumberService $service) {}

    public function index(): JsonResponse
    {
        $numbers = $this->service->list(auth()->user()->company_id);
        return response()->json(['phone_numbers' => $numbers->map(fn($n) => [
            'id'             => $n->id,
            'label'          => $n->label,
            'phone_number_id'=> $n->phone_number_id,
            'display_number' => $n->display_number,
            'is_active'      => $n->is_active,
            'is_default'     => $n->is_default,
            'status'         => $n->status,
            'last_verified_at' => $n->last_verified_at?->toIso8601String(),
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'               => ['required','string','max:80'],
            'phone_number_id'     => ['required','string'],
            'access_token'        => ['required','string'],
            'business_account_id' => ['nullable','string'],
            'display_number'      => ['nullable','string','max:25'],
        ]);
        $num = $this->service->create(auth()->user()->company_id, $data);
        return response()->json(['message' => 'Phone number added.', 'phone_number' => $num], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'label'          => ['sometimes','string','max:80'],
            'display_number' => ['nullable','string','max:25'],
            'access_token'   => ['sometimes','string'],
            'is_active'      => ['sometimes','boolean'],
        ]);
        $num = $this->service->update($id, auth()->user()->company_id, $data);
        return response()->json(['message' => 'Updated.', 'phone_number' => $num]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id, auth()->user()->company_id);
        return response()->json(['message' => 'Phone number removed.']);
    }

    public function setDefault(int $id): JsonResponse
    {
        $num = $this->service->setDefault($id, auth()->user()->company_id);
        return response()->json(['message' => 'Default number updated.', 'phone_number' => $num]);
    }

    public function verify(int $id): JsonResponse
    {
        $result = $this->service->verify($id, auth()->user()->company_id);
        return response()->json($result);
    }
}
