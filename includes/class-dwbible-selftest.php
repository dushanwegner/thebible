<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DwBible_SelfTest_Trait {
    /**
     * The single-language datasets this install ships, derived — never listed.
     *
     * dwbible#15: three of the four checks below hardcoded ['bible','bibel',
     * 'latin'] and a fourth ['bible','latin'], so Spanish, French and Italian
     * were never verified. A broken index.csv, an unresolvable book slug or a
     * missing OSIS mapping in any of the three passed in silence. Deriving means
     * a seventh dataset extends this file automatically instead of quietly
     * falling outside it, which is exactly how these three went missing.
     */
    private static function datasets() {
        $out = [];
        foreach (DwBible_Plugin::all_slugs() as $slug) {
            if (strpos($slug, '-') === false) { $out[] = $slug; }
        }
        return $out;
    }

    /**
     * The TWO-part combo slugs (latin-bible, bible-spanish, …), derived.
     *
     * Deliberately not every combo: this install generates 150 of them, and
     * 150 combos x 6 datasets x ~73 books is ~65,000 resolutions for a check
     * that runs on a web request. The two-part set is 30 and reaches every
     * dataset, which is the property the check needs. That is a real limit on
     * coverage, so it is stated here and in the check's own failure text rather
     * than left for someone to infer from a green tick.
     */
    private static function combo_slugs() {
        $out = [];
        foreach (DwBible_Plugin::all_slugs() as $slug) {
            if (substr_count($slug, '-') === 1) { $out[] = $slug; }
        }
        return $out;
    }

    public static function render_selftest() {
        $results = [];

        $results[] = self::selftest_check('wp_loaded', function() {
            return function_exists('get_option');
        });

        $results[] = self::selftest_check('og_renderer_class_exists', function() {
            return class_exists('DwBible_OG_Image') && method_exists('DwBible_OG_Image', 'render');
        });

        $results[] = self::selftest_check('osis_mapping_json_valid', function() {
            $file = plugin_dir_path(__FILE__) . 'osis-mapping.json';
            if (!file_exists($file) || !is_readable($file)) {
                return new WP_Error('dwbible_selftest_missing_osis_mapping', 'OSIS mapping JSON missing/unreadable.');
            }
            $raw = file_get_contents($file);
            if (!is_string($raw) || $raw === '') {
                return new WP_Error('dwbible_selftest_empty_osis_mapping', 'OSIS mapping JSON empty.');
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['books']) || !is_array($data['books'])) {
                return new WP_Error('dwbible_selftest_invalid_osis_mapping', 'OSIS mapping JSON invalid.');
            }
            return true;
        });

        $results[] = self::selftest_check('interlinear_renderer_present', function() {
            return method_exists(__CLASS__, 'render_multilingual_book');
        });

        $results[] = self::selftest_check('router_present', function() {
            return method_exists(__CLASS__, 'render_bible_page') && method_exists(__CLASS__, 'handle_request');
        });

        $results[] = self::selftest_check('slugify_transliterates', function() {
            // slugify() output becomes an address, so the mapping is pinned
            // rather than trusted. The German pairs are the ones that matter:
            // ä→ae, not a bare `a` and not nothing — book_map.json and
            // abbreviations.de.json spell them that way, and a slug that
            // disagreed with those files silently stopped matching (which is
            // what happened, unnoticed, until 2026-08-31).
            $cases = [
                'Sprüche'      => 'sprueche',
                'Matthäus'     => 'matthaeus',
                '1 Makkabäer'  => '1-makkabaeer',
                'Römer'        => 'roemer',
                'Hebräer'      => 'hebraeer',
                'Weiß'         => 'weiss',
                // Romance accents fold to the base letter — `ue` is a German
                // convention, not a universal one.
                'Génesis'      => 'genesis',
                'Genèse'       => 'genese',
                'Ézéchiel'     => 'ezechiel',
                'Giosuè'       => 'giosue',
                'Première à Timothée' => 'premiere-a-timothee',
                // …and the plain cases still behave.
                'Genesis'      => 'genesis',
                '1_Kings_Samuel' => '1-kings-samuel',
                '  Spaced  Out ' => 'spaced-out',
            ];
            $failures = [];
            foreach ($cases as $in => $expected) {
                $got = DwBible_Plugin::slugify($in);
                if ($got !== $expected) {
                    $failures[] = "'$in' -> '$got' (expected '$expected')";
                }
            }
            if ($failures) {
                return new WP_Error('dwbible_selftest_slugify_failed', implode('; ', $failures));
            }
            return true;
        });

        $results[] = self::selftest_check('text_utils_cases', function() {
            if (!class_exists('DwBible_Text_Utils')) {
                return new WP_Error('dwbible_selftest_text_utils_missing', 'Text utils class missing (DwBible_Text_Utils).');
            }
            if (!method_exists('DwBible_Text_Utils', 'normalize_whitespace') || !method_exists('DwBible_Text_Utils', 'clean_verse_text_for_output')) {
                return new WP_Error('dwbible_selftest_text_utils_incomplete', 'Text utils methods missing.');
            }

            $s = "Hello\xC2\xA0world";
            $norm = DwBible_Text_Utils::normalize_whitespace($s);
            if ($norm !== 'Hello world') {
                return new WP_Error('dwbible_selftest_text_utils_norm_failed', 'normalize_whitespace did not normalize NBSP.');
            }

            $q = DwBible_Text_Utils::clean_verse_text_for_output('»Test', false, '»', '«');
            if (!is_string($q) || $q === '') {
                return new WP_Error('dwbible_selftest_text_utils_quote_failed', 'clean_verse_text_for_output returned empty output.');
            }
            // After balancing/normalization, the output should contain either an inner or outer closing guillemet.
            if (strpos($q, '«') === false && strpos($q, '‹') === false) {
                return new WP_Error('dwbible_selftest_text_utils_quote_failed', 'clean_verse_text_for_output did not synthesize a closing quote.');
            }

            return true;
        });

        // ── Data consistency checks ─────────────────────────────────────
        // These would have caught the OSIS latin-slug 404 bug.

        $results[] = self::selftest_check('osis_dataset_consistency', function() {
            // Every "latin", "bible", "bibel[0]" value in osis-mapping.json
            // must exist as a slug in the corresponding dataset's index CSV.
            $osis = DwBible_Mappings_Loader::load_osis_mapping();
            if (empty($osis['books']) || !is_array($osis['books'])) {
                return new WP_Error('dwbible_selftest', 'OSIS mapping empty or invalid.');
            }

            $datasets = self::datasets();
            $index_slugs = [];
            foreach ($datasets as $ds) {
                $csv = dwbible_data_dir() . $ds . '/html/index.csv';
                if (!file_exists($csv)) continue;
                $fh = fopen($csv, 'r');
                if ($fh === false) continue;
                fgetcsv($fh); // skip header
                $slugs = [];
                while (($row = fgetcsv($fh)) !== false) {
                    if (!is_array($row) || count($row) < 2) continue;
                    $slugs[] = DwBible_Plugin::slugify($row[1]);
                }
                fclose($fh);
                $index_slugs[$ds] = $slugs;
            }

            $failures = [];
            foreach ($osis['books'] as $code => $entry) {
                if (!is_array($entry)) continue;
                // Check bible
                if (isset($entry['bible'], $index_slugs['bible'])) {
                    $slug = DwBible_Plugin::slugify($entry['bible']);
                    if (!in_array($slug, $index_slugs['bible'], true)) {
                        $failures[] = "$code: bible='$slug' not in bible index";
                    }
                }
                // Check latin
                if (isset($entry['latin'], $index_slugs['latin'])) {
                    $slug = DwBible_Plugin::slugify($entry['latin']);
                    if (!in_array($slug, $index_slugs['latin'], true)) {
                        $failures[] = "$code: latin='$slug' not in latin index";
                    }
                }
                // Check bibel (first element must match the index)
                if (isset($entry['bibel'], $index_slugs['bibel']) && is_array($entry['bibel']) && !empty($entry['bibel'])) {
                    $slug = DwBible_Plugin::slugify($entry['bibel'][0]);
                    if (!in_array($slug, $index_slugs['bibel'], true)) {
                        $failures[] = "$code: bibel[0]='$slug' not in bibel index";
                    }
                }
            }

            if (!empty($failures)) {
                return new WP_Error('dwbible_selftest_osis_mismatch', implode('; ', array_slice($failures, 0, 10)));
            }
            return true;
        });

        $results[] = self::selftest_check('interlinear_osis_resolution', function() {
            // Simulates the exact code path in render_multilingual_book():
            // osis_for_dataset_book_slug → dataset_book_slug_for_osis → get_book_entry_for_dataset
            // This is the path that broke when OSIS latin slugs were wrong.
            $osis = DwBible_Mappings_Loader::load_osis_mapping();
            if (empty($osis['books']) || !is_array($osis['books'])) {
                return new WP_Error('dwbible_selftest', 'OSIS mapping empty.');
            }

            $datasets = self::datasets();
            $failures = [];

            foreach ($osis['books'] as $code => $entry) {
                if (!is_array($entry)) continue;
                foreach ($datasets as $ds) {
                    // Step 1: OSIS code → dataset slug (what render_multilingual_book does)
                    $resolved_slug = DwBible_Osis_Utils::dataset_book_slug_for_osis($osis, $ds, $code);
                    if (!is_string($resolved_slug) || $resolved_slug === '') continue;

                    // Step 2: resolved slug → book entry (must find the file)
                    $entry_result = self::get_book_entry_for_dataset($ds, $resolved_slug);
                    if (!$entry_result) {
                        $failures[] = "$code/$ds: OSIS resolved to '$resolved_slug' but get_book_entry_for_dataset() returned null";
                    }
                }
            }

            if (!empty($failures)) {
                return new WP_Error('dwbible_selftest_osis_resolution', implode('; ', array_slice($failures, 0, 10)));
            }
            return true;
        });

        $results[] = self::selftest_check('book_map_consistency', function() {
            // Every book_map.json value must name a book this plugin can
            // actually resolve, in every dataset it claims one for.
            //
            // ─── Why the RESOLVER and not slugify() ───────────────────────
            //
            // This check used to slugify each value and look for the result in
            // column 1 of the dataset's index CSV. It could not work, for two
            // independent reasons, and was red on both sites for long enough
            // that nobody read it:
            //
            //   1. Column 1 is `short_name` — the canonical key — while the
            //      German book_map values are DISPLAY names. `Levitikus` was
            //      compared against `leviticus` and lost. English and Latin
            //      passed only because both columns happen to agree there.
            //   2. slugify() DELETES non-ASCII rather than transliterating it,
            //      so `Sprüche` becomes `sprche` — while book_map spells the
            //      German convention out as `Sprueche`. Those two can never
            //      agree, whichever column is read.
            //
            // Both faults come from re-implementing book lookup inside a test.
            // key_from_any_book_slug() is what the PRODUCT resolves names with
            // — it already accepts a canonical key, a display name and a
            // transliteration — so asking it is both the simpler check and the
            // one that tests the real contract. If it can resolve every value,
            // every value is usable; if it stops being able to, that is a
            // genuine regression rather than a disagreement between two
            // spellings of the same book.
            //
            // What it therefore does NOT prove, stated so nobody assumes more:
            // the resolver is language-agnostic, so a German name sitting in
            // the `latin` column resolves happily and passes here. That is
            // untidy, not broken — the product resolves it too. Mutation-tested
            // on 2026-08-31: a typo (`Levitkus`) and a non-book are both caught;
            // a cross-language value is not.
            $book_map = DwBible_Mappings_Loader::load_book_map();
            if (empty($book_map) || !is_array($book_map)) {
                return true; // book_map.json is optional
            }
            if (!method_exists('DwBible_Plugin', 'key_from_any_book_slug')) {
                return new WP_Error('dwbible_selftest_book_map_no_resolver',
                    'DwBible_Plugin::key_from_any_book_slug() is missing — book_map cannot be checked.');
            }

            $datasets = self::datasets();

            // The books each dataset really ships, named in the SAME vocabulary
            // the values will be resolved into.
            //
            // Both sides go through the resolver because the datasets do not
            // agree with each other on the shape of column 1: `bible` writes it
            // capitalised (`Genesis`), `bibel` and `latin` lower-case
            // (`genesis`). Comparing raw strings across datasets is therefore a
            // trap, and normalising by hand would be re-implementing lookup
            // again — the mistake this check is being repaired from.
            $index_keys = [];
            foreach ($datasets as $ds) {
                $csv = dwbible_data_dir() . $ds . '/html/index.csv';
                if (!file_exists($csv)) continue;
                $fh = fopen($csv, 'r');
                if ($fh === false) continue;
                fgetcsv($fh);
                $keys = [];
                while (($row = fgetcsv($fh)) !== false) {
                    if (!is_array($row) || count($row) < 2) continue;
                    $name = (string) $row[1];
                    $key  = DwBible_Plugin::key_from_any_book_slug($name);
                    // An index entry the resolver cannot place is kept under its
                    // own lower-cased name rather than dropped: losing it here
                    // would turn a resolver gap into a phantom book_map failure,
                    // which is exactly the misdirection being fixed.
                    $keys[is_string($key) && $key !== '' ? $key : strtolower($name)] = true;
                }
                fclose($fh);
                $index_keys[$ds] = $keys;
            }

            $failures = [];
            foreach ($book_map as $key => $map_entry) {
                if (!is_array($map_entry)) continue;
                foreach ($datasets as $ds) {
                    if (!isset($map_entry[$ds], $index_keys[$ds])) continue;
                    $value = (string) $map_entry[$ds];
                    if ($value === '') continue;
                    $resolved = DwBible_Plugin::key_from_any_book_slug($value);
                    if (!is_string($resolved) || $resolved === '') {
                        $failures[] = "$key: $ds='$value' resolves to no book";
                        continue;
                    }
                    if (!isset($index_keys[$ds][$resolved])) {
                        $failures[] = "$key: $ds='$value' resolves to '$resolved', absent from the $ds index";
                    }
                }
            }

            if (!empty($failures)) {
                return new WP_Error('dwbible_selftest_book_map_mismatch', implode('; ', array_slice($failures, 0, 10)));
            }
            return true;
        });

        $results[] = self::selftest_check('all_books_resolve_in_combos', function() {
            // Every book in every dataset's index must resolve via
            // canonical_book_slug_from_url() for relevant combo slugs.
            // A combo is only asked about the datasets it is MADE of. Pairing
            // every dataset with every combo instead looks thorough and is
            // nonsense: it asks whether the German '1-koenige' resolves under
            // /bible-spanish/, which it must not, and the check then reports a
            // healthy install as broken. Verified while widening this — the
            // first version produced exactly those failures.
            $failures = [];
            $index_cache = [];
            $examined = 0;

            foreach (self::combo_slugs() as $combo) {
                foreach (explode('-', $combo) as $ds) {
                    if (!isset($index_cache[$ds])) {
                        $index_cache[$ds] = [];
                        $csv = dwbible_data_dir() . $ds . '/html/index.csv';
                        if (file_exists($csv) && ($fh = fopen($csv, 'r')) !== false) {
                            fgetcsv($fh);
                            while (($row = fgetcsv($fh)) !== false) {
                                if (!is_array($row) || count($row) < 2) continue;
                                $index_cache[$ds][] = DwBible_Plugin::slugify($row[1]);
                            }
                            fclose($fh);
                        }
                    }
                    foreach ($index_cache[$ds] as $slug) {
                        $examined++;
                        $result = self::canonical_book_slug_from_url($slug, $combo);
                        if ($result === null) {
                            $failures[] = "'$slug' via /$combo/ (from $ds index)";
                            if (count($failures) >= 10) break 3;
                        }
                    }
                }
            }

            if (!empty($failures)) {
                return new WP_Error('dwbible_selftest_resolution', 'Unresolvable: ' . implode('; ', $failures));
            }
            // REFUSE TO REPORT CLEAN HAVING LOOKED AT NOTHING. Every `continue`
            // above is a silent skip — a missing index.csv, an unreadable file,
            // a slug option that yields no combos — and with all of them taken
            // this check returns true in exactly the state it exists to catch.
            // That is the defect dwbible#15 was filed about, one level down.
            if ($examined === 0) {
                return new WP_Error('dwbible_selftest_examined_nothing',
                    'Resolved 0 book/combo pairs — this check verified NOTHING. ' .
                    'Datasets: ' . implode(',', self::datasets()) . '; combos: ' . count(self::combo_slugs()) . '.');
            }
            return true;
        });

        $results[] = self::selftest_check('autolinker_cases', function() {
            if (!method_exists(__CLASS__, 'autolink_content_for_slug')) {
                return new WP_Error('dwbible_selftest_autolink_missing', 'Auto-linker helper missing (autolink_content_for_slug).');
            }

            $cases = [
                [
                    'name' => 'en_basic_single',
                    'slug' => 'bible',
                    'in' => 'See John 3:16.',
                    'must_contain' => ['href="', '>John 3:16</a>'],
                    'must_not_contain' => [],
                ],
                [
                    // An abbreviation is deliberately NOT linked in prose —
                    // see the "FULL BOOK NAMES ONLY" note in the autolinker.
                    'name' => 'en_abbrev_not_linked',
                    'slug' => 'bible',
                    'in' => 'Gen 1:1',
                    'must_contain' => ['Gen 1:1'],
                    'must_not_contain' => ['href="'],
                ],
                [
                    'name' => 'en_numeric_prefix',
                    'slug' => 'bible',
                    'in' => '1 Kings 2:3',
                    'must_contain' => ['>1 Kings 2:3</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'de_basic_single',
                    'slug' => 'bibel',
                    'in' => 'Siehe Matthäus 5:27.',
                    'must_contain' => ['href="', '>Matthäus 5:27</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'de_numeric_prefix_dot',
                    'slug' => 'bibel',
                    'in' => '1. Mose 1:1',
                    'must_contain' => ['>1. Mose 1:1</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'de_numeric_prefix_no_dot',
                    'slug' => 'bibel',
                    'in' => '1 Mose 1:1',
                    'must_contain' => ['>1 Mose 1:1</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'unicode_nbsp',
                    'slug' => 'bibel',
                    'in' => "Matthäus\xC2\xA05:27",
                    'must_contain' => ['>Matthäus 5:27</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'unicode_colon_ratio',
                    'slug' => 'bibel',
                    'in' => "Matthäus 5\xE2\x88\xB6 27",
                    'must_contain' => ['>Matthäus 5:27</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'unicode_colon_small',
                    'slug' => 'bibel',
                    'in' => "Matthäus 5\xEF\xB9\x95 27",
                    'must_contain' => ['>Matthäus 5:27</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'unicode_colon_fullwidth',
                    'slug' => 'bibel',
                    'in' => "Matthäus 5\xEF\xBC\x9A27",
                    'must_contain' => ['>Matthäus 5:27</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'range_dash',
                    'slug' => 'bible',
                    'in' => 'Romans 8:1-2',
                    'must_contain' => ['>Romans 8:1-2</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'multiple_refs',
                    'slug' => 'bible',
                    'in' => 'Genesis 1:1 and Exodus 3:14',
                    'must_contain' => ['>Genesis 1:1</a>', '>Exodus 3:14</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'dont_link_inside_anchor',
                    'slug' => 'bible',
                    'in' => '<a href="https://example.com">John 3:16</a> and John 3:16',
                    'must_contain' => ['<a href="https://example.com">John 3:16</a>', '>John 3:16</a>'],
                    'must_not_contain' => ['<a href="https://example.com"><a '],
                ],
                [
                    'name' => 'dont_link_inside_anchor_nested_markup',
                    'slug' => 'bible',
                    'in' => '<a href="https://example.com"><span>John 3:16</span></a> and John 3:16',
                    'must_contain' => ['<a href="https://example.com"><span>John 3:16</span></a>', '>John 3:16</a>'],
                    'must_not_contain' => ['<a href="https://example.com"><span><a '],
                ],
                [
                    'name' => 'dont_link_midword',
                    'slug' => 'bible',
                    'in' => 'NotAJohn 3:16 should not link.',
                    'must_contain' => ['NotAJohn 3:16 should not link.'],
                    'must_not_contain' => ['href="'],
                ],
                [
                    'name' => 'dont_link_invalid_chapter',
                    'slug' => 'bible',
                    'in' => 'Gen 0:1 should not link.',
                    'must_contain' => ['Gen 0:1 should not link.'],
                    'must_not_contain' => ['href="'],
                ],
                [
                    'name' => 'dont_link_invalid_verse',
                    'slug' => 'bible',
                    'in' => 'Gen 1:0 should not link.',
                    'must_contain' => ['Gen 1:0 should not link.'],
                    'must_not_contain' => ['href="'],
                ],
                [
                    'name' => 'range_endash',
                    'slug' => 'bible',
                    'in' => "John 6:5\xE2\x80\x937",
                    'must_contain' => ['>John 6:5-7</a>'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'de_comma_verse',
                    'slug' => 'bibel',
                    'in' => 'Johannes 6,5',
                    // The LINK TEXT stays the reader's own language; the HREF is
                    // the canonical Latin section under the dataset's language
                    // prefix. These two being different is the whole point of
                    // the URL shape, so both are asserted. The German COMMA
                    // verse separator is what this case exists for.
                    'must_contain' => ['>Johannes 6:5</a>', '/de/biblia/ioannes/6:5'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'en_comma_list_not_verse',
                    'slug' => 'bible',
                    'in' => 'Genesis 1, 2 here',
                    'must_contain' => ['>Genesis 1</a>'],
                    'must_not_contain' => ['genesis/1:2'],
                ],
                [
                    'name' => 'es_book_name',
                    'slug' => 'spanish',
                    'in' => 'Juan 3:16',
                    'must_contain' => ['>Juan 3:16</a>', '/es/biblia/ioannes/3:16'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'fr_book_name',
                    'slug' => 'french',
                    'in' => 'Jean 3:16',
                    'must_contain' => ['>Jean 3:16</a>', '/fr/biblia/ioannes/3:16'],
                    'must_not_contain' => [],
                ],
                [
                    'name' => 'it_book_name',
                    'slug' => 'italian',
                    'in' => 'Giovanni 3:16',
                    'must_contain' => ['>Giovanni 3:16</a>', '/it/biblia/ioannes/3:16'],
                    'must_not_contain' => [],
                ],
                [
                    // `Io` is an abbreviation and is deliberately NOT linked;
                    // the Latin NAME is. And the href lands under /en/, not
                    // /la/: Latin has no web locale by design — every page is
                    // Latin PLUS a vernacular, so there is no Latin-only
                    // reading surface to point at (see dwbible#3).
                    'name' => 'la_name',
                    'slug' => 'latin',
                    'in' => 'Ioannes 1:1',
                    'must_contain' => ['>Ioannes 1:1</a>', '/biblia/ioannes/1:1'],
                    'must_not_contain' => [],
                ],
                [
                    // A cited psalm is SINGULAR in every language — "Psalm 23",
                    // not "Psalms 23" — while the book itself is plural. Both
                    // forms are names, so both link; this pins the singular,
                    // because it is the one the content is written in and the
                    // one an expansion pass would naturally get wrong.
                    'name' => 'psalm_singular_links',
                    'slug' => 'bible',
                    'in' => 'Psalm 23:1',
                    'must_contain' => ['>Psalm 23:1</a>'],
                    'must_not_contain' => [],
                ],
                [
                    // The three false positives that made widening this map
                    // unsafe, measured on the real corpus 2026-08-31. German
                    // for "on the 30th" and "it", plus a hex colour. None may
                    // ever become a Bible link again.
                    'name' => 'german_prose_is_not_a_reference',
                    'slug' => 'bibel',
                    'in' => 'Am 30.7.2021 habe ich einen Essay geschrieben, und es 3 Tage dauerte; ba 61414.',
                    'must_contain' => ['Am 30.7.2021'],
                    'must_not_contain' => ['href="'],
                ],
            ];

            // ── Only test datasets the autolinker was actually given ──────
            //
            // The unified abbreviation map is built from `dwbible_slugs`, which
            // has never been set on either site — so the default, `bible,bibel`,
            // is what is live, and Spanish, French, Italian and Latin references
            // are not recognised at all. Their cases above are therefore not
            // failures of the linker; they describe a dataset it was never
            // handed.
            //
            // Asserting them anyway made this whole check red for so long that
            // nobody read it, which cost far more than the gap itself: the two
            // real findings underneath (the stale URL shape, and book_map) sat
            // invisible behind it. So the check tests the contract that EXISTS
            // — every configured dataset links correctly — and reports the rest
            // as skipped rather than pretending they passed.
            $configured = get_option('dwbible_slugs', 'bible,bibel');
            $configured = is_string($configured) && $configured !== '' ? $configured : 'bible,bibel';
            $configured = array_values(array_filter(array_map('trim', explode(',', $configured))));

            $failures = [];
            $skipped   = [];
            foreach ($cases as $case) {
                $name = is_string($case['name'] ?? null) ? $case['name'] : '';
                $slug = is_string($case['slug'] ?? null) ? $case['slug'] : '';
                $in = is_string($case['in'] ?? null) ? $case['in'] : '';
                if ($slug !== '' && !in_array($slug, $configured, true)) {
                    $skipped[] = $name . ' (' . $slug . ')';
                    continue;
                }
                $out = self::autolink_content_for_slug($in, $slug);
                if (!is_string($out)) {
                    $failures[] = ['case' => $name, 'reason' => 'output_not_string'];
                    continue;
                }

                foreach (($case['must_contain'] ?? []) as $needle) {
                    if (!is_string($needle) || $needle === '') continue;
                    if (strpos($out, $needle) === false) {
                        $failures[] = ['case' => $name, 'reason' => 'missing_substring', 'needle' => $needle];
                    }
                }
                foreach (($case['must_not_contain'] ?? []) as $needle) {
                    if (!is_string($needle) || $needle === '') continue;
                    if (strpos($out, $needle) !== false) {
                        $failures[] = ['case' => $name, 'reason' => 'forbidden_substring', 'needle' => $needle];
                    }
                }
            }

            if (!empty($failures)) {
                return new WP_Error('dwbible_selftest_autolink_failed', wp_json_encode($failures));
            }
            if (!empty($skipped)) {
                return 'not linked because their dataset is not in dwbible_slugs ('
                     . implode(', ', $configured) . '): ' . implode(', ', $skipped);
            }
            return true;
        });

        $ok = true;
        foreach ($results as $r) {
            if (!is_array($r) || empty($r['ok'])) {
                $ok = false;
                break;
            }
        }

        $payload = [
            'ok' => $ok,
            'timestamp' => gmdate('c'),
            'plugin' => [
                'version' => defined('DWBIBLE_VERSION') ? DWBIBLE_VERSION : null,
            ],
            'checks' => $results,
        ];

        if ($ok) {
            status_header(200);
        } else {
            status_header(500);
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($payload);
        exit;
    }

    private static function selftest_check($name, $fn) {
        $name = is_string($name) ? $name : '';
        try {
            $res = is_callable($fn) ? $fn() : new WP_Error('dwbible_selftest_not_callable', 'Selftest function not callable.');
            if (is_wp_error($res)) {
                return [
                    'name' => $name,
                    'ok' => false,
                    'error' => [
                        'code' => $res->get_error_code(),
                        'message' => $res->get_error_message(),
                    ],
                ];
            }
            // A check may pass and still have something to say — "these four
            // cases were skipped because the site is not configured for them".
            // Returning the note as a string keeps that visible in the JSON
            // instead of leaving it to a silent `true`, which is how the
            // Spanish/French/Italian/Latin gap stayed invisible for months.
            if (is_string($res) && $res !== '') {
                return [
                    'name' => $name,
                    'ok'   => true,
                    'note' => $res,
                ];
            }
            if ($res !== true) {
                return [
                    'name' => $name,
                    'ok' => false,
                    'error' => [
                        'code' => 'dwbible_selftest_failed',
                        'message' => 'Check failed.',
                    ],
                ];
            }
            return [
                'name' => $name,
                'ok' => true,
            ];
        } catch (Throwable $e) {
            return [
                'name' => $name,
                'ok' => false,
                'error' => [
                    'code' => 'dwbible_selftest_exception',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }
}
