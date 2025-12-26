<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait TheBible_Router_Trait {
    public static function handle_request() {
        // Main request router; will be refactored later.
        // Serve Open Graph image when requested
        $og = get_query_var(self::QV_OG);
        if ($og) {
            TheBible_OG_Image::render();
            exit;
        }
        $book = get_query_var(self::QV_BOOK);
        if ($book) {
            self::render_bible_page();
            return;
        }
        $sitemap = get_query_var(self::QV_SITEMAP);
        if ($sitemap) {
            self::handle_sitemap();
            return;
        }
        $flag = get_query_var(self::QV_FLAG);
        if ($flag) {
            self::render_index();
            return;
        }
    }

    public static function render_bible_page() {
        $book_slug = get_query_var(self::QV_BOOK);
        if (!$book_slug) {
            self::render_index();
            return;
        }

        // Resolve canonical book slug for the current language dataset
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        set_query_var(self::QV_SLUG, $slug);

        // Canonicalize book slug based on the first dataset in the slug (e.g. latin-bible => latin)
        $canon_dataset = $slug;
        if (is_string($canon_dataset) && strpos($canon_dataset, '-') !== false) {
            $parts = array_values(array_filter(array_map('trim', explode('-', $canon_dataset))));
            if (!empty($parts)) {
                $canon_dataset = $parts[0];
            }
        }

        $canonical = self::canonical_book_slug_from_url($book_slug, $canon_dataset);
        if (!$canonical) {
            self::render_404();
            exit;
        }

        // If the URL slug differs from the canonical one, redirect
        if ($canonical !== $book_slug) {
            $ch = get_query_var(self::QV_CHAPTER);
            $vf = get_query_var(self::QV_VFROM);
            $vt = get_query_var(self::QV_VTO);

            $path = '/' . trim($slug, '/') . '/' . $canonical . '/';
            if ($ch) {
                $path .= $ch;
                if ($vf) {
                    $path .= ':' . $vf;
                    if ($vt && $vt > $vf) {
                        $path .= '-' . $vt;
                    }
                }
            }

            $canonical_url = home_url($path);
            $current = home_url(add_query_arg([]));
            if (trailingslashit($canonical_url) !== trailingslashit($current)) {
                wp_redirect($canonical_url, 301);
                exit;
            }
            $book_slug = $canonical;
            set_query_var(self::QV_BOOK, $book_slug);
        }

        // Always use multilingual renderer (1 dataset is the special case)
        self::render_multilingual_book($book_slug, $slug);
        exit; // prevent WP from continuing
    }

    private static function canonical_book_slug_from_url($raw_book, $slug) {
        if (!is_string($raw_book) || $raw_book === '') return null;
        if ($slug === 'latin-bible') {
            $slug = 'bible';
        }
        if ($slug !== 'bible' && $slug !== 'bibel' && $slug !== 'latin') {
            $slug = 'bible';
        }

        // Always allow direct slugs that exist in the dataset index.
        // This is important for datasets where certain books exist in index.csv
        // but are not present in the abbreviations map (e.g. German standalone additions).
        $prev_slug = get_query_var(self::QV_SLUG);
        set_query_var(self::QV_SLUG, $slug);
        self::load_index();
        $direct = self::slugify($raw_book);
        if ($direct !== '' && isset(self::$slug_map[$direct])) {
            set_query_var(self::QV_SLUG, $prev_slug);
            return $direct;
        }
        set_query_var(self::QV_SLUG, $prev_slug);

        $abbr = self::get_abbreviation_map($slug);
        if (empty($abbr)) {
            // Some datasets (e.g. latin) may not ship an abbreviations map.
            // In that case, accept direct book slugs if they exist in the index.
            self::load_index();
            $direct = self::slugify($raw_book);
            if ($direct !== '' && isset(self::$slug_map[$direct])) {
                return $direct;
            }
            return null;
        }

        $book = str_replace('-', ' ', $raw_book);
        $book = urldecode($book);
        $norm = preg_replace('/\.\s*$/u', '', $book);
        $norm = preg_replace('/\s+/u', ' ', trim((string)$norm));
        $key = mb_strtolower($norm, 'UTF-8');

        $short = null;
        if ($key !== '' && isset($abbr[$key])) {
            $short = $abbr[$key];
        } else {
            $alt = preg_replace('/^(\d+)\.\s*/u', '$1 ', $norm);
            $alt = preg_replace('/\s+/u', ' ', trim((string)$alt));
            $alt_key = mb_strtolower($alt, 'UTF-8');
            if ($alt_key !== '' && isset($abbr[$alt_key])) {
                $short = $abbr[$alt_key];
            }
        }

        if ($short === null) return null;
        $book_slug = self::slugify($short);
        return $book_slug !== '' ? $book_slug : null;
    }
}
