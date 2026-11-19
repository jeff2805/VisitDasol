<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Contracts\View\View;

/**
 * Announcements from the Municipal Tourism Office, read inside the resort
 * owner's own dashboard.
 *
 * THE SAME ANNOUNCEMENTS THE PUBLIC READS, and deliberately so: there is no
 * separate channel to the resorts, and inventing one would mean the office
 * writing everything twice and the two drifting apart. What is different is
 * only where they are read — an owner who has to leave the dashboard and
 * find the public site is an owner who does not read them.
 *
 * Published only, through the same scope the public pages use. A draft the
 * office is still writing is not an advisory to anybody yet.
 */
class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('owner.announcements.index', [
            'announcements' => Announcement::published()
                ->orderByDesc('is_featured')
                ->latest('published_at')
                ->paginate(config('visitdasol.per_page')),
        ]);
    }

    public function show(Announcement $announcement): View
    {
        // A draft, or one scheduled for a later date, is not found — the
        // same answer the public pages give.
        abort_unless($announcement->isPublished(), 404);

        return view('owner.announcements.show', [
            'announcement' => $announcement,
            'more' => Announcement::published()
                ->whereKeyNot($announcement->id)
                ->latest('published_at')
                ->limit(3)
                ->get(),
        ]);
    }
}
