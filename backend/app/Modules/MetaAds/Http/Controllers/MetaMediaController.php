<?php
namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Services\MetaAdsService;
use App\Models\{MetaAdAccount, MetaCampaign, MetaAdSet, MetaAd, MetaMediaLibrary, MetaAdCreative, MetaInsight};
use Illuminate\Http\{JsonResponse, Request};

class MetaMediaController extends Controller
{
    public function __construct(private MetaAdsService $svc) {}

    public function index(Request $request): JsonResponse {
        $media = MetaMediaLibrary::where('company_id', auth()->user()->company_id)
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->where('upload_status', 'ready')
            ->latest()->paginate(20);
        return response()->json($media);
    }

    public function uploadImage(Request $request): JsonResponse {
        $request->validate(['file' => ['required','file','mimes:jpg,jpeg,png,gif','max:10240'], 'account_id' => ['required','integer']]);
        $account = MetaAdAccount::where('id',$request->account_id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $file    = $request->file('file');
        $path    = $file->store("meta-ads/{$account->company_id}/images", 'local');
        $media   = $this->svc->uploadImage($account, storage_path("app/{$path}"), $file->getClientOriginalName());
        return response()->json(['message' => 'Image uploaded.', 'media' => $media], 201);
    }

    public function uploadVideo(Request $request): JsonResponse {
        $request->validate(['file' => ['required','file','mimes:mp4,mov,avi','max:512000'], 'account_id' => ['required','integer'], 'title' => ['nullable','string','max:150']]);
        $account = MetaAdAccount::where('id',$request->account_id)->where('company_id',auth()->user()->company_id)->firstOrFail();
        $file    = $request->file('file');
        $path    = $file->store("meta-ads/{$account->company_id}/videos", 'local');
        $media   = $this->svc->uploadVideo($account, storage_path("app/{$path}"), $file->getClientOriginalName(), $request->title ?? 'Ad video');
        return response()->json(['message' => 'Video uploaded.', 'media' => $media], 201);
    }

    public function destroy(int $id): JsonResponse {
        MetaMediaLibrary::where('id',$id)->where('company_id',auth()->user()->company_id)->firstOrFail()->delete();
        return response()->json(['message' => 'Media deleted.']);
    }
}
