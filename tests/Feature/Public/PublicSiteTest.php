<?php

namespace Tests\Feature\Public;

use App\Enums\ResortStatus;
use App\Enums\RoomType;
use App\Enums\UserStatus;
use App\Models\Amenity;
use App\Models\Announcement;
use App\Models\Booking;
use App\Models\Destination;
use App\Models\DestinationCategory;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    private function publicResort(array $attributes = []): Resort
    {
        return Resort::factory()->active()->create($attributes);
    }

    /* Visibility -------------------------------------------------------- */

    public static function hiddenResorts(): array
    {
        return [
            'pending resort' => [UserStatus::Active, ResortStatus::Pending],
            'rejected resort' => [UserStatus::Active, ResortStatus::Rejected],
            'inactive resort' => [UserStatus::Active, ResortStatus::Inactive],
            'deactivated owner' => [UserStatus::Inactive, ResortStatus::Active],
            'pending owner' => [UserStatus::Pending, ResortStatus::Active],
        ];
    }

    #[DataProvider('hiddenResorts')]
    public function test_only_approved_resorts_are_public(UserStatus $ownerStatus, ResortStatus $resortStatus): void
    {
        $owner = User::factory()->resortOwner($ownerStatus)->create();
        $hidden = Resort::factory()->for($owner, 'owner')->withStatus($resortStatus)->create(['name' => 'Hidden Cove Resort']);

        // The listing is of rooms now, so the room is what must not leak: a
        // hidden resort with an active room type would otherwise appear there
        // under its own name while the resort page itself stayed shut.
        Room::factory()->for($hidden)->create(['name' => 'Hidden Seaview Room']);

        $this->get(route('resorts.index'))
            ->assertOk()
            ->assertDontSee('Hidden Cove Resort')
            ->assertDontSee('Hidden Seaview Room');

        $this->get(route('home'))->assertOk()->assertDontSee('Hidden Cove Resort');

        // Guessing the address does not help either.
        $this->get(route('resorts.show', $hidden))->assertNotFound();
    }

    /**
     * Fictional listings exist so an empty system can be walked through. Once
     * a town has real resorts, a visitor reading "Places to stay" has to be
     * reading real places, so demo listings leave the public site — without
     * being deleted, because the walkthrough still has to be possible.
     */
    public function test_demo_listings_are_kept_off_the_public_site(): void
    {
        $real = $this->publicResort(['name' => 'Salt Breeze Beach Resort']);
        $demo = $this->publicResort(['name' => 'Fictional Cove Resort (Demo)']);
        $demo->forceFill(['is_demo' => true])->save();

        // The listing shows rooms, so each resort needs one to appear at all.
        Room::factory()->for($real)->create(['name' => 'Real Seaview Room']);
        Room::factory()->for($demo)->create(['name' => 'Fictional Seaview Room']);

        $this->get(route('home'))->assertOk()->assertSee($real->name)->assertDontSee($demo->name);

        $this->get(route('resorts.index'))
            ->assertOk()
            ->assertSee($real->name)
            ->assertSee('Real Seaview Room')
            ->assertDontSee($demo->name)
            ->assertDontSee('Fictional Seaview Room');

        // Guessing the address must not get round it either.
        $this->get(route('resorts.show', $demo))->assertNotFound();
    }

    public function test_demo_listings_come_back_when_the_office_asks_for_them(): void
    {
        config(['visitdasol.demo.listings_public' => true]);

        $demo = $this->publicResort(['name' => 'Fictional Cove Resort (Demo)']);
        $demo->forceFill(['is_demo' => true])->save();
        Room::factory()->for($demo)->create(['name' => 'Fictional Seaview Room']);

        $this->get(route('resorts.index'))
            ->assertOk()
            ->assertSee($demo->name)
            ->assertSee('Fictional Seaview Room');

        $this->get(route('resorts.show', $demo))->assertOk();
    }

    /**
     * The home strip is the shop window. A listing whose owner has uploaded
     * nothing shows a grey "No photo yet" box, and that must not be the first
     * thing a visitor sees when a photographed listing exists.
     */
    public function test_the_home_page_leads_with_listings_that_have_a_photo(): void
    {
        $this->publicResort(['name' => 'Aaa Resort Without Photos', 'main_image' => null]);
        $this->publicResort(['name' => 'Zzz Resort With Photos', 'main_image' => 'resorts/1/cover/photo.jpg']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeInOrder(['Zzz Resort With Photos', 'Aaa Resort Without Photos']);
    }

    public function test_an_approved_resort_is_listed_and_readable(): void
    {
        $resort = $this->publicResort(['name' => 'Salt Breeze Beach Resort']);
        Room::factory()->for($resort)->create(['name' => 'Seaview Deluxe', 'price_per_night' => 2500, 'capacity' => 2]);
        Room::factory()->for($resort)->inactive()->create(['name' => 'Closed Loft']);

        $this->get(route('resorts.index'))->assertOk()->assertSee('Salt Breeze Beach Resort')->assertSee('₱2,500');

        $this->get(route('resorts.show', $resort))
            ->assertOk()
            ->assertSee('Salt Breeze Beach Resort')
            ->assertSee('Seaview Deluxe')
            ->assertSee('₱2,500')
            ->assertDontSee('Closed Loft');
    }

    /**
     * The listing is of rooms, not resorts.
     *
     * A guest is choosing a bed at a price for a number of people, and before
     * this they could not see any of that until they had clicked into a
     * resort. Two resorts looked identical on the listing even when one
     * started at ₱600 and the other at ₱6,500.
     */
    public function test_the_listing_shows_each_room_with_the_resort_it_belongs_to(): void
    {
        $resort = $this->publicResort(['name' => 'Salt Breeze Beach Resort', 'barangay' => 'Tambobong']);
        Room::factory()->for($resort)->create(['name' => 'Seaview Deluxe', 'price_per_night' => 2500, 'capacity' => 2]);
        Room::factory()->for($resort)->create(['name' => 'Budget Fan Room', 'price_per_night' => 600, 'capacity' => 2]);
        Room::factory()->for($resort)->inactive()->create(['name' => 'Closed Loft']);

        $this->get(route('resorts.index'))
            ->assertOk()
            // Every bookable room appears in its own right, with its own rate.
            ->assertSee('Seaview Deluxe')
            ->assertSee('₱2,500')
            ->assertSee('Budget Fan Room')
            ->assertSee('₱600')
            // Each one says where it is, or the room name means nothing.
            ->assertSee('Salt Breeze Beach Resort')
            ->assertSee('Tambobong')
            // A room the resort has switched off is not for sale.
            ->assertDontSee('Closed Loft')
            ->assertSee('2 rooms at 1 resort');
    }

    /**
     * The filters now bite on the room rather than the resort. Before this, a
     * ₱2,000 ceiling kept a resort because something cheap existed somewhere
     * inside it, and then showed the guest the expensive rooms too.
     */
    public function test_a_price_ceiling_hides_the_rooms_above_it_not_just_the_resort(): void
    {
        $resort = $this->publicResort(['name' => 'Salt Breeze Beach Resort']);
        Room::factory()->for($resort)->create(['name' => 'Budget Fan Room', 'price_per_night' => 600, 'capacity' => 2]);
        Room::factory()->for($resort)->create(['name' => 'Seaview Villa', 'price_per_night' => 6500, 'capacity' => 8]);

        $this->get(route('resorts.index', ['max_price' => 2000]))
            ->assertOk()
            ->assertSee('Budget Fan Room')
            ->assertDontSee('Seaview Villa');

        // Same for the party size: a room that cannot take them is not shown.
        $this->get(route('resorts.index', ['guests' => 6]))
            ->assertOk()
            ->assertSee('Seaview Villa')
            ->assertDontSee('Budget Fan Room');
    }

    /**
     * Budget and premium are categories in their own right.
     *
     * Before they existed, a resort owner with a ₱600 room and a ₱1,500 room
     * had nothing in the list that described either, so they put the words in
     * the room's NAME and filed the budget room under "cottage". A category
     * nobody can pick correctly is worse than no category.
     */
    public function test_a_room_can_be_filed_as_budget_or_premium(): void
    {
        $resort = $this->publicResort(['name' => 'Salt Breeze Beach Resort']);

        Room::factory()->for($resort)->create([
            'name' => 'Fan Room', 'room_type' => RoomType::Budget, 'price_per_night' => 600,
        ]);
        Room::factory()->for($resort)->create([
            'name' => 'Seaview Suite', 'room_type' => RoomType::Premium, 'price_per_night' => 6000,
        ]);

        $this->get(route('resorts.index'))
            ->assertOk()
            ->assertSee('Budget')
            ->assertSee('Premium');
    }

    /** The categories have to do something, or they are just a label. */
    public function test_rooms_can_be_filtered_by_category(): void
    {
        $resort = $this->publicResort();

        // Deliberately no premium room here, so one real category is empty.
        Room::factory()->for($resort)->create(['name' => 'Fan Room', 'room_type' => RoomType::Budget]);
        Room::factory()->for($resort)->create(['name' => 'Nipa Hut', 'room_type' => RoomType::Cottage]);

        $this->get(route('resorts.index', ['type' => 'budget']))
            ->assertOk()->assertSee('Fan Room')->assertDontSee('Nipa Hut');

        $this->get(route('resorts.index', ['type' => 'cottage']))
            ->assertOk()->assertSee('Nipa Hut')->assertDontSee('Fan Room');

        // A real category nobody offers is not an error, just an empty answer.
        $this->get(route('resorts.index', ['type' => 'premium']))
            ->assertOk()->assertSee('No rooms match your filters');

        // A category that does not exist is ignored rather than breaking the
        // page — including one this list used to have.
        foreach (['penthouse', 'deluxe'] as $notACategory) {
            $this->get(route('resorts.index', ['type' => $notACategory]))
                ->assertOk()->assertSee('Fan Room')->assertSee('Nipa Hut');
        }
    }

    /**
     * All three public cards — room, resort, tourist spot — offer the same
     * visible way in.
     *
     * The spot card used to end at its description: the only way through was
     * the title or the photo, which is invisible to anyone scanning for a
     * button and gave a row of mixed cards no common baseline.
     */
    public function test_every_public_card_offers_a_visible_way_in(): void
    {
        $resort = $this->publicResort(['name' => 'Salt Breeze Beach Resort']);
        Room::factory()->for($resort)->create(['name' => 'Seaview Deluxe']);
        $spot = Destination::factory()->create(['name' => 'White Sand Cove']);

        foreach ([
            route('resorts.index') => route('resorts.show', $resort),
            route('destinations.index') => route('destinations.show', $spot),
        ] as $listing => $target) {
            $html = $this->get($listing)->assertOk()->getContent();

            $this->assertStringContainsString('vd-card__footer', $html, "{$listing} has a card with no footer.");
            $this->assertStringContainsString(
                '<a class="btn btn-sm btn-outline-primary" href="'.$target.'">View details</a>',
                $html,
                "{$listing} offers no button through to the thing it lists."
            );
        }
    }

    /* The stay search ---------------------------------------------------- */

    /**
     * The home page asks the question the visitor arrives with.
     *
     * It used to offer "Browse resorts", which is a different thing: it asks
     * them to go and look rather than to say what they want. The search posts
     * straight to the room listing, which already understands every field.
     */
    public function test_the_home_page_asks_where_and_when(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('action="'.route('resorts.index').'"', false)
            ->assertSee('name="check_in"', false)
            ->assertSee('name="check_out"', false)
            ->assertSee('name="guests"', false)
            // A Dasol barangay, so the "where" is answerable from the hero.
            ->assertSee('Brgy. Tambobong');
    }

    /**
     * Dates annotate the listing; they never silently remove a room.
     *
     * A visitor who picked nights is told which rooms are free for them and
     * which are full, because a listing that quietly drops what somebody
     * liked is worse than one that explains.
     */
    public function test_dates_say_what_is_free_without_hiding_what_is_full(): void
    {
        $resort = $this->publicResort(['name' => 'Salt Breeze Beach Resort']);

        $free = Room::factory()->for($resort)->create([
            'name' => 'Open Seaview Room', 'total_rooms' => 3,
        ]);
        $full = Room::factory()->for($resort)->create([
            'name' => 'Taken Garden Room', 'total_rooms' => 1,
        ]);

        Booking::factory()->for($full)->forStay('2030-04-10', '2030-04-13')->confirmed()->create();
        Booking::factory()->for($free)->forStay('2030-04-10', '2030-04-13')->confirmed()->create();

        $response = $this->get(route('resorts.index', [
            'check_in' => '2030-04-10', 'check_out' => '2030-04-12',
        ]));

        $response->assertOk()
            ->assertSee('Apr 10 to Apr 12, 2030')
            ->assertSee('2 nights')
            // The full room is still listed, and says so.
            ->assertSee('Taken Garden Room')
            ->assertSee('Fully booked for your dates')
            // 3 units, 1 held, so 2 remain.
            ->assertSee('Open Seaview Room')
            ->assertSee('2 rooms free for your dates');
    }

    /**
     * Without dates, nothing is claimed either way. "We did not ask" and
     * "none left" must never look the same.
     */
    public function test_no_dates_means_no_availability_claim(): void
    {
        $resort = $this->publicResort();
        Room::factory()->for($resort)->create(['name' => 'Open Seaview Room', 'total_rooms' => 2]);

        $this->get(route('resorts.index'))
            ->assertOk()
            ->assertSee('Open Seaview Room')
            ->assertDontSee('free for your dates')
            ->assertDontSee('Fully booked for your dates');
    }

    /**
     * A date range that cannot be honoured is explained in words, and the
     * rooms stay on the page.
     *
     * @return void
     */
    #[DataProvider('impossibleStays')]
    public function test_an_impossible_date_range_is_explained(array $query, string $message): void
    {
        $resort = $this->publicResort();
        Room::factory()->for($resort)->create(['name' => 'Open Seaview Room']);

        $this->travelTo('2030-04-01 09:00:00');

        $this->get(route('resorts.index', $query))
            ->assertOk()
            ->assertSee($message)
            ->assertSee('Open Seaview Room')
            ->assertDontSee('free for your dates');
    }

    public static function impossibleStays(): array
    {
        return [
            'check-out before check-in' => [
                ['check_in' => '2030-04-12', 'check_out' => '2030-04-10'],
                'The check-out date must be after the check-in date.',
            ],
            'check-in in the past' => [
                ['check_in' => '2030-03-01', 'check_out' => '2030-03-04'],
                'Choose a check-in date from today onwards.',
            ],
            'same day' => [
                ['check_in' => '2030-04-10', 'check_out' => '2030-04-10'],
                'The check-out date must be after the check-in date.',
            ],
        ];
    }

    /** A hand-edited date must not break the page or reach the database. */
    public function test_nonsense_dates_are_ignored(): void
    {
        $resort = $this->publicResort();
        Room::factory()->for($resort)->create(['name' => 'Open Seaview Room']);

        foreach (['not-a-date', '2030-13-45', '0000-00-00', '<script>'] as $value) {
            $this->get(route('resorts.index', ['check_in' => $value, 'check_out' => $value]))
                ->assertOk()
                ->assertSee('Open Seaview Room')
                ->assertDontSee('free for your dates');
        }
    }

    /**
     * The amenity list is folded away to keep the filter bar short, which
     * means it can hide a filter that is doing something. So it opens itself
     * whenever any amenity is in use, and says how many: a filter nobody can
     * see is a filter nobody can undo, and the visitor is left wondering why
     * half the rooms are missing.
     */
    public function test_the_amenity_panel_opens_itself_when_it_is_filtering(): void
    {
        $wifi = Amenity::factory()->create(['name' => 'Wi-Fi']);
        $pool = Amenity::factory()->create(['name' => 'Swimming pool']);

        $resort = $this->publicResort();
        $resort->amenities()->attach([$wifi->id, $pool->id]);
        Room::factory()->for($resort)->create();

        // Nothing chosen: folded away, and no count to report.
        $this->get(route('resorts.index'))
            ->assertOk()
            ->assertSee('<details class="vd-filterbar__more">', false)
            ->assertDontSee('chosen');

        // Two chosen: open, and it says so.
        $this->get(route('resorts.index', ['amenities' => [$wifi->id, $pool->id]]))
            ->assertOk()
            ->assertSee('<details class="vd-filterbar__more" open>', false)
            ->assertSee('2 chosen');
    }

    public function test_guests_do_not_need_an_account_to_browse(): void
    {
        $this->publicResort();

        foreach (['home', 'resorts.index', 'destinations.index', 'announcements.index', 'about'] as $page) {
            $this->get(route($page))->assertOk();
        }

        $this->assertGuest();
    }

    /* Filters ------------------------------------------------------------ */

    public function test_resorts_can_be_filtered(): void
    {
        $wifi = Amenity::factory()->create(['name' => 'Wi-Fi']);
        $pool = Amenity::factory()->create(['name' => 'Swimming pool']);

        $beachside = $this->publicResort(['name' => 'Beachside Resort', 'barangay' => 'Tambobong']);
        $beachside->amenities()->attach([$wifi->id, $pool->id]);
        Room::factory()->for($beachside)->create(['price_per_night' => 1500, 'capacity' => 2]);

        $hilltop = $this->publicResort(['name' => 'Hilltop Resort', 'barangay' => 'Uli']);
        $hilltop->amenities()->attach($wifi);
        Room::factory()->for($hilltop)->create(['price_per_night' => 6000, 'capacity' => 8]);

        $this->get(route('resorts.index', ['barangay' => 'Tambobong']))
            ->assertSee('Beachside Resort')->assertDontSee('Hilltop Resort');

        $this->get(route('resorts.index', ['max_price' => 2000]))
            ->assertSee('Beachside Resort')->assertDontSee('Hilltop Resort');

        $this->get(route('resorts.index', ['guests' => 6]))
            ->assertSee('Hilltop Resort')->assertDontSee('Beachside Resort');

        $this->get(route('resorts.index', ['q' => 'hilltop']))
            ->assertSee('Hilltop Resort')->assertDontSee('Beachside Resort');

        // Every chosen amenity must be present.
        $this->get(route('resorts.index', ['amenities' => [$wifi->id, $pool->id]]))
            ->assertSee('Beachside Resort')->assertDontSee('Hilltop Resort');
    }

    public function test_nonsense_filter_values_are_ignored_rather_than_breaking_the_page(): void
    {
        $resort = $this->publicResort(['name' => 'Salt Breeze Resort']);
        Room::factory()->for($resort)->create(['name' => 'Seaview Room']);

        $this->get(route('resorts.index', [
            'barangay' => "Nowhere'); DROP TABLE users;--",
            'guests' => 'lots',
            'max_price' => '-5',
            'sort' => 'cheapest',
            'amenities' => ['not-a-number'],
        ]))->assertOk()->assertSee('Salt Breeze Resort');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_sorting_by_price(): void
    {
        $cheap = $this->publicResort(['name' => 'Budget Resort']);
        Room::factory()->for($cheap)->create(['price_per_night' => 900]);
        $pricey = $this->publicResort(['name' => 'Premium Resort']);
        Room::factory()->for($pricey)->create(['price_per_night' => 9000]);

        $this->get(route('resorts.index', ['sort' => 'price_low']))
            ->assertSeeInOrder(['Budget Resort', 'Premium Resort']);

        $this->get(route('resorts.index', ['sort' => 'price_high']))
            ->assertSeeInOrder(['Premium Resort', 'Budget Resort']);
    }

    /* Destinations ------------------------------------------------------- */

    public function test_only_published_destinations_are_public(): void
    {
        $beach = DestinationCategory::factory()->create(['name' => 'Beach']);
        Destination::factory()->for($beach, 'category')->create(['name' => 'White Sand Cove']);
        $hidden = Destination::factory()->inactive()->create(['name' => 'Closed Falls']);

        $this->get(route('destinations.index'))
            ->assertOk()->assertSee('White Sand Cove')->assertDontSee('Closed Falls');

        $this->get(route('destinations.show', $hidden))->assertNotFound();
    }

    public function test_destinations_can_be_filtered_by_category_and_search(): void
    {
        $beach = DestinationCategory::factory()->create(['name' => 'Beach']);
        $view = DestinationCategory::factory()->create(['name' => 'Viewpoint']);
        Destination::factory()->for($beach, 'category')->create(['name' => 'White Sand Cove']);
        Destination::factory()->for($view, 'category')->create(['name' => 'Hilltop Lookout']);

        $this->get(route('destinations.index', ['category' => $beach->slug]))
            ->assertSee('White Sand Cove')->assertDontSee('Hilltop Lookout');

        $this->get(route('destinations.index', ['q' => 'lookout']))
            ->assertSee('Hilltop Lookout')->assertDontSee('White Sand Cove');
    }

    public function test_a_destination_page_shows_its_details(): void
    {
        $category = DestinationCategory::factory()->create(['name' => 'Beach']);
        $destination = Destination::factory()->for($category, 'category')->create([
            'name' => 'White Sand Cove',
            'barangay' => 'Tambobong',
            'description' => 'A quiet stretch of shoreline.',
        ]);

        $this->get(route('destinations.show', $destination))
            ->assertOk()
            ->assertSee('White Sand Cove')
            ->assertSee('Tambobong')
            ->assertSee('A quiet stretch of shoreline.')
            ->assertSee('Beach');
    }

    /* Announcements ------------------------------------------------------ */

    public function test_only_published_announcements_are_public(): void
    {
        $live = Announcement::factory()->published()->create(['title' => 'Holiday office hours']);
        $draft = Announcement::factory()->create(['title' => 'Unfinished draft']);
        $scheduled = Announcement::factory()->published(now()->addWeek())->create(['title' => 'Next month update']);

        $this->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('Holiday office hours')
            ->assertDontSee('Unfinished draft')
            ->assertDontSee('Next month update');

        $this->get(route('announcements.show', $live))->assertOk()->assertSee('Holiday office hours');
        $this->get(route('announcements.show', $draft))->assertNotFound();
        $this->get(route('announcements.show', $scheduled))->assertNotFound();
    }

    /* Home and About ------------------------------------------------------ */

    public function test_the_home_page_shows_approved_content_and_prefers_featured_resorts(): void
    {
        $featured = $this->publicResort(['name' => 'Featured Resort', 'is_featured' => true]);
        Room::factory()->for($featured)->create(['price_per_night' => 3000]);
        $this->publicResort(['name' => 'Ordinary Resort']);
        Destination::factory()->create(['name' => 'White Sand Cove']);
        Announcement::factory()->published()->create(['title' => 'Holiday office hours']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeInOrder(['Featured Resort', 'Ordinary Resort'])
            ->assertSee('White Sand Cove')
            ->assertSee('Holiday office hours');
    }

    public function test_empty_states_are_shown_on_a_fresh_install(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('No resorts listed yet');
        $this->get(route('resorts.index'))->assertOk()->assertSee('No rooms match your filters');
        $this->get(route('destinations.index'))->assertOk()->assertSee('No destinations to show');
        $this->get(route('announcements.index'))->assertOk()->assertSee('No announcements yet');
    }

    /**
     * The site collects names, numbers, addresses and travel dates, so the
     * Data Privacy Act expects it to say what it holds and why. These pages
     * are reachable without an account, because someone deciding whether to
     * register has to be able to read them first.
     */
    public function test_the_privacy_and_terms_pages_are_public(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('Privacy notice')
            ->assertSee('Republic Act 10173')
            ->assertSee('National Privacy Commission');

        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('Terms of use')
            ->assertSee('is a request, not a reservation');

        $this->assertGuest();
    }

    public function test_registration_links_to_what_the_applicant_is_agreeing_to(): void
    {
        foreach ([route('register'), route('register.owner')] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee(route('terms'), false)
                ->assertSee(route('privacy'), false);
        }
    }

    public function test_the_about_page_renders(): void
    {
        $this->get(route('about'))->assertOk()->assertSee('About Visit Dasol')->assertSee('18 barangays');
    }

    /* Safety -------------------------------------------------------------- */

    public function test_owner_supplied_text_is_escaped(): void
    {
        $resort = $this->publicResort([
            'name' => 'Sneaky <script>alert(1)</script> Resort',
            'description' => 'Contains <img src=x onerror=alert(2)> markup.',
        ]);

        $response = $this->get(route('resorts.show', $resort));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('<img src=x onerror=alert(2)>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_private_management_details_never_appear_on_public_pages(): void
    {
        $owner = User::factory()->resortOwner()->create(['email' => 'owner.private@example.test']);
        $resort = Resort::factory()->for($owner, 'owner')->active()->create([
            'review_remarks' => 'Internal note: check the permit again.',
        ]);

        $this->get(route('resorts.show', $resort))
            ->assertOk()
            ->assertDontSee('owner.private@example.test')
            ->assertDontSee('Internal note');
    }
}
