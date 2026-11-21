<?php

namespace Tests\Feature\Messaging;

use App\Models\Conversation;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which shell the message pages are drawn in.
 *
 * Messaging is one set of pages on one route, read from three different
 * places. It used to draw the public site's header and footer whoever
 * opened it, so a resort owner who clicked Messages in their own sidebar
 * was thrown out of the dashboard and had to navigate back — while every
 * other entry in that sidebar kept them inside it.
 *
 * The threads themselves are unchanged. This is only about the frame.
 */
class MessageChromeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $tourist;

    private Resort $resort;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'resort_owner']);
        $this->tourist = User::factory()->create();
        $this->resort = Resort::factory()->active()->create(['owner_id' => $this->owner->id]);

        // Through the service, which is the only thing that opens a thread.
        $this->conversation = app(\App\Services\MessagingService::class)
            ->between($this->tourist, $this->resort);
    }

    public function test_an_owner_keeps_their_dashboard_sidebar_on_the_inbox(): void
    {
        $this->actingAs($this->owner)
            ->get(route('messages.index'))
            ->assertOk()
            // The sidebar the dashboard draws, and a link only it carries.
            ->assertSee('vd-sidebar__list', false)
            ->assertSee(route('owner.walk-ins.index'))
            ->assertSee('Your conversations with guests.');
    }

    public function test_an_owner_keeps_the_sidebar_inside_a_thread_too(): void
    {
        $this->actingAs($this->owner)
            ->get(route('messages.show', $this->conversation))
            ->assertOk()
            ->assertSee('vd-sidebar__list', false)
            ->assertSee(route('owner.walk-ins.index'));
    }

    public function test_a_guest_still_gets_the_public_site(): void
    {
        $response = $this->actingAs($this->tourist)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertSee('Your conversations with resorts on Dasol.');

        // No owner sidebar anywhere on a guest's page.
        $response->assertDontSee(route('owner.walk-ins.index'));
    }

    public function test_the_office_reads_messages_in_its_own_dashboard(): void
    {
        $office = User::factory()->superAdmin()->create();

        $this->actingAs($office)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertSee('vd-sidebar__list', false)
            // A link only the office's sidebar carries.
            ->assertSee(route('admin.walk-ins.index'));
    }

    /**
     * The stylesheet is pushed from the view, and the dashboard shell has to
     * carry it through as the public one did.
     */
    public function test_the_message_styles_reach_the_dashboard_shell(): void
    {
        $this->actingAs($this->owner)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertSee('css/messages.css', false);
    }
}
