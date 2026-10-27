<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\RoomImageRequest;
use App\Models\Room;
use App\Models\RoomImage;
use App\Services\ImageUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Photos of one room type. The route binds the photo inside its room
 * (scoped bindings), and the policy checks the room's resort, so a photo
 * from another resort can never be reached through these URLs.
 */
class RoomImageController extends Controller
{
    public function __construct(private readonly ImageUploadService $images) {}

    public function store(RoomImageRequest $request, Room $room): RedirectResponse
    {
        $this->authorize('manageImages', $room);

        $nextOrder = (int) $room->images()->max('sort_order');

        DB::transaction(function () use ($request, $room, &$nextOrder): void {
            foreach ($request->file('images') as $file) {
                $room->images()->create([
                    'path' => $this->images->store($file, "rooms/{$room->id}"),
                    'sort_order' => ++$nextOrder,
                ]);
            }
        });

        $count = count($request->file('images'));

        return back()->with('success', $count.' '.str('photo')->plural($count).' added to '.$room->name.'.');
    }

    public function destroy(Room $room, RoomImage $image): RedirectResponse
    {
        $this->authorize('manageImages', $room);

        $path = $image->path;
        $image->delete();
        $this->images->delete($path);

        return back()->with('success', 'Photo removed.');
    }
}
