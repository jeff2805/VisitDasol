<?php

namespace Tests\Feature;

use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Guards for the Phase 18 polish pass, so the fixes cannot quietly regress.
 */
class UiConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One first-level heading per page, and no skipped levels.
     *
     * Somebody using a screen reader moves through a page by its headings.
     * Two h1s means the page has two titles and neither is the title; a jump
     * from h1 to h3 means a list of things appears with nothing saying what
     * the list is.
     *
     * Every sign-in and registration page had both faults: the decorative
     * tagline beside the form was an h1, and it is hidden below large
     * screens, so on a phone the only h1 was something nobody could see.
     */
    public function test_every_page_has_exactly_one_first_level_heading(): void
    {
        foreach ($this->representativePages() as $label => $html) {
            $this->assertSame(
                1,
                substr_count($html, '<h1'),
                "{$label} should have exactly one <h1>",
            );
        }
    }

    public function test_no_page_skips_a_heading_level(): void
    {
        foreach ($this->representativePages() as $label => $html) {
            preg_match_all('/<h([1-6])\b/', $html, $found);
            $levels = array_map('intval', $found[1]);

            for ($i = 1; $i < count($levels); $i++) {
                $this->assertLessThanOrEqual(
                    1,
                    $levels[$i] - $levels[$i - 1],
                    "{$label} jumps from h{$levels[$i - 1]} to h{$levels[$i]}",
                );
            }
        }
    }

    /**
     * One page, one name, wherever it is named.
     *
     * The tourist's reservation list was called "Booking history" in the main
     * navigation, "My reservations" in the account menu, "My reservations" as
     * its own heading and "All reservations" on the dashboard. Two of those
     * links sat in the same bar, so a guest had two differently named ways to
     * one page and no way to tell they were the same place.
     */
    public function test_the_reservation_list_has_one_name_everywhere(): void
    {
        $tourist = User::factory()->create();

        foreach ([route('home'), route('tourist.dashboard')] as $url) {
            $html = $this->actingAs($tourist)->get($url)->assertOk()->getContent();

            foreach (['Booking history', 'All reservations', 'Booking History'] as $otherName) {
                $this->assertStringNotContainsString(
                    $otherName,
                    $html,
                    "{$url} still calls the reservation list \"{$otherName}\". It is \"My reservations\".",
                );
            }
        }

        // And exactly one link to it in the navigation bar, not two.
        $nav = $this->actingAs($tourist)->get(route('home'))->assertOk()->getContent();
        $nav = substr($nav, (int) strpos($nav, '<nav'), (int) strpos($nav, '</nav>') - (int) strpos($nav, '<nav'));

        $this->assertSame(
            1,
            substr_count($nav, route('tourist.bookings.index')),
            'The navigation bar links to the reservation list more than once.',
        );
    }

    /**
     * An eye means the same thing everywhere in this project.
     *
     * bi-eye-slash sits beside "hidden from the public"; bi-eye beside
     * "this listing is live". The password toggle used to read the
     * other way round — an open eye while the password was still dots —
     * so the same icon meant opposite things on a resort page and a
     * sign-in form. That is what made it feel backwards.
     *
     * A password field starts concealed, so its button starts crossed
     * out. public/js/app.js swaps it to bi-eye when the characters go
     * on screen.
     */
    public function test_the_password_toggle_starts_crossed_out_like_everything_hidden(): void
    {
        $markup = file_get_contents(
            resource_path('views/components/form/input.blade.php')
        );

        $this->assertStringContainsString(
            '<i class="bi bi-eye-slash"',
            $markup,
            'A password field starts hidden, so its toggle must start with the '
            .'crossed-out eye this project uses for anything concealed.'
        );

        $this->assertStringNotContainsString(
            '<i class="bi bi-eye"',
            $markup,
            'An open eye on a field that is still showing dots reads as the '
            .'opposite of what it means everywhere else here.'
        );
    }

    /**
     * A toggle button keeps ONE name and reports its state in
     * aria-pressed.
     *
     * Changing the name to "Hide password" at the same moment
     * aria-pressed became true had a screen reader announce "Hide
     * password, pressed" — saying concealment was switched on while the
     * password sat in plain sight.
     */
    public function test_the_password_toggle_does_not_rename_itself_when_pressed(): void
    {
        $script = file_get_contents(public_path('js/app.js'));

        $this->assertStringNotContainsString(
            "'Hide password'",
            $script,
            'The toggle must not swap its accessible name; aria-pressed already '
            .'carries whether the password is showing.'
        );

        $this->assertStringContainsString(
            "toggle.setAttribute('aria-pressed'",
            $script,
            'The toggle still has to report its state somehow.'
        );
    }

    /**
     * A tab appearing out of nowhere is disorienting, and worse with a screen
     * reader: the back button no longer goes back and nothing said why.
     */
    public function test_every_link_that_opens_a_new_tab_says_so(): void
    {
        $finder = (new Finder)->files()->in(resource_path('views'))->name('*.blade.php');

        foreach ($finder as $file) {
            $this->assertStringNotContainsString(
                'target="_blank"',
                $file->getContents(),
                "{$file->getRelativePathname()} opens a new tab by hand. Use <x-external-link>, "
                .'which says so for people who cannot see it happen.',
            );
        }
    }

    /**
     * @return array<string, string>
     */
    /**
     * The rendered HTML of one page of each kind, keyed by a readable name.
     *
     * Signed-in pages are included as well as public ones. They were not
     * before, so the headings on the pages a guest actually spends time in —
     * their dashboard and their reservations — were never checked at all.
     *
     * @return array<string, string>
     */
    private function representativePages(): array
    {
        $resort = Resort::factory()->active()->create();
        $room = \App\Models\Room::factory()->for($resort)->create();
        $spot = \App\Models\Destination::factory()->create();

        $pages = [];

        foreach ([
            'the home page' => route('home'),
            'the room listing' => route('resorts.index'),
            'a resort page' => route('resorts.show', $resort),
            'the tourist spot listing' => route('destinations.index'),
            'a tourist spot page' => route('destinations.show', $spot),
            'the map' => route('map'),
            'the about page' => route('about'),
            'the privacy notice' => route('privacy'),
            'the sign-in page' => route('login'),
            'the tourist sign-up' => route('register'),
            'the owner application' => route('register.owner'),
        ] as $label => $url) {
            $pages[$label] = $this->get($url)->assertOk()->getContent();
        }

        $tourist = User::factory()->create();

        \App\Models\Booking::factory()->for($tourist, 'tourist')->for($resort)->for($room)
            ->forStay(today()->addDays(5)->toDateString(), today()->addDays(7)->toDateString())
            ->confirmed()
            ->create();

        foreach ([
            'the tourist dashboard' => route('tourist.dashboard'),
            'the tourist reservation list' => route('tourist.bookings.index'),
        ] as $label => $url) {
            $pages[$label] = $this->actingAs($tourist)->get($url)->assertOk()->getContent();
        }

        // The owner's own area, which uses the dashboard shell rather than the
        // public layout and so was never checked by either of these at all.
        $owner = User::factory()->resortOwner()->create();
        $ownedResort = Resort::factory()->for($owner, 'owner')->active()->create();
        $ownedRoom = \App\Models\Room::factory()->for($ownedResort)->create();

        \App\Models\Booking::factory()->for($tourist, 'tourist')->for($ownedResort)->for($ownedRoom)
            ->forStay(today()->addDays(3)->toDateString(), today()->addDays(5)->toDateString())
            ->confirmed()
            ->create();

        foreach ([
            'the owner dashboard' => route('owner.dashboard'),
            'the owner resort page' => route('owner.resort.show'),
            'the owner room list' => route('owner.rooms.index'),
            'the owner reservation list' => route('owner.bookings.index'),
            'the owner availability calendar' => route('owner.availability'),
            'the owner statistics' => route('owner.statistics'),
            'the owner review list' => route('owner.reviews.index'),
        ] as $label => $url) {
            $pages[$label] = $this->actingAs($owner)->get($url)->assertOk()->getContent();
        }

        return $pages;
    }

    /**
     * No file carries a character that a bad encoding round trip mangled.
     *
     * This is here because it happened: several views were edited with a tool
     * that read them in the system codepage and wrote them back as UTF-8, and
     * every em dash in those files became "Ã¢â‚¬â€". One of them was the
     * barangay name "Osmeña" in the seeder, which would have written a
     * barangay into the database that matches nothing in the config list.
     *
     * The damage is silent — the page still renders, just with rubbish in it —
     * so it needs a test rather than an eye.
     */
    public function test_no_file_has_characters_mangled_by_a_bad_encoding(): void
    {
        // What a mangled UTF-8 multibyte character leaves behind.
        $signatures = ['Ã¢â‚¬', 'Ã‚Â', 'â€”', 'â€“', 'â€™', 'Ã±', 'Ã©', 'Ãƒ'];

        $finder = (new Finder)->files()
            ->in([resource_path('views'), app_path(), database_path(), config_path()])
            ->name(['*.php', '*.blade.php']);

        foreach ($finder as $file) {
            $contents = $file->getContents();

            $this->assertTrue(
                mb_check_encoding($contents, 'UTF-8'),
                "{$file->getRelativePathname()} is not valid UTF-8",
            );

            foreach ($signatures as $mangled) {
                $this->assertStringNotContainsString(
                    $mangled,
                    $contents,
                    "{$file->getRelativePathname()} contains \"{$mangled}\", which is a character "
                    .'mangled by reading the file in the wrong encoding.',
                );
            }

            /*
             * Invisible C1 controls (U+0080–U+009F).
             *
             * These are what a half-finished repair leaves behind: a mangled
             * em dash is four characters, and fixing three of them leaves the
             * fourth sitting there as a control byte. It renders as nothing
             * and reads as nothing, so the only way to find it is to look —
             * which is what this does. Nothing legitimate puts one in source.
             */
            $this->assertSame(
                0,
                preg_match_all('/\xC2[\x80-\x9F]/', $contents),
                "{$file->getRelativePathname()} contains an invisible control character.",
            );
        }
    }

    /**
     * Every sideways scroll can be reached by a keyboard.
     *
     * A wide table on a phone has to scroll rather than shrink its text to
     * nothing. A plain overflow box scrolls with a finger and with nothing
     * else: the columns past the right edge are unreachable from a keyboard,
     * and a screen reader is told nothing about the box at all. Nine of the
     * eleven on this site were like that.
     *
     * <x-scroll-region> is the box that is reachable. This stops the next
     * table being wrapped in a bare div out of habit.
     */
    public function test_every_sideways_scroll_can_be_reached_by_a_keyboard(): void
    {
        $finder = (new Finder)->files()->in(resource_path('views'))->name('*.blade.php');

        $bare = [];
        $reachable = 0;

        foreach ($finder as $file) {
            $contents = $file->getContents();

            preg_match_all('/<div class="table-responsive[^"]*"([^>]*)>/', $contents, $found);

            foreach ($found[1] as $attributes) {
                if (! str_contains($attributes, 'tabindex')) {
                    $bare[] = $file->getRelativePathname();
                }
            }

            $reachable += substr_count($contents, '<x-scroll-region');
        }

        $this->assertSame(
            [],
            $bare,
            "These scroll sideways in a plain div, which a keyboard cannot reach:\n".implode("\n", $bare)
            ."\nUse <x-scroll-region label=\"…\"> instead."
        );

        // Asserted as well as the absence above, or this test would pass by
        // finding nothing at all — including on the day somebody deletes the
        // component and every table with it.
        $this->assertGreaterThanOrEqual(
            10,
            $reachable,
            'The scrollable tables have gone missing, so this guard is no longer guarding anything.'
        );
    }

    public function test_no_page_still_talks_about_unfinished_features(): void
    {
        $stale = ['Available soon', 'opens soon', 'still to come', 'arrive in later phases', 'Available in Phase'];

        $finder = (new Finder)->files()->in(resource_path('views'))->name('*.blade.php')->notPath('dev');

        foreach ($finder as $file) {
            foreach ($stale as $phrase) {
                $this->assertStringNotContainsString(
                    $phrase,
                    $file->getContents(),
                    "{$file->getRelativePathname()} still says \"{$phrase}\"."
                );
            }
        }
    }

    public function test_every_navigation_link_now_works(): void
    {
        // Links render disabled only while their route is missing; none should be.
        $this->get(route('home'))->assertOk()->assertDontSee('title="Available in a later phase"', false);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('vd-sidebar__soon', false);

        $owner = User::factory()->resortOwner()->create();
        Resort::factory()->for($owner, 'owner')->create();

        $this->actingAs($owner)
            ->get(route('owner.dashboard'))
            ->assertOk()
            ->assertDontSee('vd-sidebar__soon', false);
    }

    public function test_every_layout_offers_a_skip_link_to_the_main_content(): void
    {
        $pages = [
            [null, route('home')],
            [null, route('login')],
            [User::factory()->superAdmin()->create(), route('admin.dashboard')],
            [User::factory()->create(), route('tourist.dashboard')],
        ];

        foreach ($pages as [$user, $url]) {
            $response = $user ? $this->actingAs($user)->get($url) : $this->get($url);

            $response->assertOk()
                ->assertSee('href="#main-content"', false)
                ->assertSee('id="main-content"', false);

            if ($user) {
                $this->post(route('logout'));
            }
        }
    }

    public function test_the_page_title_names_the_site(): void
    {
        $this->get(route('resorts.index'))->assertOk()->assertSee('<title>Rooms | Visit Dasol</title>', false);
    }
}
