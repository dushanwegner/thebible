<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait TheBible_AutoLink_Trait {
    public static function register_strip_bibleserver_bulk($bulk_actions) {
        if (!is_array($bulk_actions)) return $bulk_actions;
        $bulk_actions['thebible_strip_bibleserver'] = __('Strip BibleServer links', 'thebible');
        $bulk_actions['thebible_set_bible'] = __('Set Bible: English (Douay-Rheims)', 'thebible');
        $bulk_actions['thebible_set_bibel'] = __('Set Bible: Deutsch (Menge)', 'thebible');
        return $bulk_actions;
    }

    private static function strip_bibleserver_links_from_content($content) {
        if (!is_string($content) || $content === '') return $content;
        $pattern_html = '~<a\s+[^>]*href=["\']https?://(?:www\.)?bibleserver\.com/[^"\']*["\'][^>]*>(.*?)</a>~is';
        $content = preg_replace($pattern_html, '$1', $content);

        $pattern_md = '~\[([^\]]+)\]\(\s*https?://(?:www\.)?bibleserver\.com/[^\s\)]+\s*\)~i';
        $content = preg_replace($pattern_md, '$1', $content);

        return $content;
    }

    public static function handle_strip_bibleserver_bulk($redirect_to, $doaction, $post_ids) {
        if (!is_array($post_ids)) {
            return $redirect_to;
        }

        if ($doaction === 'thebible_strip_bibleserver') {
            $count = 0;
            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                if (!$post || $post->post_type === 'revision') continue;
                $old = $post->post_content;
                $new = self::strip_bibleserver_links_from_content($old);
                if ($new !== $old) {
                    wp_update_post([
                        'ID' => $post_id,
                        'post_content' => $new,
                    ]);
                    $count++;
                }
            }
            if ($count > 0) {
                $redirect_to = add_query_arg('thebible_stripped_bibleserver', $count, $redirect_to);
            }
            return $redirect_to;
        }

        if ($doaction === 'thebible_set_bible' || $doaction === 'thebible_set_bibel') {
            $target = ($doaction === 'thebible_set_bibel') ? 'bibel' : 'bible';
            $count = 0;
            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                if (!$post || $post->post_type === 'revision') continue;
                update_post_meta($post_id, 'thebible_slug', $target);
                $count++;
            }
            if ($count > 0) {
                $key = ($target === 'bibel') ? 'thebible_set_bibel' : 'thebible_set_bible';
                $redirect_to = add_query_arg($key, $count, $redirect_to);
            }
            return $redirect_to;
        }

        return $redirect_to;
    }

    public static function filter_content_auto_link_bible_refs($content) {
        if (!is_string($content) || $content === '') return $content;
        if (is_feed() || is_admin()) return $content;

        $post_id = get_the_ID();
        if (!$post_id) return $content;

        $slug = get_post_meta($post_id, 'thebible_slug', true);
        return self::autolink_content_for_slug($content, $slug);
    }

    public static function autolink_content_for_slug($content, $slug) {
        if (!is_string($content) || $content === '') return $content;
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        if ($slug !== 'bible' && $slug !== 'bibel' && $slug !== 'latin') {
            $slug = 'bible';
        }

        $abbr = self::get_abbreviation_map($slug);
        if (empty($abbr)) return $content;

        $pattern = '/(?<!\p{L})('
                 . '(?:[0-9]{1,2}\.?(?:\s|\x{00A0})*)?'
                 . '[\p{L}][\p{L}\p{M}\.]*'
                 . '(?:(?:\s|\x{00A0})+[\p{L}\p{M}\.]+)*'
                 . ')(?:\s|\x{00A0})*(\d+)(?:\s|\x{00A0})*[:\x{2236}\x{FE55}\x{FF1A}](?:\s|\x{00A0})*(\d+)(?:-(\d+))?(?!\p{L})/u';

        $parts = preg_split('/(<a\s[^>]*>.*?<\/a>)/us', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $content;
        }

        $result = '';
        foreach ($parts as $part) {
            if (preg_match('/^<a\s/i', $part)) {
                $result .= $part;
            } else {
                $normalized_part = preg_replace('/&(nbsp|NBSP);/u', "\xC2\xA0", $part);
                if ($normalized_part !== null) {
                    $normalized_part = preg_replace('/&#160;|&#x0*a0;/iu', "\xC2\xA0", $normalized_part);
                    $normalized_part = preg_replace('/&(thinsp|ensp|emsp);/iu', ' ', $normalized_part);
                    $normalized_part = preg_replace('/&#(8194|8195|8201);|&#x(2002|2003|2009);/iu', ' ', $normalized_part);
                }
                if (!is_string($normalized_part)) {
                    $normalized_part = $part;
                }

                $normalized_part = preg_replace('/[\x{202F}\x{2000}-\x{200A}\x{2060}]/u', "\xC2\xA0", $normalized_part);
                $normalized_part = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $normalized_part);
                $result .= preg_replace_callback(
                    $pattern,
                    function ($m) use ($slug, $abbr) {
                        return self::process_bible_ref_match($m, $slug, $abbr);
                    },
                    $normalized_part
                );
            }
        }

        return $result;
    }

    private static function process_bible_ref_match($m, $slug, $abbr) {
        if (!isset($m[1], $m[2], $m[3])) return $m[0];
        $book_raw = $m[1];
        $ch = (int)$m[2];
        $vf = (int)$m[3];
        $vt = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0;
        if ($ch <= 0 || $vf <= 0) return $m[0];

        $book_clean = str_replace("\xC2\xA0", ' ', (string)$book_raw);
        $book_clean = preg_replace('/\.\s*$/u', '', $book_clean);
        $book_clean = preg_replace('/\s+/u', ' ', trim($book_clean));

        $effective_slug = $slug;
        $short = null;
        $resolved_book_text = null;
        $matched_word_start_index = null;

        $words = preg_split('/\s+/u', (string)$book_clean);
        if (is_array($words)) {
            for ($i = 0; $i < count($words); $i++) {
                $candidate = implode(' ', array_slice($words, $i));
                if ($candidate === '') continue;

                $norm = preg_replace('/\s+/u', ' ', trim($candidate));
                $key = mb_strtolower($norm, 'UTF-8');
                if (isset($abbr[$key])) {
                    $short = $abbr[$key];
                    $resolved_book_text = $norm;
                    $matched_word_start_index = $i;
                    break;
                }

                $alt = preg_replace('/^(\d+)\.\s*/u', '$1 ', $norm);
                $alt = preg_replace('/\s+/u', ' ', trim($alt));
                $alt_key = mb_strtolower($alt, 'UTF-8');
                if (isset($abbr[$alt_key])) {
                    $short = $abbr[$alt_key];
                    $resolved_book_text = $alt;
                    $matched_word_start_index = $i;
                    break;
                }

                $other_slug = ($slug === 'bibel') ? 'bible' : 'bibel';
                $abbr_other = self::get_abbreviation_map($other_slug);
                if (isset($abbr_other[$key])) {
                    $short = $abbr_other[$key];
                    $effective_slug = $other_slug;
                    $resolved_book_text = $norm;
                    $matched_word_start_index = $i;
                    break;
                }
                if (isset($abbr_other[$alt_key])) {
                    $short = $abbr_other[$key];
                    $effective_slug = $other_slug;
                    $resolved_book_text = $alt;
                    $matched_word_start_index = $i;
                    break;
                }
            }
        }

        if ($short === null) {
            return $m[0];
        }

        $book_slug = self::slugify($short);
        if ($book_slug === '') return $m[0];

        $base = home_url('/' . trim($effective_slug, '/') . '/' . $book_slug . '/');
        if ($vt && $vt >= $vf) {
            $url = $base . $ch . ':' . $vf . '-' . $vt;
        } else {
            $url = $base . $ch . ':' . $vf;
        }

        $book_display = $resolved_book_text ?: $book_clean;
        $ref_text = $book_display . ' ' . $ch . ':' . $vf . ($vt && $vt >= $vf ? '-' . $vt : '');

        $prefix_raw = '';
        if ($matched_word_start_index !== null && $matched_word_start_index > 0) {
            $raw_tokens = preg_split('/\s+/u', (string)$book_raw, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($raw_tokens)) {
                $book_word_count = count($words) - $matched_word_start_index;
                $prefix_count = max(0, count($raw_tokens) - $book_word_count);
                if ($prefix_count > 0) {
                    $prefix_raw = implode(' ', array_slice($raw_tokens, 0, $prefix_count));
                    if ($prefix_raw !== '') {
                        $prefix_raw .= ' ';
                    }
                }
            }
        }

        return $prefix_raw . '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($ref_text) . '</a>';
    }
}
