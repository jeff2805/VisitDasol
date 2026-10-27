<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Contracts\View\View;

/**
 * Public announcements. Drafts and anything scheduled for a later date
 * are never listed or reachable.
 */
class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('site.announcements.index', [
            'announcements' => Announcement::published()
                ->orderByDesc('is_featured')
                ->latest('published_at')
                ->paginate(config('visitdasol.per_page')),
        ]);
    }

    public function show(Announcement $announcement): View
    {
        abort_unless($announcement->isPublished(), 404);

        return view('site.announcements.show', [
            'announcement' => $announcement,
            'more' => Announcement::published()
                ->whereKeyNot($announcement->id)
                ->latest('published_at')
                ->limit(3)
                ->get(),
        ]);
    }
}
