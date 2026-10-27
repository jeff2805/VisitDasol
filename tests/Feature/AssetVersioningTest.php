<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every stylesheet and script this project owns carries a cache stamp.
 *
 * There is no build step here, so nothing renames a file when its contents
 * change. Without a stamp a returning visitor keeps running whatever their
 * browser cached — which can be months old — and a fix that is live on the
 * server never reaches them.
 *
 * Before @vdasset existed the stamp was written out by hand and only two of
 * the nine files had one. This is what stops that happening again.
 */
class AssetVersioningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pages chosen to pull in every component that loads a script of its own:
     * the maps, the charts, the coordinate picker, the booking form.
     *
     * @return list<string>
     */
    private function pagesWithAssets(): array
    {
        $resort = Resort::factory()->active()->create();
        $room = Room::factory()->for($resort)->create();
        $destination = Destination::factory()->create();

        return [
            route('home'),
            route('resorts.index'),
            route('resorts.show', $resort),
            route('destinations.show', $destination),
            route('map'),
            route('tourist.bookings.create', [
                'room' => $room->id,
                'check_in' => now()->addDays(10)->toDateString(),
                'check_out' => now()->addDays(12)->toDateString(),
            ]),
        ];
    }

    public function test_every_script_and_stylesheet_is_stamped(): void
    {
        $tourist = User::factory()->create();

        foreach ($this->pagesWithAssets() as $url) {
            $html = $this->actingAs($tourist)->get($url)->assertOk()->getContent();

            /*
             * Only this project's own files: /js/… and /css/… directly off the
             * site root. Vendored libraries live under /vendor/ and are pinned
             * by the version in their own path, so they never change under us
             * and need no stamp.
             */
            preg_match_all(
                '#(?:src|href)="https?://[^/"]+/(js|css)/([^"?]+)(\?[^"]*)?"#',
                $html,
                $found,
                PREG_SET_ORDER,
            );

            $this->assertNotEmpty($found, "no own assets found on {$url}");

            foreach ($found as $asset) {
                $this->assertMatchesRegularExpression(
                    '/^\?v=\d+$/',
                    $asset[3] ?? '',
                    "{$asset[2]} on {$url} has no cache stamp, so a returning visitor keeps the old copy",
                );
            }
        }
    }

    /** The stamp has to follow the file, or it is just noise in the URL. */
    public function test_the_stamp_is_the_files_own_modification_time(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $expected = filemtime(public_path('css/visitdasol.css'));

        $this->assertStringContainsString("css/visitdasol.css?v={$expected}", $html);
    }

    /** A file that is not there still renders a tag somebody can find. */
    public function test_a_missing_file_still_produces_a_usable_url(): void
    {
        $url = \App\Support\Asset::url('js/not-a-real-file.js');

        $this->assertStringContainsString('js/not-a-real-file.js', $url);
        $this->assertStringNotContainsString('?v=', $url);
    }
}
