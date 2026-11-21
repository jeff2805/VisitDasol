<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Static information pages.
 */
class PageController extends Controller
{
    /**
     * What the site says about Dasol.
     *
     * The three prose sections are the tourism office's to write, from
     * Settings -> About page. Everything else on the page — how the site
     * works, why listings are reviewed — describes the software and stays
     * in the template, because an office that could edit those could
     * publish a promise the system does not keep.
     */
    public function about(\App\Services\AboutPageService $about): View
    {
        $content = $about->published();

        return view('site.about', [
            'about' => $content,

            // The real destination records, in the office's order. Read
            // here rather than in the template so the page cannot quietly
            // start querying from inside a loop.
            'destinations' => $about->destinationsFor(
                (array) ($content['destinations']['destination_ids'] ?? [])
            ),

            // The preview passes true; the public page never does.
            'isPreview' => false,
        ]);
    }

    /**
     * What the site collects and who sees it.
     *
     * Required rather than optional: the Data Privacy Act of 2012 (RA 10173)
     * expects anyone collecting personal information to say what they hold and
     * why, and this site holds names, addresses, phone numbers, and travel
     * dates belonging to real visitors.
     */
    public function privacy(): View
    {
        return view('site.privacy');
    }

    public function terms(): View
    {
        return view('site.terms');
    }
}
