<?php

namespace Tests\Feature\Booking;

use App\Enums\PaymentDeadlineReference;
use App\Enums\PaymentRequirement;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Resort;
use App\Models\ResortPaymentPolicy;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The live payment terms on the booking form.
 *
 * PaymentPolicyTest already covers the arithmetic, the last-minute
 * rule, the snapshot and the owner's settings screen. This covers the
 * part that stands between them and the guest: the endpoint the form
 * asks as the booking changes, and the wiring that asks it.
 *
 * TWO THINGS MATTER MOST HERE.
 *
 * The figures must come from the resort's own saved policy, not from
 * anything the browser said. And the preview must be unable to move
 * what is actually charged — it is a display, and BookingService
 * prices the stay again on submission regardless of what was previewed.
 */
class PaymentTermsPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $tourist;

    private Resort $resort;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-06-01 09:00:00');

        $this->owner = User::factory()->resortOwner()->create();
        $this->tourist = User::factory()->create();

        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();

        // ₱1,000 a night, so the arithmetic below reads at a glance.
        $this->room = Room::factory()->for($this->resort)->create([
            'capacity' => 6,
            'total_rooms' => 4,
            'price_per_night' => 1000,
        ]);
    }

    private function policy(array $attributes = [], ?Resort $resort = null): ResortPaymentPolicy
    {
        $resort ??= $this->resort;

        $policy = $resort->paymentPolicy()->firstOrNew();

        $policy->fill($attributes + [
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
            'deadline_hours' => 24,
            'deadline_reference' => PaymentDeadlineReference::Confirmation,
            'last_minute_rule' => 'full',
            'last_minute_hours' => 48,
            'allow_on_site' => true,
        ]);

        $policy->resort()->associate($resort);
        $policy->version = (int) ($policy->version ?? 0) + 1;
        $policy->save();

        return $policy->refresh();
    }

    private function preview(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->tourist)
            ->postJson(route('tourist.bookings.payment-terms-preview'), array_merge([
                'room' => $this->room->id,
                'check_in_date' => '2030-07-10',
                'total' => '5000.00',
            ], $overrides));
    }

    /* The form can be opened at all ----------------------------------------- */

    /**
     * THE REGRESSION THIS FILE WAS STARTED FOR.
     *
     * The form asked for route('bookings.payment-terms-preview'). The
     * route is declared inside a group with ->name('tourist.'), so that
     * name does not exist, route() threw, and the whole booking page
     * was a 500. Every guest without an existing reservation — which is
     * every guest who could actually book — met a blank error screen.
     */
    public function test_the_booking_form_opens_and_carries_the_preview_address(): void
    {
        $this->policy();

        $response = $this->actingAs($this->tourist)->get(route('tourist.bookings.create', [
            'room' => $this->room->id,
            'check_in' => '2030-07-10',
            'check_out' => '2030-07-15',
        ]));

        $response->assertOk();

        // The address the script posts to, spelled the way the router knows it.
        $response->assertSee(route('tourist.bookings.payment-terms-preview'), false);

        // And the panel it writes into.
        $response->assertSee('data-payment-terms-preview', false);
        $response->assertSee('How this resort takes payment');
    }

    /* The endpoint ----------------------------------------------------------- */

    public function test_the_preview_uses_the_resorts_own_deposit(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30]);

        $this->preview(['total' => '5000.00'])
            ->assertOk()
            ->assertJson([
                'requirement' => 'deposit',
                'deposit_percent' => 30,
                'total' => '5000.00',
                'due_now' => '1500.00',
                'balance' => '3500.00',
                'needs_advance_payment' => true,
                'has_balance' => true,
                'last_minute' => false,
            ]);
    }

    /** Two resorts, two policies, two answers. */
    public function test_each_resort_gets_its_own_terms(): void
    {
        $this->policy(['deposit_percent' => 30]);

        $otherOwner = User::factory()->resortOwner()->create();
        $otherResort = Resort::factory()->for($otherOwner, 'owner')->active()->create();
        $otherRoom = Room::factory()->for($otherResort)->create(['price_per_night' => 1000]);

        $this->policy(['deposit_percent' => 50], $otherResort);

        $this->preview(['total' => '5000.00'])
            ->assertOk()
            ->assertJson(['due_now' => '1500.00', 'balance' => '3500.00']);

        $this->preview(['room' => $otherRoom->id, 'total' => '5000.00'])
            ->assertOk()
            ->assertJson(['due_now' => '2500.00', 'balance' => '2500.00']);
    }

    /**
     * The last-minute rule is the server's decision, reached from the
     * arrival date it is given. Nothing in the browser knows the window.
     */
    public function test_an_imminent_arrival_is_recognised_by_the_server(): void
    {
        $this->policy([
            'requirement' => PaymentRequirement::Deposit,
            'deposit_percent' => 30,
            'last_minute_rule' => 'full',
            'last_minute_hours' => 48,
        ]);

        // Inside the window: the whole bill, nothing left over.
        $this->preview(['check_in_date' => '2030-06-02', 'total' => '5000.00'])
            ->assertOk()
            ->assertJson([
                'requirement' => 'full',
                'due_now' => '5000.00',
                'balance' => '0.00',
                'last_minute' => true,
                'has_balance' => false,
            ]);

        // Outside it: the ordinary deposit.
        $this->preview(['check_in_date' => '2030-07-10', 'total' => '5000.00'])
            ->assertOk()
            ->assertJson(['requirement' => 'deposit', 'due_now' => '1500.00', 'last_minute' => false]);
    }

    public function test_a_resort_that_asks_for_nothing_up_front_says_so(): void
    {
        // on_site, not "none": LastMinuteRule's third case is the one
        // that lets an imminent arrival settle at the gate, and it maps
        // to PaymentRequirement::None.
        $this->policy([
            'requirement' => PaymentRequirement::None,
            'last_minute_rule' => 'on_site',
            'allow_on_site' => true,
        ]);

        $this->preview(['total' => '5000.00'])
            ->assertOk()
            ->assertJson([
                'due_now' => '0.00',
                'needs_advance_payment' => false,
                'on_site_allowed' => true,
            ]);
    }

    /**
     * The money arrives written out, and the label with it.
     *
     * The browser sets text and toggles rows; it does not format a peso
     * or decide when a deposit stops being a deposit. Two places that
     * knew either would drift.
     */
    public function test_the_money_and_its_label_come_back_ready_to_show(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30]);

        $this->preview(['total' => '5000.00'])
            ->assertOk()
            ->assertJson(['formatted' => [
                'total' => '₱5,000.00',
                'due_now' => '₱1,500.00',
                'balance' => '₱3,500.00',
                'due_label' => 'Deposit to pay (30%)',
            ]]);

        $this->policy(['requirement' => PaymentRequirement::Full]);

        $this->preview(['total' => '5000.00'])
            ->assertOk()
            ->assertJson(['formatted' => ['due_label' => 'To pay before arrival']]);
    }

    /* It cannot move money --------------------------------------------------- */

    /**
     * THE PREVIEW IS A DISPLAY AND NOTHING ELSE.
     *
     * A guest who previews a total of one peso — or a million — and
     * then books must be charged on the real price of the stay. The
     * preview writes nothing, and BookingService prices the room again
     * and asks the policy again when the request is submitted.
     */
    public function test_a_made_up_preview_total_changes_nothing_that_is_charged(): void
    {
        $this->policy(['requirement' => PaymentRequirement::Deposit, 'deposit_percent' => 30]);

        // Ask for the terms on a nonsense figure.
        $this->preview(['total' => '1.00'])->assertOk()->assertJson(['due_now' => '0.30']);

        $this->assertSame(0, Booking::count(), 'The preview must not write anything.');

        // Then book the real stay: 5 nights at ₱1,000.
        $this->actingAs($this->tourist)
            ->post(route('tourist.bookings.store'), [
                'room' => $this->room->id,
                'check_in_date' => '2030-07-10',
                'check_out_date' => '2030-07-15',
                'check_in_time' => '14:00',
                'check_out_time' => '12:00',
                'adults' => 2,
                'children' => 0,
                'pwd_guests' => 0,
                'guests_male' => 1,
                'guests_female' => 1,
                'rooms_booked' => 1,
                'contact_name' => 'Bea Santos',
                'contact_phone' => '09171234567',
                'origin' => 'pangasinan',
            ])
            ->assertSessionHasNoErrors();

        $booking = Booking::sole();

        $this->assertSame('5000.00', $booking->total_amount);
        $this->assertSame('1500.00', $booking->amount_due_now);
    }

    public function test_the_preview_refuses_a_room_that_is_not_bookable(): void
    {
        $this->policy();

        /*
         * status, not total_rooms. Room::isBookable() asks whether the
         * room is active and its resort publicly visible; a room with
         * no units is still a bookable room that happens to be full,
         * which availability answers, not this.
         *
         * status is not mass assignable on purpose, so it is set after.
         */
        $hidden = Room::factory()->for($this->resort)->create();
        $hidden->forceFill(['status' => RoomStatus::Inactive])->save();

        $this->preview(['room' => $hidden->id])->assertStatus(422);
    }

    public function test_the_preview_refuses_nonsense(): void
    {
        $this->policy();

        $this->preview(['total' => 'free'])->assertStatus(422);
        $this->preview(['total' => '-10'])->assertStatus(422);
        $this->preview(['check_in_date' => 'next tuesday'])->assertStatus(422);
        $this->preview(['room' => 999999])->assertStatus(422);
    }

    /* Who may ask ------------------------------------------------------------ */

    public function test_a_guest_who_is_not_signed_in_cannot_ask(): void
    {
        $this->policy();

        $this->postJson(route('tourist.bookings.payment-terms-preview'), [
            'room' => $this->room->id,
            'check_in_date' => '2030-07-10',
            'total' => '5000.00',
        ])->assertUnauthorized();
    }

    public function test_a_resort_owner_cannot_ask(): void
    {
        $this->policy();

        $this->actingAs($this->owner)
            ->postJson(route('tourist.bookings.payment-terms-preview'), [
                'room' => $this->room->id,
                'check_in_date' => '2030-07-10',
                'total' => '5000.00',
            ])
            ->assertForbidden();
    }

    /* The wiring ------------------------------------------------------------- */

    /**
     * The endpoint existing is no use if nothing calls it.
     *
     * There is no browser in this suite, so this cannot watch the panel
     * update. What it can do is hold the contract the two sides share:
     * the hooks the script writes into, and the script asking for them.
     */
    public function test_the_panel_carries_the_hooks_the_script_writes_into(): void
    {
        $this->policy();

        $html = $this->actingAs($this->tourist)->get(route('tourist.bookings.create', [
            'room' => $this->room->id,
            'check_in' => '2030-07-10',
            'check_out' => '2030-07-15',
        ]))->assertOk()->getContent();

        foreach ([
            'data-terms-total',
            'data-terms-due-label',
            'data-terms-due',
            'data-terms-balance',
            'data-terms-nothing',
            'data-terms-sentence',
            'data-terms-lastminute',
            'data-terms-onsite',
            'data-terms-error',
        ] as $hook) {
            $this->assertStringContainsString($hook, $html, "The panel lost its {$hook} hook.");
        }

        // Announced to a screen reader as it changes, and marked busy while it does.
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('aria-busy', $html);
    }

    /** And the script asks, guards against stale answers, and says when it cannot. */
    public function test_the_script_asks_the_server_and_handles_what_comes_back(): void
    {
        $script = file_get_contents(public_path('js/booking-form.js'));

        $this->assertStringContainsString('paymentTermsPreviewUrl', $script);
        $this->assertStringContainsString('window.fetch(', $script);
        $this->assertStringContainsString('X-CSRF-TOKEN', $script);

        // An answer to an old question must not overwrite a newer one.
        $this->assertStringContainsString('AbortController', $script);
        $this->assertStringContainsString('mine < written', $script);

        // Busy while asking, and a visible word when it fails.
        $this->assertStringContainsString('aria-busy', $script);
        $this->assertStringContainsString('data-terms-error', $script);

        /*
         * AND NO POLICY IN THE BROWSER. A percentage or an hours figure
         * written here would be a second place that decides what a
         * resort charges, and the two would drift.
         */
        $this->assertStringNotContainsString('deposit_percent *', $script);
        $this->assertStringNotContainsString('* 0.3', $script);
        $this->assertStringNotContainsString('lastMinuteHours', $script);
    }
}
