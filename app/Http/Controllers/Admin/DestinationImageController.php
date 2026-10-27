<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DestinationImageRequest;
use App\Models\Destination;
use App\Models\DestinationImage;
use App\Services\ImageUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Photos of one destination. Routes bind the photo inside its destination
 * (scoped bindings), so a photo from another record cannot be reached.
 */
class DestinationImageController extends Controller
{
    public function __construct(private readonly ImageUploadService $images) {}

    public function store(DestinationImageRequest $request, Destination $destination): RedirectResponse
    {
        $this->authorize('manageImages', $destination);

        $nextOrder = (int) $destination->images()->max('sort_order');

        DB::transaction(function () use ($request, $destination, &$nextOrder): void {
            foreach ($request->file('images') as $file) {
                $destination->images()->create([
                    'path' => $this->images->store($file, "destinations/{$destination->id}/gallery"),
                    'sort_order' => ++$nextOrder,
                ]);
            }
        });

        $count = count($request->file('images'));

        return back()->with('success', $count.' '.str('photo')->plural($count).' added.');
    }

    public function setCover(Destination $destination, DestinationImage $image): RedirectResponse
    {
        $this->authorize('manageImages', $destination);

        if (filled($destination->main_image) && ! $destination->images()->where('path', $destination->main_image)->exists()) {
            $this->images->delete($destination->main_image);
        }

        $destination->forceFill(['main_image' => $image->path])->save();

        return back()->with('success', 'Cover photo updated.');
    }

    public function destroy(Destination $destination, DestinationImage $image): RedirectResponse
    {
        $this->authorize('manageImages', $destination);

        DB::transaction(function () use ($destination, $image): void {
            if ($destination->main_image === $image->path) {
                $destination->forceFill(['main_image' => null])->save();
            }

            $path = $image->path;
            $image->delete();
            $this->images->delete($path);
        });

        return back()->with('success', 'Photo removed.');
    }
}
