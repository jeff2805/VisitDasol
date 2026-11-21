<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * The parts of the public About page the tourism office may write.
 *
 * SEVEN SECTIONS, AND DELIBERATELY NOT THE WHOLE PAGE. The page also
 * explains how this site works and why listings are reviewed. Neither is
 * here, and neither ever should be: those describe what the SOFTWARE does,
 * and an office able to edit them could publish a promise the system does
 * not keep. Facts about the municipality change when the municipality does;
 * facts about the software change when the software does.
 *
 * Each case declares the shape of its own payload and the words the page
 * started life with. The defaults matter more than they look: a section
 * nobody has published yet falls back to them, so the page reads properly
 * on the day this ships and on a fresh install, with no seeding step and no
 * empty headings.
 */
enum AboutSection: string
{
    use EnumHelpers;

    case Hero = 'hero';
    case Municipality = 'municipality';
    case History = 'history';
    case Heritage = 'heritage';
    case Destinations = 'destinations';
    case Location = 'location';
    case OwnerCta = 'owner_cta';

    public function label(): string
    {
        return match ($this) {
            self::Hero => 'Hero banner',
            self::Municipality => 'Municipality information',
            self::History => 'History of Dasol',
            self::Heritage => 'Salt-making heritage',
            self::Destinations => 'Tourism destinations',
            self::Location => 'Location information',
            self::OwnerCta => 'Resort owner information',
        };
    }

    public function blurb(): string
    {
        return match ($this) {
            self::Hero => 'The banner at the top of the page, and the words over it.',
            self::Municipality => 'What Dasol is, in a paragraph, with a photograph.',
            self::History => 'The town\'s story, in as many paragraphs as it needs.',
            self::Heritage => 'Salt-making, and the pictures that show it.',
            self::Destinations => 'Which places appear, and in what order.',
            self::Location => 'The address card beside the municipality.',
            self::OwnerCta => 'The card inviting resort owners to apply.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Hero => 'image',
            self::Municipality => 'buildings',
            self::History => 'book',
            self::Heritage => 'droplet',
            self::Destinations => 'signpost-2',
            self::Location => 'geo-alt',
            self::OwnerCta => 'person-badge',
        };
    }

    /** Whether this section carries a single picture of its own. */
    public function hasImage(): bool
    {
        return in_array($this, [self::Hero, self::Municipality, self::History], true);
    }

    /** Whether this section carries a gallery with captions. */
    public function hasGallery(): bool
    {
        return $this === self::Heritage;
    }

    /** Where this section's uploads live on the public disk. */
    public function folder(): string
    {
        return 'about/'.$this->value;
    }

    /**
     * What the office's form may post, and what it must.
     *
     * Beside the defaults on purpose: the shape of a section is declared
     * once, and a field added above without a rule here would be a field
     * saved without being checked.
     *
     * Picture uploads are NOT here. Every section validates those the same
     * way, from the site's own upload settings, and UpdateAboutSectionRequest
     * adds them so three sections cannot drift to three different limits.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Hero => [
                'label' => ['required', 'string', 'max:60'],
                'heading' => ['required', 'string', 'max:150'],
                'subheading' => ['nullable', 'string', 'max:300'],
            ],

            self::Municipality => [
                'heading' => ['required', 'string', 'max:150'],
                'body' => ['required', 'string', 'max:4000'],
            ],

            self::History => [
                'heading' => ['required', 'string', 'max:150'],
                'paragraphs' => ['required', 'array', 'min:1', 'max:30'],
                'paragraphs.*' => ['required', 'string', 'max:4000'],
            ],

            self::Heritage => [
                'heading' => ['required', 'string', 'max:150'],
                'body' => ['required', 'string', 'max:4000'],
                // The gallery that survives this save.
                'gallery_path' => ['nullable', 'array', 'max:20'],
                'gallery_path.*' => ['required', 'string', 'max:255'],
                'gallery_caption' => ['nullable', 'array', 'max:20'],
                'gallery_caption.*' => ['nullable', 'string', 'max:200'],
                // Where each picture sits. A number rather than drag and
                // drop: it needs no JavaScript, works from the keyboard,
                // and survives a page that failed to load its scripts.
                'gallery_order' => ['nullable', 'array', 'max:20'],
                'gallery_order.*' => ['nullable', 'integer', 'min:0', 'max:999'],
            ],

            self::Destinations => [
                'heading' => ['required', 'string', 'max:150'],
                'subheading' => ['nullable', 'string', 'max:300'],
                'destination_ids' => ['nullable', 'array', 'max:12'],
                // Checked against the real table: the About page points at
                // destinations, it does not invent them.
                'destination_ids.*' => ['integer', 'exists:destinations,id'],
                // Keyed by destination id, so a position survives the
                // checkbox beside it being unticked and ticked again.
                'destination_order' => ['nullable', 'array', 'max:200'],
                'destination_order.*' => ['nullable', 'integer', 'min:0', 'max:999'],
            ],

            self::Location => [
                'heading' => ['required', 'string', 'max:150'],
                'municipality' => ['required', 'string', 'max:80'],
                'province' => ['required', 'string', 'max:80'],
                'region' => ['required', 'string', 'max:80'],
                'country' => ['required', 'string', 'max:80'],
                'postal_code' => ['required', 'string', 'max:10'],
            ],

            self::OwnerCta => [
                'visible' => ['boolean'],
                'heading' => ['required', 'string', 'max:150'],
                'body' => ['required', 'string', 'max:1000'],
                'button_label' => ['required', 'string', 'max:60'],
            ],
        };
    }

    /**
     * The words the page started with, used until the office publishes
     * something of its own.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::Hero => [
                'label' => 'ABOUT DASOL',
                'heading' => 'Discover Dasol, Pangasinan',
                'subheading' => 'Explore the beauty, history, and culture of the Salt Capital of Pangasinan.',
                'image_path' => null,
            ],

            self::Municipality => [
                'heading' => 'The municipality',
                'body' => 'Dasol is a coastal municipality in the western part of Pangasinan, in the '
                    .'Ilocos Region of the Philippines. It is made up of 18 barangays and uses the '
                    .'postal code 2411. The town is long associated with salt-making, and its '
                    .'shoreline faces Dasol.',
                'image_path' => null,
            ],

            /*
             * Paragraphs as a list rather than one block of text, because
             * the office asked to add, remove and REORDER them. Reordering
             * a paragraph buried in a textarea means cutting and pasting
             * without losing a sentence; reordering a list is a button.
             */
            self::History => [
                'heading' => 'History of Dasol',
                'paragraphs' => [
                    'Dasol is a serene coastal municipality located in the western part of Pangasinan, '
                        .'known for its natural beauty and rich cultural heritage. It is recognized as one '
                        .'of Pangasinan\'s hidden gems and is also known as the "Home of Quality Salt."',
                    'The name Dasol originated from the medicinal herb called "dosol", which reportedly '
                        .'grew abundantly in the area during the Spanish period. The plant was traditionally '
                        .'used to treat infected wounds. Its name was frequently mispronounced by the '
                        .'Spaniards as "Dasol," eventually becoming the municipality\'s official name. The '
                        .'Ilocano word "dasol", meaning "to stretch," is also associated with the long '
                        .'stretch of white-sand beaches along its coastline.',
                    'Originally part of the municipality of San Isidro de Potot, now known as Burgos, '
                        .'Dasol became a separate municipality during the American colonial period. It '
                        .'officially became an independent municipality on January 27, 1911. Its early '
                        .'settlers were primarily Ilocanos and Pangasinenses who relied on farming, '
                        .'fishing, and salt-making for their livelihood.',
                    'During World War II, Dasol became historically significant as an area of underground '
                        .'guerrilla resistance against Japanese occupation. Its caves and rugged terrain '
                        .'served as shelters and meeting places for freedom fighters. The local salt '
                        .'industry also played an important role in sustaining communities during wartime '
                        .'shortages.',
                    'Today, Dasol is recognized for its beautiful coastal destinations, including '
                        .'Tambobong Beach and Balinmanok, crystal-clear waters, and hidden coves. It '
                        .'continues to develop as an emerging ecotourism destination while preserving its '
                        .'traditions, cultural identity, and resilient community.',
                    'Whether visitors come to enjoy its tranquil beaches, explore its history, discover '
                        .'its cultural heritage, or experience traditional salt farming, Dasol welcomes '
                        .'everyone with its natural beauty, rich history, and warm community.',
                ],
                'image_path' => null,
            ],

            /*
             * The gallery lives in the payload rather than in a table of
             * its own. A second table would need its own draft and
             * published copies to keep the publishing rule — an unpublished
             * photograph must not appear — and that is the same two-copy
             * problem solved twice, in two places, free to disagree.
             */
            self::Heritage => [
                'heading' => "Dasol's Salt-Making Heritage",
                'body' => 'Salt-making is an important part of Dasol\'s local identity and livelihood. '
                    .'The municipality is recognized for its salt-producing industry, which contributes '
                    .'to its cultural and economic heritage.'."\n\n"
                    .'Visitors can learn about the traditional salt-making industry and appreciate the '
                    .'local communities and livelihoods that help shape the identity of Dasol.',
                'images' => [],
            ],

            /*
             * Ids from the destinations table, in the order they appear. The
             * office already manages destinations elsewhere, and this page
             * must not become a second place where a beach is described —
             * taking one off the About page leaves the destination itself
             * untouched, which is exactly what was asked for.
             *
             * Empty means "choose for me": the page falls back to the
             * featured destinations, so a fresh install is not a blank row
             * of cards.
             */
            self::Destinations => [
                'heading' => 'Explore the Beauty of Dasol',
                'subheading' => 'Beaches, view decks and coves worth the trip.',
                'destination_ids' => [],
            ],

            /*
             * The card beside the municipality text. Its own copy of the
             * address, NOT the live configuration: config('visitdasol.*')
             * feeds the map, the reports and the booking screens, and an
             * office correcting a line of prose here must not be able to
             * move the municipality on the GIS layer by accident.
             */
            self::Location => [
                'heading' => 'Where Dasol is',
                'municipality' => 'Dasol',
                'province' => 'Pangasinan',
                'region' => 'Ilocos Region (Region I)',
                'country' => 'Philippines',
                'postal_code' => '2411',
            ],

            /*
             * No route here, on purpose. The button's address belongs to
             * the application and is written in the template; the office
             * may change what the card SAYS, and cannot change where it
             * goes. A mistyped URL on this card would quietly break resort
             * registration for everybody.
             */
            self::OwnerCta => [
                'visible' => true,
                'heading' => 'Own a resort in Dasol?',
                'body' => 'List your resort on Visit Dasol and take reservations online. '
                    .'The tourism office reviews every application before it appears.',
                'button_label' => 'Apply as a resort owner',
            ],
        };
    }
}
