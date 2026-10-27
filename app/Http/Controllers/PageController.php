<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Static information pages.
 */
class PageController extends Controller
{
    public function about(): View
    {
        return view('site.about');
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
