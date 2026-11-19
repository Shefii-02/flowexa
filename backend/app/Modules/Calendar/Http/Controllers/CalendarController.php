<?php

namespace App\Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\Company;
use App\Modules\Calendar\Services\CalendarService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Bookings. Without `calendar.view_all` a staff member only sees bookings that are theirs (assigned to them or created by
 * them); without `calendar.manage_all` they can only book for, change and cancel their own.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendar) {}

    private function canViewAll(): bool
    {
        $u = auth()->user();
        return $u->isOwner() || $u->isSuperAdmin() || $u->hasPermission('calendar.view_all');
    }

    private function canManageAll(): bool
    {
        $u = auth()->user();
        return $u->isOwner() || $u->isSuperAdmin() || $u->hasPermission('calendar.manage_all');
    }

    /** Is this booking the caller's to change? */
    private function mine(CalendarEvent $e): bool
    {
        $id = (int) auth()->id();
        return (int) $e->assigned_to === $id || (int) $e->created_by === $id;
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'staff_id' => ['nullable', 'integer'], 'mine' => ['nullable', 'boolean']]);
        $company = $this->company();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : now()->subDays(7);
        $to   = $request->filled('to') ? Carbon::parse($request->input('to')) : now()->addDays(30);

        $all = $this->canViewAll();
        $onlyMine = !$all || $request->boolean('mine');
        $events = $this->calendar->upcoming($company, $from, $to, $onlyMine ? (int) auth()->id() : null, $all && !$onlyMine ? ($request->integer('staff_id') ?: null) : null);

        return response()->json(['events' => $events, 'can_view_all' => $all, 'can_manage_all' => $this->canManageAll(), 'me' => (int) auth()->id()]);
    }

    public function store(Request $request): JsonResponse
    {
        $cid = (int) auth()->user()->company_id;
        $d = $request->validate([
            'title'          => ['required', 'string', 'max:200'],
            'description'    => ['nullable', 'string', 'max:2000'],
            'location'       => ['nullable', 'string', 'max:200'],
            'type'           => ['nullable', Rule::in(CalendarEvent::TYPES)],
            'starts_at'      => ['required', 'date'],
            'ends_at'        => ['required', 'date', 'after:starts_at'],
            'timezone'       => ['nullable', 'string', 'max:60'],
            'contact_id'     => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $cid)],
            'lead_id'        => ['nullable', 'integer', Rule::exists('leads', 'id')->where('company_id', $cid)],
            'assigned_to'    => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $cid)],
            'attendee_email' => ['nullable', 'email'],
            'want_meet_link' => ['nullable', 'boolean'],
            'skip_conflict_check' => ['nullable', 'boolean'],
        ]);

        // someone without "manage all" books for themselves — never for a colleague
        if (!$this->canManageAll()) {
            if (!empty($d['assigned_to']) && (int) $d['assigned_to'] !== (int) auth()->id()) {
                return response()->json(['message' => 'You can only book for yourself.'], 403);
            }
            $d['assigned_to'] = auth()->id();
        }

        $company = $this->company();
        $starts  = Carbon::parse($d['starts_at']);
        $ends    = Carbon::parse($d['ends_at']);

        if (!($d['skip_conflict_check'] ?? false) && !empty($d['assigned_to'])
            && $this->calendar->hasConflict($company, $starts, $ends, $d['assigned_to'])) {
            return response()->json(['message' => 'This staff member already has something booked in that window.', 'conflict' => true], 409);
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
            'skip_conflict_check' => ['nullable', 'boolean'],
        ]);
        $starts = Carbon::parse($d['starts_at']); $ends = Carbon::parse($d['ends_at']);

        if (!($d['skip_conflict_check'] ?? false) && $event->assigned_to && $event->status === 'scheduled'
            && $this->calendar->hasConflict($this->company(), $starts, $ends, (int) $event->assigned_to, $event->id)) {
            return response()->json(['message' => 'This staff member already has something booked in that window.', 'conflict' => true], 409);
        }
        $event = $this->calendar->reschedule($event, $starts, $ends);

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

    /** A booking of this company the caller may change: any if they manage all, otherwise only their own. Others' are "not found". */
    private function find(int $id): CalendarEvent
    {
        $event = CalendarEvent::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
        abort_unless($this->canManageAll() || $this->mine($event), 404, 'Booking not found.');
        return $event;
    }
}
