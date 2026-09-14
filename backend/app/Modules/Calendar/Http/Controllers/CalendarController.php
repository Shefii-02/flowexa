<?php

namespace App\Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\Company;
use App\Modules\Calendar\Services\CalendarService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendar) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->company();
        $from = $request->filled('from') ? Carbon::parse($request->query('from')) : now()->subDays(7);
        $to   = $request->filled('to') ? Carbon::parse($request->query('to')) : now()->addDays(30);

        return response()->json(['events' => $this->calendar->upcoming($company, $from, $to)]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'title'          => ['required', 'string', 'max:200'],
            'description'    => ['nullable', 'string', 'max:2000'],
            'location'       => ['nullable', 'string', 'max:200'],
            'type'           => ['nullable', Rule::in(CalendarEvent::TYPES)],
            'starts_at'      => ['required', 'date'],
            'ends_at'        => ['required', 'date', 'after:starts_at'],
            'timezone'       => ['nullable', 'string', 'max:60'],
            'contact_id'     => ['nullable', 'integer', 'exists:contacts,id'],
            'lead_id'        => ['nullable', 'integer', 'exists:leads,id'],
            'assigned_to'    => ['nullable', 'integer', 'exists:users,id'],
            'attendee_email' => ['nullable', 'email'],
            'want_meet_link' => ['nullable', 'boolean'],
            'skip_conflict_check' => ['nullable', 'boolean'],
        ]);

        $company = $this->company();
        $starts  = Carbon::parse($d['starts_at']);
        $ends    = Carbon::parse($d['ends_at']);

        if (!($d['skip_conflict_check'] ?? false) && !empty($d['assigned_to'])
            && $this->calendar->hasConflict($company, $starts, $ends, $d['assigned_to'])) {
            return response()->json(['message' => 'This staff member already has something booked in that window.'], 409);
        }

        $event = $this->calendar->book($company, array_merge($d, ['created_by' => auth()->id()]));

        return response()->json(['message' => 'Booked.', 'event' => $event], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $event = $this->find($id);
        $d = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['required', 'date', 'after:starts_at'],
        ]);
        $event = $this->calendar->reschedule($event, Carbon::parse($d['starts_at']), Carbon::parse($d['ends_at']));

        return response()->json(['message' => 'Rescheduled.', 'event' => $event]);
    }

    public function complete(int $id): JsonResponse
    {
        $event = $this->find($id);
        $event->update(['status' => 'completed']);
        return response()->json(['message' => 'Marked completed.', 'event' => $event]);
    }

    public function noShow(int $id): JsonResponse
    {
        $event = $this->find($id);
        $event->update(['status' => 'no_show']);
        return response()->json(['message' => 'Marked as no-show.', 'event' => $event]);
    }

    public function destroy(int $id): JsonResponse
    {
        $event = $this->find($id);
        $this->calendar->cancel($event);
        return response()->json(['message' => 'Cancelled.']);
    }

    private function company(): Company
    {
        return Company::findOrFail(auth()->user()->company_id);
    }

    private function find(int $id): CalendarEvent
    {
        return CalendarEvent::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }
}
