<?php

namespace Tests\Feature\Booking;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The card fields on the booking form.
 *
 * They sit under the Card choice and open with it, the same way the
 * Manual GCash details do — one toggle in booking-form.js now serves both,
 * keyed by the method each panel names.
 *
 * NOTHING HERE IS POSTED TO THIS SITE. The inputs carry no name attribute,
 * so the browser has nothing to send and the server has nothing to receive;
 * the number goes to PayMongo and a token comes back. The test that matters
 * most below is the one asserting exactly that.
 */
class CardOnBookingFormTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-06-01 09:00:00');

        config([
            'services.paymongo.secret' => 'sk_test_pretend',
            'services.paymongo.public' => 'pk_test_pretend',
            'services.paymongo.methods' => ['card'],
        ]);

        $this->tourist = User::factory()->create();
        $this->room = Room::factory()
            ->for(Resort::factory()->active())
            ->create(['capacity' => 4, 'total_rooms' => 3, 'price_per_night' => 1000]);
    }

    private function form(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->tourist)->get(route('tourist.bookings.create', [
            'room' => $this->room->id,
            'check_in' => '2030-07-01',
            'check_out' => '2030-07-03',
        ]));
    }

    public function test_the_card_fields_are_on_the_booking_form(): void
    {
        $this->form()
            ->assertOk()
            ->assertSee('Card holder name')
            ->assertSee('Card number')
            ->assertSee('CVC');
    }

    /**
     * Hidden until Card is chosen, and tied to that choice by name rather
     * than by position, so the browser can open the right one.
     */
    public function test_the_panel_belongs_to_the_card_choice_and_starts_closed(): void
    {
        $html = $this->form()->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-preference-panel="online"[^>]*\bhidden\b/s',
            $html,
            'The card panel should name the Card choice and start hidden.'
        );
    }

    /**
     * THE ONE THAT MATTERS. A field with no name cannot be submitted, so
     * there is no request in which a card number could reach this server.
     */
    public function test_the_card_inputs_cannot_be_posted_to_this_site(): void
    {
        $html = $this->form()->assertOk()->getContent();

        preg_match('/<section[^>]*data-card-panel.*?<\/section>/s', $html, $panel);

        $this->assertNotEmpty($panel, 'The card panel was not found.');

        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*\bname=/',
            $panel[0],
            'A card input carries a name, which means it would be posted here.'
        );
    }

    public function test_the_secret_key_is_not_in_the_page(): void
    {
        $this->form()->assertOk()->assertDontSee('sk_test_pretend');
    }

    public function test_without_a_publishable_key_there_are_no_card_fields(): void
    {
        config(['services.paymongo.public' => null]);

        $this->form()->assertOk()->assertDontSee('Card holder name');
    }

    /**
     * The GCash panel was the only one before this, and it moved to the
     * same attribute. If it stopped opening, nobody could pay that way.
     */
    public function test_the_gcash_panel_still_uses_the_shared_toggle(): void
    {
        $html = $this->form()->assertOk()->getContent();

        if (! str_contains($html, 'GCash Reference Number')) {
            $this->markTestSkipped('This resort has no manual GCash account.');
        }

        $this->assertStringContainsString('data-preference-panel="manual_gcash"', $html);
    }
}
