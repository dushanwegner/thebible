<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait TheBible_Interlinear_Trait {
    /**
     * Build unified content.
     * Single-language is treated as "interlinear without a secondary language".
     */
    private static function build_hybrid_content_impl($canonical_key, $chapter, $primary_lang, $secondary_lang = null, $book_slug_for_ids = '') {
        $primary_content = self::extract_chapter_content($canonical_key, $chapter, $primary_lang);
        if (!$primary_content) {
            return '';
        }

        $primary_verses = self::extract_verses_from_html($primary_content);
        if (!is_array($primary_verses) || empty($primary_verses)) {
            return '';
        }

        $secondary_verses = [];
        if (is_string($secondary_lang) && $secondary_lang !== '' && $secondary_lang !== $primary_lang) {
            $secondary_content = self::extract_chapter_content($canonical_key, $chapter, $secondary_lang);
            if ($secondary_content) {
                $secondary_verses = self::extract_verses_from_html($secondary_content);
                if (!is_array($secondary_verses)) { $secondary_verses = []; }
            }
        }

        $lang_labels = [
            'bible' => 'EN',
            'bibel' => 'DE',
            'latin' => 'LA',
        ];
        $primary_label = $lang_labels[$primary_lang] ?? strtoupper((string)$primary_lang);
        $secondary_label = (is_string($secondary_lang) && $secondary_lang !== '')
            ? ($lang_labels[$secondary_lang] ?? strtoupper((string)$secondary_lang))
            : '';

        if (!is_string($book_slug_for_ids) || $book_slug_for_ids === '') {
            // IDs and navigation must be stable across languages and match the URL slug.
            // So we always base them on the canonical key (e.g. "matthew", "2-machabees").
            $book_slug_for_ids = self::slugify($canonical_key);
        }

        // Build highlight/scroll targets from URL like /book/20:2-4 or /book/20
        $targets = [];
        $vf = absint( get_query_var( self::QV_VFROM ) );
        $vt = absint( get_query_var( self::QV_VTO ) );
        if ( $vf ) {
            if ( ! $vt || $vt < $vf ) { $vt = $vf; }
            for ( $i = $vf; $i <= $vt; $i++ ) {
                $targets[] = $book_slug_for_ids . '-' . $chapter . '-' . $i;
            }
        }
        $chapter_scroll_id = $book_slug_for_ids . '-ch-' . $chapter;

        $human = self::resolve_book_for_dataset($canonical_key, $primary_lang);
        if (!is_string($human) || $human === '') { $human = $canonical_key; }

        $entry = self::resolve_index_entry_for_canonical_book($book_slug_for_ids, $primary_lang);
        $nav = [
            'book' => $book_slug_for_ids,
            'chapter' => (int)$chapter,
            'max_ch' => 0,
            'prev_book' => '',
            'prev_max_ch' => 0,
            'next_book' => '',
            'next_max_ch' => 0,
        ];
        if (is_array($entry) && isset($entry['order'])) {
            self::load_index();
            $idx = ((int)$entry['order']) - 1;
            $count = is_array(self::$books) ? count(self::$books) : 0;
            if ($count > 0 && $idx >= 0 && $idx < $count) {
                $prev_idx = ($idx - 1 + $count) % $count;
                $next_idx = ($idx + 1) % $count;
                $prev_entry = self::$books[$prev_idx] ?? null;
                $next_entry = self::$books[$next_idx] ?? null;
                if (is_array($prev_entry) && isset($prev_entry['short_name'])) {
                    $prev_canon = self::canonical_key_for_dataset_short_name($primary_lang, (string)$prev_entry['short_name']);
                    $nav['prev_book'] = $prev_canon !== '' ? self::slugify($prev_canon) : self::slugify((string)$prev_entry['short_name']);
                }
                if (is_array($next_entry) && isset($next_entry['short_name'])) {
                    $next_canon = self::canonical_key_for_dataset_short_name($primary_lang, (string)$next_entry['short_name']);
                    $nav['next_book'] = $next_canon !== '' ? self::slugify($next_canon) : self::slugify((string)$next_entry['short_name']);
                }
            }
        }

        $nav['max_ch'] = (int) self::max_chapter_for_book_slug($book_slug_for_ids, $primary_lang);
        if (is_string($nav['prev_book']) && $nav['prev_book'] !== '') {
            $nav['prev_max_ch'] = (int) self::max_chapter_for_book_slug($nav['prev_book'], $primary_lang);
        }
        if (is_string($nav['next_book']) && $nav['next_book'] !== '') {
            $nav['next_max_ch'] = (int) self::max_chapter_for_book_slug($nav['next_book'], $primary_lang);
        }

        $out = '';
        $out .= '<a id="' . esc_attr($chapter_scroll_id) . '"></a>';

        foreach ($primary_verses as $verse_num => $primary_text) {
            $verse_num = (int) $verse_num;
            if ($verse_num <= 0) { continue; }
            $id = $book_slug_for_ids . '-' . $chapter . '-' . $verse_num;

            $out .= '<p id="' . esc_attr($id) . '"'
                . TheBible_Markup::build_class_attr( [ 'verse', 'thebible-verse', 'thebible-interlinear__verse' ] )
                . TheBible_Markup::build_data_attrs( [
                    'thebible-book' => $book_slug_for_ids,
                    'thebible-ch'   => (string) $chapter,
                    'thebible-v'    => (string) $verse_num,
                ] )
                . '>';
            $out .= '<span class="verse-num thebible-verse__num">' . esc_html($verse_num) . '</span> ';
            $out .= '<span class="verse-body thebible-verse__body thebible-interlinear__line thebible-interlinear__line--primary"><span class="thebible-interlinear-verse-lang thebible-interlinear__lang-label" data-thebible-lang="' . esc_attr($primary_lang) . '">' . esc_html($primary_label) . '</span> ' . esc_html($primary_text) . '</span>';

            if ($secondary_label !== '' && isset($secondary_verses[$verse_num]) && is_string($secondary_verses[$verse_num]) && $secondary_verses[$verse_num] !== '') {
                $out .= '<br><span class="verse-body verse-body-secondary thebible-verse__body thebible-interlinear__line thebible-interlinear__line--secondary"><span class="thebible-interlinear-verse-lang thebible-interlinear__lang-label" data-thebible-lang="' . esc_attr($secondary_lang) . '">' . esc_html($secondary_label) . '</span> ' . esc_html($secondary_verses[$verse_num]) . '</span>';
            }

            $out .= '</p>';
        }

        $out = self::inject_nav_helpers($out, $targets, $chapter_scroll_id, $human, $nav);
        return '<div class="thebible thebible-book thebible-interlinear thebible--mode-interlinear thebible--lang-primary-' . esc_attr($primary_lang) . ($secondary_label !== '' ? (' thebible--lang-secondary-' . esc_attr($secondary_lang)) : '') . '" data-thebible-mode="interlinear" data-thebible-lang-primary="' . esc_attr($primary_lang) . '" data-thebible-lang-secondary="' . esc_attr(is_string($secondary_lang) ? $secondary_lang : '') . '">' . $out . '</div>';
    }
}
