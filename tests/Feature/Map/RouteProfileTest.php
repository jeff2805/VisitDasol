<?php

namespace Tests\Feature\Map;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Whether a road climbs or drops.
 *
 * The routing service answers how far and how long; the height is not in its
 * geometry at all. These cover the part that could quietly lie: a height
 * model accurate to about a metre, summed naively over a hundred points,
 * turns a flat coastal road into a mountain stage.
 */
class RouteProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<float>  $metres
     * @return list<array{lat: float, lng: float}>
     */
    private function roadOf(array $metres): array
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['elevation' => $metres])]);

        // About 300 m apart, which is the spacing the map samples at.
        return array_map(
            fn (int $i): array => ['lat' => 15.99 + ($i * 0.0027), 'lng' => 119.88],
            range(0, count($metres) - 1),
        );
    }

    public function test_a_climb_is_reported_as_a_climb(): void
    {
        $road = $this->roadOf([0.0, 20.0, 45.0, 80.0]);

        $this->postJson(route('routes.profile'), ['points' => $road])
            ->assertOk()
            ->assertJson([
                'found' => true,
                'ascent' => 80,
                'descent' => 0,
                'low' => 0,
                'high' => 80,
                'steepest_falls' => false,
            ]);
    }

    public function test_a_descent_is_reported_as_a_descent(): void
    {
        $road = $this->roadOf([80.0, 45.0, 20.0, 0.0]);

        $response = $this->postJson(route('routes.profile'), ['points' => $road])->assertOk();

        $this->assertSame(0, $response->json('ascent'));
        $this->assertSame(80, $response->json('descent'));
        $this->assertTrue($response->json('steepest_falls'));
    }

    /**
     * The whole reason the filter exists.
     *
     * A sea-level road wobbles by a metre either way in the height model.
     * Added up without thought that reads as tens of metres of climbing, and
     * the map would tell a visitor a flat coastal road is hard work.
     */
    public function test_a_flat_road_does_not_accumulate_imaginary_hills(): void
    {
        $road = $this->roadOf([2.0, 1.0, 2.0, 3.0, 2.0, 1.0, 2.0, 3.0, 2.0, 1.0, 2.0, 1.0]);

        $response = $this->postJson(route('routes.profile'), ['points' => $road])->assertOk();

        $this->assertSame(0, $response->json('ascent'), 'a one-metre wobble is not a hill');
        $this->assertSame(0, $response->json('descent'));
        $this->assertNull($response->json('steepest'));
    }

    /** A real climb through noise is still a real climb. */
    public function test_a_real_hill_is_not_filtered_away_by_the_wobble(): void
    {
        $road = $this->roadOf([0.0, 1.0, 0.0, 25.0, 24.0, 26.0, 50.0]);

        $response = $this->postJson(route('routes.profile'), ['points' => $road])->assertOk();

        $this->assertGreaterThanOrEqual(45, $response->json('ascent'));
        $this->assertSame(50, $response->json('high'));
    }

    public function test_the_steepest_stretch_is_reported_as_a_percentage(): void
    {
        // Roughly 300 m along, 30 m up: about 10%.
        $road = $this->roadOf([0.0, 30.0]);

        $steepest = $this->postJson(route('routes.profile'), ['points' => $road])
            ->assertOk()
            ->json('steepest');

        $this->assertGreaterThan(5, $steepest);
        $this->assertLessThan(15, $steepest);
    }

    /* The marks drawn on the road itself ------------------------------------ */

    /**
     * Each point carries the slope of the stretch that ends at it, signed, so
     * the map can paint the road: a red climb is seen at a glance where a
     * sentence of totals underneath is skimmed past.
     */
    public function test_each_stretch_carries_its_slope_so_the_road_can_be_painted(): void
    {
        // Up, up, then back down to where it started.
        $road = $this->roadOf([0.0, 30.0, 60.0, 30.0, 0.0]);

        $points = $this->postJson(route('routes.profile'), ['points' => $road])
            ->assertOk()
            ->json('points');

        $this->assertNull($points[0]['grade'], 'nothing leads into the first point');

        // Climbing first, dropping after. Sign is the whole signal.
        $this->assertGreaterThan(0, $points[1]['grade']);
        $this->assertGreaterThan(0, $points[2]['grade']);
        $this->assertLessThan(0, $points[3]['grade']);
        $this->assertLessThan(0, $points[4]['grade']);
    }

    /**
     * A flat road must be painted level, not in climbing colours. This is the
     * same model noise as before, now able to mislead by sight rather than in
     * a number.
     */
    public function test_a_flat_road_is_painted_level_all_the_way(): void
    {
        $road = $this->roadOf([2.0, 1.0, 2.0, 3.0, 2.0, 1.0, 2.0]);

        $points = $this->postJson(route('routes.profile'), ['points' => $road])
            ->assertOk()
            ->json('points');

        foreach ($points as $index => $point) {
            $this->assertNull($point['grade'], "point {$index} should read as level");
        }
    }

    /** The slope on the road and the totals beneath it must agree. */
    public function test_the_painted_slopes_agree_with_the_totals(): void
    {
        $road = $this->roadOf([0.0, 30.0, 60.0]);

        $response = $this->postJson(route('routes.profile'), ['points' => $road])->assertOk();

        $climbing = collect($response->json('points'))
            ->filter(fn (array $p): bool => $p['grade'] !== null && $p['grade'] > 0);

        $this->assertCount(2, $climbing);
        $this->assertGreaterThan(0, $response->json('ascent'));
        $this->assertSame(0, $response->json('descent'), 'nothing on this road goes down');
    }

    /* Being a good neighbour ------------------------------------------------ */

    public function test_a_point_already_measured_is_never_asked_for_twice(): void
    {
        $road = $this->roadOf([0.0, 20.0, 45.0, 80.0]);

        $this->postJson(route('routes.profile'), ['points' => $road])->assertOk();
        $this->postJson(route('routes.profile'), ['points' => $road])->assertOk();

        Http::assertSentCount(1);
    }

    public function test_the_service_says_who_it_is(): void
    {
        $road = $this->roadOf([0.0, 20.0]);

        $this->postJson(route('routes.profile'), ['points' => $road])->assertOk();

        Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => str_contains(
            $request->header('User-Agent')[0] ?? '', config('app.name')
        ));
    }

    /**
     * A height service that is down must cost the map nothing: the route, the
     * distance and the driving time are already drawn and still true.
     */
    public function test_a_height_service_that_fails_is_answered_plainly(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response('', 503)]);

        $this->postJson(route('routes.profile'), [
            'points' => [['lat' => 15.99, 'lng' => 119.88], ['lat' => 15.97, 'lng' => 119.86]],
        ])
            ->assertOk()
            ->assertExactJson(['found' => false]);
    }

    /* What the endpoint will not be used for -------------------------------- */

    public function test_it_refuses_more_of_the_road_than_it_should_measure(): void
    {
        Http::fake();

        $tooMany = array_map(
            fn (int $i): array => ['lat' => 15.99 + ($i * 0.0001), 'lng' => 119.88],
            range(0, 120),
        );

        $this->postJson(route('routes.profile'), ['points' => $tooMany])
            ->assertStatus(422)
            ->assertJsonValidationErrors('points');

        Http::assertNothingSent();
    }

    /** It serves a map of Dasol, not a height service for the whole planet. */
    public function test_it_refuses_points_outside_the_philippines(): void
    {
        Http::fake();

        $this->postJson(route('routes.profile'), [
            'points' => [['lat' => 46.5, 'lng' => 7.9], ['lat' => 46.6, 'lng' => 8.0]],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_single_point_is_not_a_road(): void
    {
        Http::fake();

        $this->postJson(route('routes.profile'), ['points' => [['lat' => 15.99, 'lng' => 119.88]]])
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
