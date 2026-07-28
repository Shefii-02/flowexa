<?php


namespace App\Modules\Blacklist\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MessageBlacklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlacklistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $list = MessageBlacklist::where('company_id', auth()->user()->company_id)
            ->when($request->search, fn($q) => $q->where('phone', 'like', "%{$request->search}%"))
            ->latest()->paginate(30);
        return response()->json($list);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['phone' => ['required','string','max:25'], 'reason' => ['nullable','string','max:300']]);
        $entry = MessageBlacklist::firstOrCreate(
            ['company_id' => auth()->user()->company_id, 'phone' => preg_replace('/\D/','',$d['phone'])],
            ['reason' => $d['reason'] ?? null, 'created_by' => auth()->id()]
        );
        return response()->json(['message' => 'Number blacklisted.', 'entry' => $entry], 201);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required','file','mimes:csv,txt','max:5120']]);
        $handle  = fopen($request->file('file')->getRealPath(), 'r');
        fgetcsv($handle); // skip header
        $added = 0; $skipped = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $phone = preg_replace('/\D/', '', trim($row[0] ?? ''));
            if (!$phone) { $skipped++; continue; }
            MessageBlacklist::firstOrCreate(
                ['company_id' => auth()->user()->company_id, 'phone' => $phone],
                ['created_by' => auth()->id()]
            ) ? $added++ : $skipped++;
        }
        fclose($handle);
        return response()->json(['message' => "Imported {$added} numbers, {$skipped} skipped."]);
    }

    public function destroy(int $id): JsonResponse
    {
        MessageBlacklist::where('id',$id)->where('company_id',auth()->user()->company_id)->delete();
        return response()->json(['message' => 'Removed from blacklist.']);
    }

    public function check(Request $request): JsonResponse
    {
        $phone   = preg_replace('/\D/', '', $request->phone ?? '');
        $blocked = MessageBlacklist::where('company_id',auth()->user()->company_id)->where('phone',$phone)->exists();
        return response()->json(['phone' => $phone, 'blocked' => $blocked]);
    }
}
