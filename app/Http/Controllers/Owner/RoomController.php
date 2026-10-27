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

    public function destroy(Room $room): RedirectResponse
    {
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
