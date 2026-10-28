<?php

namespace Tests\Feature\Owner;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sidebar stays where the owner left it.
 *
 * WHAT THIS CAN AND CANNOT PROVE. There is no browser in this suite —
 * no Dusk, no Playwright — so nothing here scrolls anything or reads a
 * scrollTop back. What it does prove is the contract the browser code
 * depends on, which is the part that breaks silently:
 *
 *   - every owner page that the sidebar links to renders the one
 *     element that actually scrolls, .vd-sidebar__nav;
 *   - that element's class is spelled the same way in the stylesheet
 *     and in app.js, so a rename cannot quietly orphan the script;
 *   - app.js is on the page, and still contains the save and the
 *     restore.
 *
 * Someone renaming .vd-sidebar__nav, dropping the script from the
 * layout, or deleting half the handler would otherwise find out from a
 * user rather than from here. The scrolling itself has to be checked by
 * hand in a browser.
 */
class SidebarScrollTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->resortOwner()->create();
        $resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
        Room::factory()->for($resort)->create(['total_rooms' => 2, 'capacity' => 4]);
    }

    /** The pages named in the report, plus the rest of the owner's menu. */
    public static function sidebarPages(): array
    {
        return [
            'Availability' => ['owner.availability'],
            'Reservations' => ['owner.bookings.index'],
            'Statistics' => ['owner.statistics'],
            'Payment terms' => ['owner.payment-policy.edit'],
            'Dashboard' => ['owner.dashboard'],
            'Resort profile' => ['owner.resort.show'],
            'Rooms' => ['owner.rooms.index'],
            'Extras' => ['owner.extras.index'],
            'Guest reviews' => ['owner.reviews.index'],
            'GCash Payment Settings' => ['owner.manual-gcash.edit'],
            'Profile' => ['profile.edit'],
        ];
    }

    #[DataProvider('sidebarPages')]
    public function test_each_sidebar_page_carries_the_scrolling_menu_and_the_script(string $route): void
    {
        $response = $this->actingAs($this->owner)->get(route($route));

        $response->assertOk();

        // The element app.js saves and restores the scroll position of.
        $response->assertSee('class="vd-sidebar__nav"', false);

        // And the script that does it, which the layout must still load.
        $response->assertSee('js/app.js', false);
    }

    /**
     * The one class name has to agree in three places. It is a string in
     * the script and a selector in the stylesheet, so nothing but a test
     * connects them.
     */
    public function test_the_scrolling_menu_is_spelled_the_same_in_the_css_and_the_script(): void
    {
        $css = file_get_contents(public_path('css/visitdasol.css'));
        $script = file_get_contents(public_path('js/app.js'));

        // The stylesheet is what makes it a scroll container at all.
        $this->assertStringContainsString('.vd-sidebar__nav {', $css);
        $this->assertMatchesRegularExpression(
            '/\.vd-sidebar__nav\s*\{[^}]*overflow-y:\s*auto/s',
            $css,
            'The sidebar menu must still be its own scroll container, '
            .'separate from the page content.'
        );

        $this->assertStringContainsString(".querySelector('.vd-sidebar__nav')", $script);
    }

    /**
     * Both halves have to be there. A restore with nothing saving is a
     * menu that always opens at the top; a save with nothing restoring
     * is the same, only with wasted writes.
     */
    public function test_the_script_both_saves_and_restores_the_position(): void
    {
        $script = file_get_contents(public_path('js/app.js'));

        $this->assertStringContainsString('sessionStorage.setItem(SCROLL', $script);
        $this->assertStringContainsString('sessionStorage.getItem(SCROLL', $script);
        $this->assertStringContainsString('nav.scrollTop = top', $script);

        /*
         * Per tab and per sitting. Where somebody had scrolled to an
         * hour ago in another window is not a preference, and keeping it
         * in localStorage beside the deliberate "tucked" setting would
         * make it one.
         */
        $this->assertStringNotContainsString('localStorage.setItem(SCROLL', $script);

        // One listener, not one per call. The guard is what makes
        // watchSidebarScroll() safe to reach twice.
        $this->assertStringContainsString('nav.dataset.scrollWatched', $script);

        // Storage can be refused in private browsing; both accesses are
        // wrapped so the menu still works when it is.
        $this->assertSame(
            2,
            preg_match_all('/sessionStorage\.(get|set)Item\(SCROLL/', $script),
            'Both storage accesses must be present.'
        );
    }

    /**
     * The page's own scroll is the browser's business.
     *
     * Forcing the main content back to where it was would fight Back,
     * fight anchor links, and land somebody half-way down a page they
     * have not read. Only the menu is restored.
     */
    public function test_the_page_content_scroll_is_left_alone(): void
    {
        $script = file_get_contents(public_path('js/app.js'));

        $this->assertStringNotContainsString('.vd-content', $script);
        $this->assertStringNotContainsString('window.scrollTo', $script);
        $this->assertStringNotContainsString('scrollRestoration', $script);
    }
}
