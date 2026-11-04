<?php

namespace Tests\Feature;

use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Rooms page search is one GET form, or it is nothing.
 *
 * It was two: the stay bar posted its own form and the filter bar posted
 * another, so the orange Search button sent four fields and silently threw
 * away the keyword, category, guests and sort sitting right below it —
 * and Apply filters threw away the dates. Two forms over one result set
 * cannot be kept in agreement by reading them.
 */
class RoomsSearchFormTest extends TestCase
{
    use RefreshDatabase;

    private function room(array $resort, array $room): Room
    {
        $owner = User::factory()->resortOwner()->create();
        $r = Resort::factory()->for($owner, 'owner')->active()->create($resort);

        return Room::factory()->for($r)->create($room);
    }

    /** The Rooms page must be ONE form carrying all eight controls. */
    public function test_one_form_holds_every_control(): void
    {
        $this->room(['name' => 'Salt Breeze'], ['name' => 'Seaview Room']);

        $html = $this->get(route('resorts.index'))->assertOk()->getContent();

        // Exactly one GET form on the search panel.
        preg_match_all('/<form[^>]*method="GET"[^>]*>/i', $html, $forms);
        $this->assertCount(1, $forms[0], 'There must be exactly one search form.');

        // No nested form inside it.
        preg_match('/<form[^>]*method="GET".*?<\/form>/is', $html, $m);
        $form = $m[0];
        $this->assertSame(1, preg_match_all('/<form/i', $form), 'Nested form found.');

        foreach ([
            'check_in', 'check_in_time', 'check_out', 'check_out_time',
            'q', 'type', 'guests', 'sort',
        ] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $form, "missing {$field}");
        }

        // Both submit buttons live inside that one form.
        $this->assertStringContainsString('Search', $form);
        $this->assertStringContainsString('Apply filters', $form);
    }

    /** All eight applied together, and echoed back into the controls. */
    public function test_all_eight_parameters_are_applied_and_preserved(): void
    {
        $this->room(['name' => 'Salt Breeze'], [
            'name' => 'Seaview Suite', 'room_type' => 'premium',
            'capacity' => 4, 'price_per_night' => 3000,
        ]);
        $this->room(['name' => 'Hilltop'], [
            'name' => 'Tiny Hut', 'room_type' => 'budget',
            'capacity' => 1, 'price_per_night' => 500,
        ]);

        $query = [
            'check_in' => '2030-04-10',
            'check_in_time' => '15:30',
            'check_out' => '2030-04-12',
            'check_out_time' => '10:00',
            'q' => 'Seaview',
            'type' => 'premium',
            'guests' => 3,
            'sort' => 'price_high',
        ];

        $response = $this->get(route('resorts.index', $query))->assertOk();

        // Applied: the budget 1-guest room is filtered out.
        $response->assertSee('Seaview Suite')->assertDontSee('Tiny Hut');

        $html = $response->getContent();

        // Preserved in the controls.
        $this->assertStringContainsString('name="check_in"', $html);
        $this->assertMatchesRegularExpression('/name="check_in"[^>]*value="2030-04-10"/s', $html);
        $this->assertMatchesRegularExpression('/name="check_in_time"[^>]*value="15:30"/s', $html);
        $this->assertMatchesRegularExpression('/name="check_out"[^>]*value="2030-04-12"/s', $html);
        $this->assertMatchesRegularExpression('/name="check_out_time"[^>]*value="10:00"/s', $html);
        $this->assertMatchesRegularExpression('/name="q"[^>]*value="Seaview"/s', $html);
        $this->assertMatchesRegularExpression('/name="guests"[^>]*value="3"/s', $html);
        $this->assertStringContainsString('<option value="premium" selected>', $html);
        $this->assertStringContainsString('<option value="price_high" selected>', $html);
    }

    /** Rubbish times fall back instead of poisoning the form. */
    public function test_bad_times_fall_back(): void
    {
        $this->room(['name' => 'Salt Breeze'], ['name' => 'Seaview Room']);

        $html = $this->get(route('resorts.index', [
            'check_in_time' => '99:99', 'check_out_time' => 'drop table',
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="check_in_time"[^>]*value="14:00"/s', $html);
        $this->assertMatchesRegularExpression('/name="check_out_time"[^>]*value="12:00"/s', $html);
    }

    /** The category buttons drive the real room_type filter. */
    public function test_category_buttons_filter(): void
    {
        $this->room(['name' => 'Salt Breeze'], ['name' => 'Seaview Suite', 'room_type' => 'premium']);
        $this->room(['name' => 'Hilltop'], ['name' => 'Tiny Hut', 'room_type' => 'budget']);

        $html = $this->get(route('resorts.index'))->assertOk()->getContent();
        $this->assertStringContainsString('type=budget', $html);
        $this->assertStringNotContainsString('category=', $html);

        $this->get(route('resorts.index', ['type' => 'budget']))
            ->assertOk()->assertSee('Tiny Hut')->assertDontSee('Seaview Suite');
    }

    /** The hero search on pages with no parent form still submits. */
    public function test_hero_search_is_its_own_form(): void
    {
        foreach (['/', '/tourist/dashboard'] as $path) {
            $user = User::factory()->create();
            $html = ($path === '/' ? $this->get($path) : $this->actingAs($user)->get($path))
                ->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<form class="vd-stay-search"[^>]*method="GET"/s',
                $html,
                $path.' lost its form wrapper'
            );
            $this->assertStringContainsString(route('resorts.index'), $html);
        }
    }

    /** Clear drops every parameter. */
    public function test_clear_has_no_query_string(): void
    {
        $this->room(['name' => 'Salt Breeze'], ['name' => 'Seaview Room']);

        $html = $this->get(route('resorts.index', ['q' => 'x', 'guests' => 2]))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('resorts.index'), '/').'"[^>]*>\s*Clear/s',
            $html
        );
    }
}
