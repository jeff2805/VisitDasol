<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Owner\ResortImageRequest;
use App\Models\ResortImage;
use App\Services\ImageUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Photos of the owner's own resort.
 *
 * Every action authorizes against the photo's parent resort, so an owner
 * cannot delete or reuse a photo belonging to someone else's listing,
 * even by putting another photo's id in the URL.
 */
class ResortImageController extends Controller
{
    public function __construct(private readonly ImageUploadService $images) {}

    public function store(ResortImageRequest $request): RedirectResponse
    {
        $resort = $request->user()->resort()->first();
        abort_if($resort === null, 404);

        $this->authorize('manageImages', $resort);

        $nextOrder = (int) $resort->images()->max('sort_order');

        DB::transaction(function () use ($request, $resort, &$nextOrder): void {
            foreach ($request->file('images') as $file) {
                $resort->images()->create([
                    'path' => $this->images->store($file, "resorts/{$resort->id}/gallery"),
                    'sort_order' => ++$nextOrder,
                ]);
            }
        });

        $count = count($request->file('images'));

        return back()->with('success', $count.' '.str('photo')->plural($count).' added.');
    }

    /** Use an existing gallery photo as the cover photo. */
    public function setCover(Request $request, ResortImage $image): RedirectResponse
    {
        $resort = $image->resort;

        $this->authorize('manageImages', $resort);
        abort_unless($resort->isOwnedBy($request->user()), 403);

        // Only remove the old cover if it is not one of the gallery photos.
        if (filled($resort->main_image) && ! $resort->images()->where('path', $resort->main_image)->exists()) {
            $this->images->delete($resort->main_image);
        }

        $resort->forceFill(['main_image' => $image->path])->save();

        return back()->with('success', 'Cover photo updated.');
    }

    public function destroy(Request $request, ResortImage $image): RedirectResponse
    {
        $resort = $image->resort;

        $this->authorize('manageImages', $resort);
        abort_unless($resort->isOwnedBy($request->user()), 403);

        DB::transaction(function () use ($image, $resort): void {
            if ($resort->main_image === $image->path) {
                $resort->forceFill(['main_image' => null])->save();
            }

            $path = $image->path;
            $image->delete();
            $this->images->delete($path);
        });

        return back()->with('success', 'Photo removed.');
    }
}
