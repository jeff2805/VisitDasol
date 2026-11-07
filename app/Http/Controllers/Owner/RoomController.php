<?php

namespace App\Http\Controllers\Owner;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\RoomRequest;
use App\Models\Resort;
use App\Models\Room;
use App\Services\ActivityLogger;
use App\Services\AvailabilityService;
use App\Services\ImageUploadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Room types of the signed-in owner's resort.
 *
 * The resort is always taken from the owner, and every room action is
 * checked against RoomPolicy, which looks at the room's resort. So an
 * owner cannot read or change another resort's rooms by changing the URL.
 */
class RoomController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly ImageUploadService $images,
        private readonly ActivityLogger $logger,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $resort = $this->resortOrRedirect($request);

        if ($resort instanceof RedirectResponse) {
            return $resort;
        }

        $this->authorize('viewAny', Room::class);

        $rooms = $resort->rooms()->with('images')->withCount('bookings')->orderBy('name')->get();

        return view('owner.rooms.index', [
            'resort' => $resort,
            'rooms' => $rooms,
            'committed' => $this->availability->peakCommittedAheadForRooms($rooms),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $resort = $this->resortOrRedirect($request);

        if ($resort instanceof RedirectResponse) {
            return $resort;
        }

        $this->authorize('create', Room::class);

        return view('owner.rooms.create', [
            'resort' => $resort,
            'room' => new Room,
            'roomTypes' => RoomType::options(),
        ]);
    }

    public function store(RoomRequest $request): RedirectResponse
    {
        $this->authorize('create', Room::class);

        $resort = $request->user()->resort()->first();
        abort_if($resort === null, 404);

        $room = new Room($request->roomAttributes());
        $room->resort()->associate($resort);
        $room->status = RoomStatus::Active;
        $room->save();

        $this->logger->log('room.created', $room, "Added the room type {$room->name} to {$resort->name}");

        return redirect()->route('owner.rooms.index')
            ->with('success', "{$room->name} has been added. Add photos so guests know what to expect.");
    }

    public function edit(Room $room): View
    {
        $this->authorize('update', $room);

        return view('owner.rooms.edit', [
            'resort' => $room->resort,
            'room' => $room->load('images'),
            'roomTypes' => RoomType::options(),
            'committed' => $this->availability->peakUnitsCommittedAhead($room),
        ]);
    }

    public function update(RoomRequest $request, Room $room): RedirectResponse
    {
        $this->authorize('update', $room);

        $room->fill($request->roomAttributes());
        $changed = array_keys($room->getDirty());
        $room->save();

        $this->logger->log('room.updated', $room, "Updated the room type {$room->name}", ['fields' => $changed]);

        return redirect()->route('owner.rooms.index')->with('success', "{$room->name} has been saved.");
    }

    /** Switch a room type on or off for booking. */
    public function status(Request $request, Room $room): RedirectResponse
    {
        $this->authorize('changeStatus', $room);

        $active = $room->status === RoomStatus::Active;
        $room->forceFill(['status' => $active ? RoomStatus::Inactive : RoomStatus::Active])->save();

        $this->logger->log(
            $active ? 'room.deactivated' : 'room.activated',
            $room,
            ($active ? 'Stopped bookings for ' : 'Opened bookings for ').$room->name,
        );

        $upcoming = $active ? $this->availability->peakUnitsCommittedAhead($room) : 0;

        return back()->with('success', $active
            ? "{$room->name} is no longer bookable."
                .($upcoming > 0 ? ' Reservations guests already hold are not affected.' : '')
            : "{$room->name} can be booked again.");
    }

    /**
     * Remove a room type.
     *
     * TWO REFUSALS, AND THEY ARE NOT THE SAME KIND OF THING.
     *
     * Somebody else's room is a 403 and stays one: there is nothing to
     * explain to a person asking for a room that is not theirs.
     *
     * Their OWN room with reservations against it is a business rule, and
     * it used to come back as the same bare 403 error page. That happens
     * for real and not only to somebody poking at URLs: the page draws the
     * delete button from bookings_count at the moment it loads, so a guest
     * booking the room while the owner is looking at the screen turns the
     * next click into an error page with no reason on it. It is answered
     * in words now, on the page they were already on.
     */
    public function destroy(Room $room): RedirectResponse
    {
        $this->authorize('update', $room);

        if ($room->bookings()->exists()) {
            return back()->with(
                'error',
                "{$room->name} has reservations against it, so it cannot be deleted — "
                ."removing it would take their booking history with it. Use \"Stop bookings\" "
                .'instead: the room disappears from the site and keeps its records.'
            );
        }

        // The policy is still the gate. It asks the same question a second
        // time, which is the point: a caller that reached here another way
        // is refused by the thing that owns the rule.
        $this->authorize('delete', $room);

        $name = $room->name;

        DB::transaction(function () use ($room): void {
            $paths = $room->images()->pluck('path');
            $room->images()->delete();
            $room->delete();

            foreach ($paths as $path) {
                $this->images->delete($path);
            }
        });

        $this->logger->log('room.deleted', null, "Deleted the room type {$name}");

        return redirect()->route('owner.rooms.index')->with('success', "{$name} has been removed.");
    }

    private function resortOrRedirect(Request $request): Resort|RedirectResponse
    {
        $resort = $request->user()->resort()->first();

        if ($resort === null) {
            return redirect()->route('owner.resort.create')
                ->with('info', 'Register your resort first, then add your room types.');
        }

        return $resort;
    }
}
