<?php
namespace App\Modules\Survey\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SurveyForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SurveyFormController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $forms = SurveyForm::where('company_id', auth()->user()->company_id)
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->withCount('responses')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json(['forms' => $forms->items(), 'total' => $forms->total()]);
    }

    public function show(int $id): JsonResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        return response()->json(['form' => $form]);
    }

    private function rules(): array
    {
        return [
            'name'                 => ['required', 'string', 'max:150'],
            'description'          => ['nullable', 'string', 'max:255'],
            'fields'               => ['required', 'array', 'min:1', 'max:20'],
            'fields.*.key'         => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'fields.*.question_text' => ['required', 'string', 'max:500'],
            'fields.*.type'        => ['required', Rule::in(['text', 'number', 'choice'])],
            'fields.*.options'     => ['nullable', 'array', 'required_if:fields.*.type,choice'],
            'fields.*.options.*'   => ['string', 'max:100'],
            'fields.*.required'    => ['nullable', 'boolean'],
            'is_active'            => ['nullable', 'boolean'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate($this->rules());

        // field keys must be unique within the form — collisions would overwrite answers
        $keys = array_column($d['fields'], 'key');
        if (count($keys) !== count(array_unique($keys))) {
            return response()->json(['message' => 'Field keys must be unique within the form.'], 422);
        }

        $form = SurveyForm::create([
            'company_id'  => auth()->user()->company_id,
            'name'        => $d['name'],
            'description' => $d['description'] ?? null,
            'fields'      => $d['fields'],
            'is_active'   => $d['is_active'] ?? true,
        ]);

        return response()->json(['form' => $form], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        $d = $request->validate($this->rules());

        $keys = array_column($d['fields'], 'key');
        if (count($keys) !== count(array_unique($keys))) {
            return response()->json(['message' => 'Field keys must be unique within the form.'], 422);
        }

        $form->update($d);
        return response()->json(['form' => $form->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        $form->delete();
        return response()->json(['message' => 'Survey form deleted.']);
    }

    // GET /survey-forms/{id}/responses — for the "view submissions" screen
    public function responses(int $id, Request $request): JsonResponse
    {
        $form = SurveyForm::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();

        $responses = $form->responses()
            ->with('contact:id,name,phone')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        return response()->json(['responses' => $responses->items(), 'total' => $responses->total()]);
    }
}
