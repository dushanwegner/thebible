<?php
/*
* Plugin Name: The Bible
* Description: Provides /bible/ with links to books; renders selected book HTML using the site's template.
* Version: 1.25.12.25.09
* Author: Dushan Wegner
*/

if (!defined('ABSPATH')) exit;

if (!defined('THEBIBLE_VERSION')) {
    define('THEBIBLE_VERSION', '1.25.12.25.09');
}

// Load include classes before hooks are registered
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-votd-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-votd-widget.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-admin-meta.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-og-image.php';

class TheBible_Plugin {
    const QV_FLAG = 'thebible';
    const QV_BOOK = 'thebible_book';
    const QV_CHAPTER = 'thebible_ch';
    const QV_VFROM = 'thebible_vfrom';
    const QV_VTO = 'thebible_vto';
    const QV_SLUG = 'thebible_slug';
    const QV_OG   = 'thebible_og';
    const QV_SITEMAP = 'thebible_sitemap';

    private static $books = null; // array of [order, short_name, filename]
    private static $slug_map = null; // slug => array entry
    private static $abbr_maps = [];
    private static $book_map = null;
    private static $current_page_title = '';
    private static $max_chapters = [];

    public static function init() {
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_action('init', ['TheBible_VOTD_Admin', 'register_votd_cpt']);
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);
        add_action('template_redirect', [__CLASS__, 'handle_request']);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        add_action('add_meta_boxes', ['TheBible_Admin_Meta', 'add_bible_meta_box']);
        add_action('save_post', ['TheBible_Admin_Meta', 'save_bible_meta'], 10, 2);

        add_filter('manage_posts_columns', ['TheBible_Admin_Meta', 'add_bible_column']);
        add_action('manage_posts_custom_column', ['TheBible_Admin_Meta', 'render_bible_column'], 10, 2);

        add_action('widgets_init', [__CLASS__, 'register_widgets']);
        // Admin list enhancements for Verse of the Day CPT
        add_filter('manage_edit-thebible_votd_columns', ['TheBible_VOTD_Admin', 'votd_columns']);
        add_filter('manage_edit-thebible_votd_sortable_columns', ['TheBible_VOTD_Admin', 'votd_sortable_columns']);
        add_action('manage_thebible_votd_posts_custom_column', ['TheBible_VOTD_Admin', 'render_votd_column'], 10, 2);
        add_action('restrict_manage_posts', ['TheBible_VOTD_Admin', 'votd_date_filter']);
        add_action('pre_get_posts', ['TheBible_VOTD_Admin', 'apply_votd_date_filter']);
        add_action('admin_notices', ['TheBible_VOTD_Admin', 'votd_condense_notice']);
        add_action('load-edit.php', ['TheBible_VOTD_Admin', 'handle_votd_condense_request']);
        add_filter('bulk_actions-edit-thebible_votd', ['TheBible_VOTD_Admin', 'votd_register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-thebible_votd', ['TheBible_VOTD_Admin', 'votd_handle_bulk_actions'], 10, 3);

        add_action('add_meta_boxes', ['TheBible_VOTD_Admin', 'add_votd_meta_box']);
        add_action('save_post', ['TheBible_VOTD_Admin', 'save_votd_meta'], 10, 2);

        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    public static function add_settings_page() {
        self::admin_menu();
    }

    public static function enqueue_admin_assets($hook) {
        self::admin_enqueue($hook);
    }

    private static function ordered_book_slugs() {
        self::load_index();
        $out = [];
        if (!is_array(self::$books) || empty(self::$books)) {
            return $out;
        }
        $books = self::$books;
        usort($books, function($a, $b) {
            $ao = isset($a['order']) ? intval($a['order']) : 0;
            $bo = isset($b['order']) ? intval($b['order']) : 0;
            return $ao <=> $bo;
        });
        foreach ($books as $entry) {
            if (!is_array($entry) || empty($entry['short_name'])) continue;
            $slug = self::slugify($entry['short_name']);
            if ($slug === '') continue;
            $out[] = $slug;
        }
        return array_values(array_unique($out));
    }

    private static function max_chapter_for_book_slug($book_slug) {
        $book_slug = self::slugify($book_slug);
        if ($book_slug === '') return 0;
        if (isset(self::$max_chapters[$book_slug])) {
            return intval(self::$max_chapters[$book_slug]);
        }
        self::load_index();
        $entry = self::$slug_map[$book_slug] ?? null;
        if (!is_array($entry) || empty($entry['filename'])) {
            self::$max_chapters[$book_slug] = 0;
            return 0;
        }
        $file = self::html_dir() . $entry['filename'];
        if (!is_string($file) || $file === '' || !file_exists($file)) {
            self::$max_chapters[$book_slug] = 0;
            return 0;
        }
        $html = (string) @file_get_contents($file);
        if ($html === '') {
            self::$max_chapters[$book_slug] = 0;
            return 0;
        }
        $max = 0;
        if (preg_match_all('/\bid="' . preg_quote($book_slug, '/') . '-ch-(\d+)"/i', $html, $m)) {
            foreach ($m[1] as $num) {
                $n = intval($num);
                if ($n > $max) $max = $n;
            }
        }
        if ($max <= 0 && preg_match_all('/\bid="' . preg_quote($book_slug, '/') . '-(\d+)-(\d+)"/i', $html, $m2)) {
            foreach ($m2[1] as $num) {
                $n = intval($num);
                if ($n > $max) $max = $n;
            }
        }
        self::$max_chapters[$book_slug] = $max;
        return $max;
    }

    private static function u_strlen($s) {
        if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8');
        $arr = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($arr) ? count($arr) : strlen((string)$s);
    }

    private static function u_substr($s, $start, $len = null) {
        if (function_exists('mb_substr')) return $len === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $len, 'UTF-8');
        $arr = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($arr)) return '';
        $slice = array_slice($arr, $start, $len === null ? null : $len);
        return implode('', $slice);
    }

    private static function inject_nav_helpers($html, $highlight_ids = [], $chapter_scroll_id = null, $book_label = '', $nav = null) {
        if (!is_string($html) || $html === '') return $html;

        // Ensure a stable anchor at the very top of the book content
        if (strpos($html, 'id="thebible-book-top"') === false && strpos($html, 'id=\"thebible-book-top\"') === false) {
            $html = '<a id="thebible-book-top"></a>' . $html;
        }

        // Prepend an up-arrow to the first chapters block linking back to the current Bible index
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        $bible_index = esc_url(trailingslashit(home_url('/' . $slug . '/')));
        $aria_label = ($slug === 'bibel') ? __('Back to German Bible', 'thebible') : __('Back to Bible', 'thebible');
        $chap_up = '<a class="thebible-up thebible-up-index" href="' . $bible_index . '" aria-label="' . esc_attr($aria_label) . '">&#8593;</a> ';
        $html = preg_replace(
            '~<p\s+class=(["\"])chapters\1>~',
            '<p class="chapters">' . $chap_up,
            $html,
            1
        );

        // Prepend an up-arrow to verses blocks linking back to top of book, but skip the first (Chapter 1)
        $book_top = '#thebible-book-top';
        $vers_up = '<a class="thebible-up thebible-up-book" href="' . $book_top . '" aria-label="Back to book">&#8593;</a> ';
        $count = 0;
        $html = preg_replace_callback(
            '~<p\s+class=(["\"])verses\1>~',
            function($m) use (&$count, $vers_up) {
                $count++;
                if ($count === 1) {
                    // First verses list (chapter 1): no up-arrow
                    return $m[0];
                }
                return '<p class="verses">' . $vers_up;
            },
            $html
        );

        // Ensure each verse paragraph has a class for styling (IDs use slug-ch-verse)
        $html = preg_replace(
            '~<p\s+id=(["\"])([a-z0-9\-]+-\d+-\d+)\1>~i',
            '<p id="$2" class="verse">',
            $html
        );

        // Add sticky status bar at top (book + current chapter)
        $book_label = is_string($book_label) ? self::pretty_label($book_label) : '';
        $book_slug_js = esc_js( self::slugify( $book_label ) );
        $book_label_html = esc_html( $book_label );

        $prev_href = '#';
        $next_href = '#';
        $top_href = $bible_index;
        if (is_array($nav)) {
            $nav_book = $nav['book'] ?? '';
            $nav_ch = isset($nav['chapter']) ? absint($nav['chapter']) : 0;
            if (is_string($nav_book) && $nav_book !== '' && $nav_ch > 0) {
                $slug_for_urls = get_query_var(self::QV_SLUG);
                if (!is_string($slug_for_urls) || $slug_for_urls === '') { $slug_for_urls = 'bible'; }
                $ordered = self::ordered_book_slugs();
                $idx = array_search($nav_book, $ordered, true);
                if ($idx !== false && !empty($ordered)) {
                    $count_books = count($ordered);
                    $max_ch = self::max_chapter_for_book_slug($nav_book);
                    if ($nav_ch > 1) {
                        $prev_book = $nav_book;
                        $prev_ch = $nav_ch - 1;
                    } else {
                        $prev_book = $ordered[($idx - 1 + $count_books) % $count_books];
                        $prev_ch = self::max_chapter_for_book_slug($prev_book);
                        if ($prev_ch <= 0) { $prev_ch = 1; }
                    }
                    if ($max_ch > 0 && $nav_ch < $max_ch) {
                        $next_book = $nav_book;
                        $next_ch = $nav_ch + 1;
                    } else {
                        $next_book = $ordered[($idx + 1) % $count_books];
                        $next_ch = 1;
                    }

                    $prev_href = esc_url(trailingslashit(home_url('/' . trim($slug_for_urls, '/') . '/' . $prev_book . '/' . $prev_ch)));
                    $next_href = esc_url(trailingslashit(home_url('/' . trim($slug_for_urls, '/') . '/' . $next_book . '/' . $next_ch)));
                }
            }
        }

        // Prepare data attributes for frontend JS (highlight targets / chapter scroll)
        $data_attrs = '';
        if ( is_array( $highlight_ids ) && ! empty( $highlight_ids ) ) {
            $ids_json = wp_json_encode( array_values( array_unique( $highlight_ids ) ) );
            $data_attrs .= ' data-highlight-ids=' . "'" . esc_attr( $ids_json ) . "'";
        } elseif ( is_string( $chapter_scroll_id ) && $chapter_scroll_id !== '' ) {
            $data_attrs .= ' data-chapter-scroll-id="' . esc_attr( $chapter_scroll_id ) . '"';
        }

        $sticky = '<div class="thebible-sticky" data-slug="' . $book_slug_js . '"' . $data_attrs . '>'
                . '<div class="thebible-sticky__left">'
                . '<span class="thebible-sticky__label" data-label>' . $book_label_html . '</span> '
                . '<span class="thebible-sticky__sep">—</span> '
                . '<span class="thebible-sticky__chapter" data-ch>1</span>'
                . '</div>'
                . '<div class="thebible-sticky__controls">'
                . '<a href="' . $prev_href . '" class="thebible-ctl thebible-ctl-prev" data-prev aria-label="Previous chapter">&#8592;</a>'
                . '<a href="' . $top_href . '" class="thebible-ctl thebible-ctl-top" data-top aria-label="Bible index">&#8593;</a>'
                . '<a href="' . $next_href . '" class="thebible-ctl thebible-ctl-next" data-next aria-label="Next chapter">&#8594;</a>'
                . '</div>'
                . '</div>';
        $html = $sticky . $html;

        return $html;
    }

    public static function activate() {
        self::add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
        // Clean up legacy options no longer used by the plugin.
        delete_option( 'thebible_custom_css' );
        delete_option( 'thebible_prod_domain' );
    }

    public static function add_rewrite_rules() {
        $slugs = self::base_slugs();
        foreach ($slugs as $slug) {
            $slug = trim($slug, "/ ");
            if ($slug === '') continue;
            // index
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/?$', 'index.php?' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}/{chapter}:{verse} or {chapter}:{from}-{to}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/([0-9]+):([0-9]+)(?:-([0-9]+))?/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}/{chapter}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/([0-9]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
        }
        // Sitemaps: English, German, and Latin (use unique endpoints to avoid conflicts with other sitemap plugins)
        add_rewrite_rule('^bible-sitemap-bible\.xml$', 'index.php?' . self::QV_SITEMAP . '=bible&' . self::QV_SLUG . '=bible', 'top');
        add_rewrite_rule('^bible-sitemap-bibel\.xml$', 'index.php?' . self::QV_SITEMAP . '=bibel&' . self::QV_SLUG . '=bibel', 'top');
        add_rewrite_rule('^bible-sitemap-latin\.xml$', 'index.php?' . self::QV_SITEMAP . '=latin&' . self::QV_SLUG . '=latin', 'top');
    }

    public static function enqueue_assets() {
        // Enqueue styles and scripts only on plugin routes
        $is_bible = ! empty( get_query_var( self::QV_FLAG ) )
            || ! empty( get_query_var( self::QV_BOOK ) )
            || ! empty( get_query_var( self::QV_SLUG ) );
        if ( $is_bible ) {
            $css_url = plugins_url( 'assets/thebible.css', __FILE__ );
            wp_enqueue_style( 'thebible-styles', $css_url, [], '0.1.3' );

            // Enqueue theme script first (in the head) to prevent flash of unstyled content
            $theme_js_url = plugins_url( 'assets/thebible-theme.js', __FILE__ );
            wp_enqueue_script( 'thebible-theme', $theme_js_url, [], '0.1.0', false );
            
            // Main frontend script in the footer
            $js_url = plugins_url( 'assets/thebible-frontend.js', __FILE__ );
            wp_enqueue_script( 'thebible-frontend', $js_url, [], '0.1.0', true );
        }
    }

    public static function add_query_vars($vars) {
        $vars[] = self::QV_FLAG;
        $vars[] = self::QV_BOOK;
        $vars[] = self::QV_CHAPTER;
        $vars[] = self::QV_VFROM;
        $vars[] = self::QV_VTO;
        $vars[] = self::QV_SLUG;
        $vars[] = self::QV_OG;
        $vars[] = self::QV_SITEMAP;
        return $vars;
    }

    private static function data_root_dir() {
        // New structure: data/{slug}/ with html/ and text/ subfolders
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        $root = plugin_dir_path(__FILE__) . 'data/' . $slug . '/';
        if (is_dir($root)) return $root;
        return null;
    }

    private static function html_dir() {
        $root = self::data_root_dir();
        if ($root) {
            $h = trailingslashit($root) . 'html/';
            if (is_dir($h)) return $h;
        }
        // Back-compat: old layout data/{slug}_books_html/
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        $old = plugin_dir_path(__FILE__) . 'data/' . $slug . '_books_html/';
        if (is_dir($old)) return $old;
        // Fallback to default English
        $fallback = plugin_dir_path(__FILE__) . 'data/bible_books_html/';
        return $fallback;
    }

    private static function index_csv_path() {
        return self::html_dir() . 'index.csv';
    }

    private static function load_index() {
        if (self::$books !== null) return;
        self::$books = [];
        self::$slug_map = [];
        $csv = self::index_csv_path();
        if (!file_exists($csv)) return;
        if (($fh = fopen($csv, 'r')) !== false) {
            // skip header
            $header = fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 3) continue;
                $order = intval($row[0]);
                $short = $row[1];
                $display = '';
                $filename = '';
                // New format: order, short_name, display_name, filename, ...
                if (count($row) >= 4) {
                    $display = isset($row[2]) ? $row[2] : '';
                    $filename = isset($row[3]) ? $row[3] : (isset($row[2]) ? $row[2] : '');
                } else {
                    // Old format: order, short_name, filename, ...
                    $filename = $row[2];
                }
                $entry = [
                    'order' => $order,
                    'short_name' => $short,
                    'display_name' => $display,
                    'filename' => $filename,
                ];
                self::$books[] = $entry;
                $slug = self::slugify($short);
                self::$slug_map[$slug] = $entry;
            }
            fclose($fh);
        }
    }

    public static function slugify($name) {
        $slug = strtolower($name);
        $slug = str_replace([' ', '__'], ['-', '-'], $slug);
        $slug = str_replace(['_', '\\', '/'], ['-', '-', '-'], $slug);
        $slug = preg_replace('/[^a-z0-9\-]+/', '', $slug);
        $slug = preg_replace('/\-+/', '-', $slug);
        return trim($slug, '-');
    }

    private static function load_book_map() {
        if (self::$book_map !== null) {
            return;
        }
        $file = plugin_dir_path(__FILE__) . 'data/book_map.json';
        $map = [];
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $map = $data;
                }
            }
        }
        self::$book_map = $map;
    }

    public static function resolve_book_for_dataset($canonical_key, $dataset_slug) {
        if (!is_string($canonical_key) || $canonical_key === '') {
            return null;
        }
        if (!is_string($dataset_slug) || $dataset_slug === '') {
            return null;
        }
        self::load_book_map();
        if (!is_array(self::$book_map) || empty(self::$book_map)) {
            return null;
        }
        $key = strtolower($canonical_key);
        if (!isset(self::$book_map[$key]) || !is_array(self::$book_map[$key])) {
            return null;
        }
        $entry = self::$book_map[$key];
        if (!isset($entry[$dataset_slug]) || !is_string($entry[$dataset_slug]) || $entry[$dataset_slug] === '') {
            return null;
        }
        return $entry[$dataset_slug];
    }

    public static function list_canonical_books() {
        self::load_book_map();
        if (!is_array(self::$book_map) || empty(self::$book_map)) {
            return [];
        }
        $out = [];
        foreach (self::$book_map as $key => $val) {
            if (!is_string($key) || $key === '') continue;
            $out[] = $key;
        }
        sort($out);
        return $out;
    }

    private static function get_abbreviation_map($slug) {
        if (isset(self::$abbr_maps[$slug])) {
            return self::$abbr_maps[$slug];
        }
        $map = [];
        $lang = ($slug === 'bibel') ? 'de' : 'en';
        $file = plugin_dir_path(__FILE__) . 'data/' . $slug . '/abbreviations.' . $lang . '.json';
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['books']) && is_array($data['books'])) {
                foreach ($data['books'] as $short => $variants) {
                    if (!is_array($variants)) continue;
                    foreach ($variants as $v) {
                        $key = trim(mb_strtolower((string)$v, 'UTF-8'));
                        if ($key === '') continue;
                        // First writer wins; avoid clobbering in case of collisions.
                        if (!isset($map[$key])) {
                            $map[$key] = (string)$short;
                        }
                    }
                }
            }
        }
        self::$abbr_maps[$slug] = $map;
        return $map;
    }

    private static function canonical_book_slug_from_url($raw_book, $slug) {
        if (!is_string($raw_book) || $raw_book === '') return null;
        if ($slug === 'latin-bible') {
            $slug = 'bible';
        }
        if ($slug !== 'bible' && $slug !== 'bibel' && $slug !== 'latin') {
            $slug = 'bible';
        }
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
        $norm = preg_replace('/\.
\s*$/u', '', $book);
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

    public static function pretty_label($short_name) {
        if (!is_string($short_name)) return '';
        $label = $short_name;
        // Convert underscores to spaces by default
        $label = str_replace('_', ' ', $label);
        // Leading numeral becomes 'N. '
        $label = preg_replace('/^(\d+)\s+/', '$1. ', $label);
        // Specific compounds get a slash separator
        $label = preg_replace('/\bKings\s+Samuel\b/', 'Kings / Samuel', $label);
        $label = preg_replace('/\bEsdras\s+Nehemias\b/', 'Esdras / Nehemias', $label);
        // normalize whitespace
        $label = preg_replace('/\s+/', ' ', $label);
        return trim($label);
    }

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
            status_header(404);
            wp_die(__('Book not found', 'thebible'));
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

    public static function register_votd_cpt() {
        $labels = [
            'name'                  => __('Verses of the Day', 'thebible'),
            'singular_name'         => __('Verse of the Day', 'thebible'),
            'add_new'               => __('Add New', 'thebible'),
            'add_new_item'          => __('Add New Verse of the Day', 'thebible'),
            'edit_item'             => __('Edit Verse of the Day', 'thebible'),
            'new_item'              => __('New Verse of the Day', 'thebible'),
            'view_item'             => __('View Verse of the Day', 'thebible'),
            'search_items'          => __('Search Verses of the Day', 'thebible'),
            'not_found'             => __('No verses of the day found.', 'thebible'),
            'not_found_in_trash'    => __('No verses of the day found in Trash.', 'thebible'),
            'all_items'             => __('Verses of the Day', 'thebible'),
            'menu_name'             => __('Verse of the Day', 'thebible'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => false,
            'show_in_admin_bar'  => false,
            'exclude_from_search'=> true,
            'publicly_queryable' => false,
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => [],
            'menu_position'      => null,
        ];

        register_post_type('thebible_votd', $args);
    }

    public static function register_widgets() {
        if (class_exists('WP_Widget')) {
            register_widget('TheBible_VOTD_Widget');
        }
    }

    public static function register_strip_bibleserver_bulk($bulk_actions) {
        if (!is_array($bulk_actions)) return $bulk_actions;
        $bulk_actions['thebible_strip_bibleserver'] = __('Strip BibleServer links', 'thebible');
        $bulk_actions['thebible_set_bible'] = __('Set Bible: English (Douay-Rheims)', 'thebible');
        $bulk_actions['thebible_set_bibel'] = __('Set Bible: Deutsch (Menge)', 'thebible');
        return $bulk_actions;
    }

    private static function strip_bibleserver_links_from_content($content) {
        if (!is_string($content) || $content === '') return $content;
        // HTML links generated by the editor
        $pattern_html = '~<a\\s+[^>]*href=["\']https?://(?:www\\.)?bibleserver\\.com/[^"\']*["\'][^>]*>(.*?)</a>~is';
        $content = preg_replace($pattern_html, '$1', $content);

        // Raw Markdown-style links that may still be present in content
        // e.g. *[Matthäus 5:27-28](https://www.bibleserver.com/EU/Matth%C3%A4us5%2C27-28)*
        $pattern_md = '~\[([^\]]+)\]\(\s*https?://(?:www\.)?bibleserver\.com/[^\s\)]+\s*\)~i';
        $content = preg_replace($pattern_md, '$1', $content);

        return $content;
    }

    public static function handle_strip_bibleserver_bulk($redirect_to, $doaction, $post_ids) {
        if (!is_array($post_ids)) {
            return $redirect_to;
        }

        // Action 1: strip BibleServer links from content
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

        // Action 2/3: bulk set Bible slug meta
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
        
        // (debug removed)

        $slug = get_post_meta($post_id, 'thebible_slug', true);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        if ($slug !== 'bible' && $slug !== 'bibel' && $slug !== 'latin') {
            $slug = 'bible';
        }

        $abbr = self::get_abbreviation_map($slug);
        if (empty($abbr)) return $content;

        // Book token (group 1): optional leading number ("1.", "2"), then one or more words of letters (incl. umlauts) and dots.
        // Then optional space(s), chapter, colon, verse, optional dash and verse.
        // Word boundary (?<!\p{L}) ensures we don't match in the middle of words (Unicode-aware).
        $pattern = '/(?<!\p{L})('
                 . '(?:[0-9]{1,2}\.?(?:\s|\x{00A0})*)?'   // optional leading number like "1." or "2" with normal or NBSP spaces
                 . '[\p{L}][\p{L}\p{M}\.]*'              // book name in any language, allows dots
                 . '(?:(?:\s|\x{00A0})+[\p{L}\p{M}\.]+)*' // optional extra words (accept NBSP too)
                 . ')(?:\s|\x{00A0})*(\d+)(?:\s|\x{00A0})*[:\x{2236}\x{FE55}\x{FF1A}](?:\s|\x{00A0})*(\d+)(?:-(\d+))?(?!\p{L})/u'; // accept Unicode colon variants; ensure no letter immediately after

        // Split content by <a> tags to avoid matching inside existing links
        $parts = preg_split('/(<a\s[^>]*>.*?<\/a>)/us', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $content;
        }

        $result = '';
        foreach ($parts as $part) {
            // If this part is an <a> tag, keep it unchanged
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

                // Normalize common Unicode whitespace (narrow NBSP, thin space, etc.) and remove zero-width chars
                $normalized_part = preg_replace('/[\x{202F}\x{2000}-\x{200A}\x{2060}]/u', "\xC2\xA0", $normalized_part);
                $normalized_part = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $normalized_part);
                // Process Bible references in this part
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

        // Normalize spaces (also convert NBSP to normal space for matching)
        $book_clean = str_replace("\xC2\xA0", ' ', (string)$book_raw);
        // Strip trailing dot, collapse spaces
        $book_clean = preg_replace('/\.\s*$/u', '', $book_clean);
        $book_clean = preg_replace('/\s+/u', ' ', trim($book_clean));

        $effective_slug = $slug;
        $short = null;
        $resolved_book_text = null; // The exact book text we will show in the link
        $matched_word_start_index = null; // index in cleaned words where the book starts

        // Strategy: from the right, take the longest suffix that maps to a known book
        $words = preg_split('/\s+/u', $book_clean);
        if (is_array($words)) {
            for ($i = 0; $i < count($words); $i++) {
                $candidate = implode(' ', array_slice($words, $i));
                if ($candidate === '') continue;

                // Try exact key in current slug map
                $norm = preg_replace('/\s+/u', ' ', trim($candidate));
                $key = mb_strtolower($norm, 'UTF-8');
                if (isset($abbr[$key])) {
                    $short = $abbr[$key];
                    $resolved_book_text = $norm;
                    $matched_word_start_index = $i;
                    break;
                }

                // Fallback: strip dot after leading number (e.g., "1. Mose" -> "1 Mose")
                $alt = preg_replace('/^(\d+)\.\s*/u', '$1 ', $norm);
                $alt = preg_replace('/\s+/u', ' ', trim($alt));
                $alt_key = mb_strtolower($alt, 'UTF-8');
                if (isset($abbr[$alt_key])) {
                    $short = $abbr[$alt_key];
                    $resolved_book_text = $alt;
                    $matched_word_start_index = $i;
                    break;
                }

                // Try alternate dataset map ('bible' <-> 'bibel')
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
                    $short = $abbr_other[$alt_key];
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

        // Build the reference text from the resolved book part + chapter/verse
        $book_display = $resolved_book_text ?: $book_clean;
        $ref_text = $book_display . ' ' . $ch . ':' . $vf . ($vt && $vt >= $vf ? '-' . $vt : '');

        // Preserve any prefix words that were included before the actual book name
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

    private static function book_groups() {
        self::load_index();
        $ot = [];
        $nt = [];
        // Detect NT boundary dynamically by first occurrence of Matthew across locales
        $nt_slug_candidates = ['matthew','matthaeus'];
        $nt_start_order = null;
        foreach (self::$books as $b) {
            $slug = self::slugify($b['short_name']);
            if (in_array($slug, $nt_slug_candidates, true)) {
                $nt_start_order = intval($b['order']);
                break;
            }
        }
        foreach (self::$books as $b) {
            if ($nt_start_order !== null) {
                if (intval($b['order']) < $nt_start_order) $ot[] = $b; else $nt[] = $b;
            } else {
                // Fallback to legacy threshold
                if ($b['order'] <= 46) $ot[] = $b; else $nt[] = $b;
            }
        }
        return [$ot, $nt];
    }

    public static function handle_sitemap() {
        $map = get_query_var(self::QV_SITEMAP);
        if (!$map) return;

        $slug = get_query_var(self::QV_SLUG);
        if ($slug !== 'bible' && $slug !== 'bibel' && $slug !== 'latin') {
            status_header(404);
            exit;
        }

        self::load_index();
        if (empty(self::$books)) {
            status_header(404);
            exit;
        }

        status_header(200);
        nocache_headers();
        header('Content-Type: application/xml; charset=UTF-8');

        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $base_path = '/' . trim($slug, '/') . '/';
        $domain = rtrim( home_url(), '/' );

        $index_url = $domain . $base_path;
        echo '  <url><loc>' . esc_url($index_url) . '</loc></url>' . "\n";

        foreach (self::$books as $entry) {
            if (!is_array($entry) || empty($entry['short_name'])) continue;
            $book_slug = self::slugify($entry['short_name']);
            if ($book_slug === '') continue;
            // Book URL
            $book_url = $domain . $base_path . $book_slug . '/';
            echo '  <url><loc>' . esc_url($book_url) . '</loc></url>' . "\n";

            // Per-verse URLs: scan the book HTML for verse IDs like slug-CH-V
            $file = self::html_dir() . $entry['filename'];
            if (!is_string($file) || $file === '' || !file_exists($file)) {
                continue;
            }
            $html = @file_get_contents($file);
            if (!is_string($html) || $html === '') {
                continue;
            }
            $pattern = '/\bid="' . preg_quote($book_slug, '/') . '-(\d+)-(\d+)"/';
            if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                continue;
            }
            $seen = [];
            foreach ($matches as $m) {
                $ch = intval($m[1]);
                $v  = intval($m[2]);
                if ($ch <= 0 || $v <= 0) continue;
                $key = $ch . ':' . $v;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $loc = $domain . $base_path . $book_slug . '/' . $ch . ':' . $v;
                echo '  <url><loc>' . esc_url($loc) . '</loc></url>' . "\n";
            }
        }

        echo '</urlset>';
        exit;
    }

    public static function handle_template_redirect() {
        $flag = get_query_var(self::QV_FLAG);
        if (!$flag) return;

        // Serve Open Graph image when requested
        $og = get_query_var(self::QV_OG);
        if ($og) {
            TheBible_OG_Image::render();
            exit;
        }

        $book_slug = get_query_var(self::QV_BOOK);
        if ($book_slug) {
            $slug = get_query_var(self::QV_SLUG);
            if (!is_string($slug) || $slug === '') { $slug = 'bible'; }

            $canonical = self::canonical_book_slug_from_url($book_slug, $slug);
            if ($canonical !== null && $canonical !== $book_slug) {
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

            self::render_book($book_slug);
            exit;
        }

        self::render_index();
        exit;
    }

    private static function extract_verse_text_from_html($html, $book_slug, $ch, $vf, $vt) {
        if (!is_string($html) || $html === '' || !is_string($book_slug) || $book_slug === '') {
            return '';
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $parts = [];
        for ($i = $vf; $i <= $vt; $i++) {
            $id = $book_slug . '-' . $ch . '-' . $i;
            $nodes = $xp->query('//*[@id="' . $id . '"]');
            if ($nodes && $nodes->length) {
                $p = $nodes->item(0);
                $body = null;
                foreach ($p->getElementsByTagName('span') as $span) {
                    if ($span->hasAttribute('class') && strpos($span->getAttribute('class'), 'verse-body') !== false) { $body = $span; break; }
                }
                $txt = $body ? trim($body->textContent) : trim($p->textContent);
                $txt = self::normalize_whitespace($txt);
                if ($txt !== '') $parts[] = $txt;
            }
        }
        $combined = trim(implode(' ', $parts));
        return self::clean_verse_text_for_output($combined);
    }

    public static function get_book_entry_by_slug($slug) {
        self::load_index();
        $norm = self::slugify($slug);
        if (!is_string($norm) || $norm === '') return null;
        return self::$slug_map[$norm] ?? null;
    }

    public static function extract_verse_text($entry, $ch, $vf, $vt) {
        if (!$entry || !is_array($entry)) return '';
        $file = self::html_dir() . $entry['filename'];
        if (!file_exists($file)) return '';
        $html = file_get_contents($file);
        if (!$html) return '';

        $ch = absint($ch);
        $vf = absint($vf);
        $vt = absint($vt);
        if ($ch <= 0 || $vf <= 0) return '';
        if ($vt <= 0 || $vt < $vf) { $vt = $vf; }

        $book_slug = '';
        if (isset($entry['short_name']) && is_string($entry['short_name'])) {
            $book_slug = self::slugify($entry['short_name']);
        }
        if ($book_slug === '') return '';
        return self::extract_verse_text_from_html($html, $book_slug, $ch, $vf, $vt);
    }

    private static function extract_votd_texts_for_entry($entry) {
        if (!is_array($entry)) return [];
        $out = [];
        $datasets = ['bible', 'bibel'];
        foreach ($datasets as $dataset) {
            $short = self::resolve_book_for_dataset($entry['book_slug'], $dataset);
            if (!is_string($short) || $short === '') {
                continue;
            }
            $index_file = plugin_dir_path(__FILE__) . 'data/' . $dataset . '/html/index.csv';
            if (!file_exists($index_file)) {
                continue;
            }
            $filename = '';
            if (($fh = fopen($index_file, 'r')) !== false) {
                $header = fgetcsv($fh);
                while (($row = fgetcsv($fh)) !== false) {
                    if (!is_array($row) || count($row) < 4) continue;
                    if ((string) $row[1] === (string) $short) {
                        $filename = (string) $row[3];
                        break;
                    }
                }
                fclose($fh);
            }
            if ($filename === '') {
                continue;
            }
            $html_path = plugin_dir_path(__FILE__) . 'data/' . $dataset . '/html/' . $filename;
            if (!file_exists($html_path)) {
                continue;
            }
            $html = (string) file_get_contents($html_path);
            if ($html === '') {
                continue;
            }
            $book_slug = self::slugify($short);
            $txt = self::extract_verse_text_from_html($html, $book_slug, (int) $entry['chapter'], (int) $entry['vfrom'], (int) $entry['vto']);
            if (is_string($txt) && $txt !== '') {
                $out[$dataset] = $txt;
            }
        }
        return $out;
    }

    private static function normalize_whitespace($s) {
        // Replace various Unicode spaces/invisibles with normal space or remove, collapse, and trim
        $s = (string)$s;
        // Map a set of known invisibles to spaces or empty
        $map = [
            "\xC2\xA0" => ' ', // NBSP U+00A0
            "\xC2\xAD" => '',  // Soft hyphen U+00AD
            "\xE1\x9A\x80" => ' ', // OGHAM space mark U+1680
            "\xE2\x80\x80" => ' ', // En quad U+2000
            "\xE2\x80\x81" => ' ', // Em quad U+2001
            "\xE2\x80\x82" => ' ', // En space U+2002
            "\xE2\x80\x83" => ' ', // Em space U+2003
            "\xE2\x80\x84" => ' ', // Three-per-em space U+2004
            "\xE2\x80\x85" => ' ', // Four-per-em space U+2005
            "\xE2\x80\x86" => ' ', // Six-per-em space U+2006
            "\xE2\x80\x87" => ' ', // Figure space U+2007
            "\xE2\x80\x88" => ' ', // Punctuation space U+2008
            "\xE2\x80\x89" => ' ', // Thin space U+2009
            "\xE2\x80\x8A" => ' ', // Hair space U+200A
            "\xE2\x80\x8B" => '',  // Zero width space U+200B
            "\xE2\x80\x8C" => '',  // Zero width non-joiner U+200C
            "\xE2\x80\x8D" => '',  // Zero width joiner U+200D
            "\xE2\x80\x8E" => '',  // LRM U+200E
            "\xE2\x80\x8F" => '',  // RLM U+200F
            "\xE2\x80\xA8" => ' ', // Line separator U+2028
            "\xE2\x80\xA9" => ' ', // Paragraph separator U+2029
            "\xE2\x80\xAF" => ' ', // Narrow no-break space U+202F
            "\xE2\x81\xA0" => ' ', // Word joiner U+2060
            "\xEF\xBB\xBF" => '',  // BOM U+FEFF
        ];
        $s = strtr($s, $map);
        // Collapse whitespace
        $s = preg_replace('/\s+/u', ' ', $s);
        // Trim and ensure no trailing space remains before closing quotes
        $s = trim($s);
        return $s;
    }

    public static function clean_verse_text_for_output($s, $wrap_outer = false, $qL = '»', $qR = '«') {
        // Convenience helper for external callers (e.g., widgets, OG images):
        // normalize whitespace and apply the internal quotation cleaner.
        $s = self::normalize_whitespace($s);
        return self::clean_verse_quotes($s, $wrap_outer, $qL, $qR);
    }

    private static function clean_verse_quotes($s, $wrap_outer = false, $qL = '»', $qR = '«') {
        // General quotation mark cleaner for verse text.
        // Rules:
        // - If the verse block contains both » and «, convert all of them
        //   to single inner guillemets › and ‹.
        // - If it has only opening-style » and no «, append a matching « at the end,
        //   then apply the above conversion.
        // - If it has only closing-style « and no », prepend a matching » at the start,
        //   then apply the above conversion.
        // - Final rule: if the cleaned text would begin with "»›" and end with "‹«",
        //   collapse those pairs to single outer quotes » and «.

        $s = (string) $s;
        if ($s === '') return $s;

        // (4) Strip hidden/control/combining characters that fonts may have trouble with.
        // We already normalized many Unicode spaces in normalize_whitespace(); here we
        // remove remaining control (\p{C}) and combining mark (\p{M}) codepoints,
        // then drop any other character that is not a letter, number, punctuation,
        // symbol, or whitespace.
        $s = preg_replace('/[\p{C}\p{M}]+/u', '', $s);
        $s = preg_replace('/[^\p{L}\p{N}\p{P}\p{S}\s]/u', '', $s);

        $has_left  = (strpos($s, '«') !== false);
        $has_right = (strpos($s, '»') !== false);

        // (1) When only a single side is present, synthesize the missing partner so
        // we always operate on a balanced pair.
        if ($has_right && !$has_left) {
            // Only » present: add a closing « at the very end
            $s .= '«';
            $has_left = true;
        } elseif ($has_left && !$has_right) {
            // Only « present: add an opening » at the very start
            $s = '»' . $s;
            $has_right = true;
        }

        // (2) If there is both » and «, normalize them to inner guillemets.
        if ($has_left && $has_right) {
            // Now that we have a pair, normalize all outer guillemets to inner ones
            $s = str_replace(['«', '»'], ['‹', '›'], $s);
        }

        // (3) Post-pass: ONLY if text both begins with "»›" AND ends with "‹«",
        // collapse these outer+inner pairs back to single outer quotes.
        $len = self::u_strlen($s);
        if ($len >= 2) {
            $starts = (self::u_substr($s, 0, 2) === '»›');
            $ends   = (self::u_substr($s, -2) === '‹«');
            if ($starts && $ends) {
                $s = '»' . self::u_substr($s, 2); // collapse leading »› -> »
                // recompute length after leading change
                $len = self::u_strlen($s);
                if ($len >= 2 && self::u_substr($s, -2) === '‹«') {
                    $s = self::u_substr($s, 0, $len - 2) . '«'; // collapse trailing ‹« -> «
                }
            }
        }

        // Normalize surrounding whitespace once more after quote adjustments
        $s = trim($s);

        // (5) If the quote ends with a space + m- or n-dash immediately before
        // a guillemet (inner or outer), strip the space and dash but keep the
        // guillemet. This covers cases like "… und der Propheten. –«".
        $s = preg_replace('/\s*[–—]\s*([«‹»›])\s*$/u', '$1', $s);

        // Also, if the quote ends directly with an m- or n-dash (no closing
        // guillemet), strip that dash and any trailing spaces.
        $s = preg_replace('/[–—]\s*$/u', '', $s);
        $s = trim($s);

        // Final safety / wrapping behavior
        $len = self::u_strlen($s);
        // First, if the text begins with an outer+inner pair "»›" and ends with
        // "‹«", collapse those boundary pairs back to a single outer quote on
        // each side. This avoids visual combinations like »›...‹« at the edges.
        if ($len >= 4 && self::u_substr($s, 0, 2) === '»›' && self::u_substr($s, -2) === '‹«') {
            $s = '»' . self::u_substr($s, 2, $len - 4) . '«';
            $len = self::u_strlen($s);
        }
        if ($wrap_outer) {
            // If already wrapped with the requested outer quotes, do not wrap again.
            if ($len >= 2 && self::u_substr($s, 0, 1) === $qL && self::u_substr($s, -1) === $qR) {
                // no-op
            }
            // If wrapped in inner guillemets ›...‹, promote them to the requested outer quotes.
            elseif ($len >= 2 && self::u_substr($s, 0, 1) === '›' && self::u_substr($s, -1) === '‹') {
                $s = $qL . self::u_substr($s, 1, $len - 2) . $qR;
            } else {
                // Otherwise, wrap the whole text once using qL/qR.
                $s = $qL . $s . $qR;
            }
        }

        return $s;
    }

    private static function render_index() {
        self::load_index();
        status_header(200);
        nocache_headers();
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        $title = ($slug === 'bibel') ? 'Die Bibel' : (($slug === 'latin') ? 'Biblia Sacra' : 'The Bible');
        $content = self::build_index_html();
        $footer = self::render_footer_html();
        if ($footer !== '') { $content .= $footer; }
        self::output_with_theme($title, $content, 'index');
    }

    private static function extract_chapter_from_html($html, $ch) {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        // Find the chapter heading like <h2 id="book-CH">Chapter CH</h2>
        $chapter_node = $xp->query('//h2[contains(@id, "-' . $ch . '")]')->item(0);
        if (!$chapter_node) return null;
        $out = '';
        $node = $chapter_node;
        while ($node) {
            $out .= $doc->saveHTML($node);
            // Stop at next chapter heading or end of parent
            $next = $node->nextSibling;
            if ($next && $next->nodeName === 'h2' && strpos($next->getAttribute('id'), '-' . ($ch + 1)) !== false) {
                break;
            }
            $node = $next;
        }
        return $out;
    }

    private static function render_book($slug) {
        self::load_index();
        // Normalize incoming slug to match index keys (case-insensitive URLs)
        $norm = self::slugify($slug);
        $entry = ($norm !== '' && isset(self::$slug_map[$norm])) ? self::$slug_map[$norm] : null;
        if (!$entry) {
            self::render_404();
            return;
        }
        $file = self::html_dir() . $entry['filename'];
        if (!file_exists($file)) {
            self::render_404();
            return;
        }
        $html = file_get_contents($file);

        // Determine chapter (full-book rendering is disabled; default to chapter 1)
        $ch = absint(get_query_var(self::QV_CHAPTER));
        if ($ch <= 0) {
            $ch = 1;
            set_query_var(self::QV_CHAPTER, $ch);
        }

        // Single-chapter mode: extract only the requested chapter
        $chapter_html = self::extract_chapter_from_html($html, $ch);
        if ($chapter_html === null) {
            self::render_404();
            return;
        }
        $html = $chapter_html;

        // Build highlight/scroll targets from URL like /book/20:2-4 or /book/20
        $targets = [];
        $chapter_scroll_id = null;
        $vf = absint(get_query_var(self::QV_VFROM));
        $vt = absint(get_query_var(self::QV_VTO));
        $book_slug = self::slugify($entry['short_name']);
        if ($ch && $vf) {
            if (!$vt || $vt < $vf) { $vt = $vf; }
            for ($i = $vf; $i <= $vt; $i++) {
                $targets[] = $book_slug . '-' . $ch . '-' . $i;
            }
        } elseif ($ch && !$vf) {
            // Chapter-only: scroll to chapter heading id like slug-ch-{ch}
            $chapter_scroll_id = $book_slug . '-ch-' . $ch;
        }

        // Inject navigation helpers and optional highlight/scroll behavior
        $human = isset($entry['display_name']) && $entry['display_name'] !== '' ? $entry['display_name'] : $entry['short_name'];
        $html = self::inject_nav_helpers($html, $targets, $chapter_scroll_id, $human, [
            'book' => $book_slug,
            'chapter' => $ch,
        ]);

        status_header(200);
        nocache_headers();
        $base_title = isset($entry['display_name']) && $entry['display_name'] !== ''
            ? $entry['display_name']
            : self::pretty_label($entry['short_name']);
        $title = $base_title;
        $slug_ctx = get_query_var(self::QV_SLUG);
        if (!is_string($slug_ctx) || $slug_ctx === '') { $slug_ctx = 'bible'; }

        $vf = absint(get_query_var(self::QV_VFROM));
        $vt = absint(get_query_var(self::QV_VTO));
        if ($ch && $vf) {
            if (!$vt || $vt < $vf) { $vt = $vf; }
            $ref = $base_title . ' ' . $ch . ':' . ($vf === $vt ? $vf : ($vf . '-' . $vt));
            $snippet = self::extract_verse_text($entry, $ch, $vf, $vt);
            if (is_string($snippet) && $snippet !== '') {
                $snippet = wp_strip_all_tags($snippet);
                $snippet = preg_replace('/\s+/u', ' ', trim($snippet));
                if ($snippet !== '') {
                    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                        $max = 80;
                        if (mb_strlen($snippet, 'UTF-8') > $max) {
                            $snippet = mb_substr($snippet, 0, $max, 'UTF-8') . '…';
                        }
                    } else {
                        if (strlen($snippet) > $max) {
                            $snippet = substr($snippet, 0, 80) . '…';
                        }
                    }
                    $title = $ref . ' (»' . $snippet . '«)';
                } else {
                    $title = $ref;
                }
            } else {
                $title = $ref;
            }
        } elseif ($ch) {
            $title = $base_title . ' ' . $ch;
        }
        $content = '<div class="thebible thebible-book">' . $html . '</div>';
        $footer = self::render_footer_html();
        if ($footer !== '') { $content .= $footer; }
        self::output_with_theme($title, $content, 'book');
    }

    private static function render_404() {
        status_header(404);
        nocache_headers();
        if (function_exists('get_header')) get_header();
        echo '<main id="primary" class="site-main container mt-2">'
           . '<h1>Not Found</h1>'
           . '<p>The requested book could not be found.</p>'
           . '</main>';
        if (function_exists('get_footer')) get_footer();
    }

    private static function build_index_html() {
        list($ot, $nt) = self::book_groups();
        $base = get_query_var(self::QV_SLUG);
        if (!is_string($base) || $base === '') { $base = 'bible'; }
        $home = home_url('/' . $base . '/');
        $out = '<div class="thebible thebible-index">';
        $out .= '<div class="thebible-groups">';
        $ot_label = ($base === 'bibel') ? 'Altes Testament' : 'Old Testament';
        $nt_label = ($base === 'bibel') ? 'Neues Testament' : 'New Testament';
        $out .= '<section class="thebible-group thebible-ot"><h2>' . esc_html($ot_label) . '</h2><ul>';
        foreach ($ot as $b) {
            $slug = self::slugify($b['short_name']);
            $url = trailingslashit($home) . $slug . '/';
            $label = !empty($b['display_name']) ? $b['display_name'] : self::pretty_label($b['short_name']);
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        $out .= '</ul></section>';
        $out .= '<section class="thebible-group thebible-nt"><h2>' . esc_html($nt_label) . '</h2><ul>';
        foreach ($nt as $b) {
            $slug = self::slugify($b['short_name']);
            $url = trailingslashit($home) . $slug . '/';
            $label = !empty($b['display_name']) ? $b['display_name'] : self::pretty_label($b['short_name']);
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        $out .= '</ul></section>';
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }

    private static function base_slugs() {
        $list = get_option('thebible_slugs', 'bible,bibel,latin');
        if (!is_string($list)) $list = 'bible';
        $parts = array_filter(array_map('trim', explode(',', $list)));
        if (empty($parts)) { $parts = ['bible']; }
        $parts = array_values(array_unique($parts));
        $datasets = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '' || strpos($p, '-') !== false) continue;
            $datasets[] = $p;
        }
        $datasets = array_values(array_unique($datasets));
        $combos = self::build_language_slug_combinations($datasets, 3);
        return array_values(array_unique(array_merge($parts, $combos)));
    }

    private static function is_bible_request() {
        $slug = get_query_var(self::QV_SLUG);
        $book = get_query_var(self::QV_BOOK);
        $flag = get_query_var(self::QV_FLAG);
        if (!empty($flag)) {
            return true;
        }
        if (is_string($slug) && $slug !== '') {
            $slug = trim($slug, "/ ");
            if ($slug === 'bible' || $slug === 'bibel' || $slug === 'latin' || strpos($slug, '-') !== false) {
                return true;
            }
        }
        if (is_string($book) && $book !== '') {
            return true;
        }
        return false;
    }

    private static function build_language_slug_combinations($datasets, $max_len = 3) {
        if (!is_array($datasets) || empty($datasets)) return [];
        $datasets = array_values(array_unique(array_filter(array_map('trim', $datasets))));
        $out = [];
        $n = count($datasets);
        if ($n < 2) return [];

        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if ($j === $i) continue;
                $out[] = $datasets[$i] . '-' . $datasets[$j];
            }
        }

        if ($max_len >= 3 && $n >= 3) {
            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    if ($j === $i) continue;
                    for ($k = 0; $k < $n; $k++) {
                        if ($k === $i || $k === $j) continue;
                        $out[] = $datasets[$i] . '-' . $datasets[$j] . '-' . $datasets[$k];
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function get_book_entry_for_dataset($dataset_slug, $book_slug) {
        $dataset_slug = is_string($dataset_slug) ? trim($dataset_slug) : '';
        $book_slug = is_string($book_slug) ? self::slugify($book_slug) : '';
        if ($dataset_slug === '' || $book_slug === '') return null;

        $index_file = plugin_dir_path(__FILE__) . 'data/' . $dataset_slug . '/html/index.csv';
        if (!file_exists($index_file)) {
            $old = plugin_dir_path(__FILE__) . 'data/' . $dataset_slug . '_books_html/index.csv';
            if (file_exists($old)) {
                $index_file = $old;
            } else {
                return null;
            }
        }

        if (($fh = fopen($index_file, 'r')) === false) return null;
        $header = fgetcsv($fh);
        $found = null;
        while (($row = fgetcsv($fh)) !== false) {
            if (!is_array($row) || count($row) < 3) continue;
            $short = (string) $row[1];
            $slug = self::slugify($short);
            if ($slug === $book_slug) {
                $display = '';
                $filename = '';
                if (count($row) >= 4) {
                    $display = isset($row[2]) ? (string)$row[2] : '';
                    $filename = isset($row[3]) ? (string)$row[3] : (isset($row[2]) ? (string)$row[2] : '');
                } else {
                    $filename = (string)$row[2];
                }
                $found = [
                    'order' => intval($row[0]),
                    'short_name' => $short,
                    'display_name' => $display,
                    'filename' => $filename,
                ];
                break;
            }
        }
        fclose($fh);
        return $found;
    }

    private static function html_dir_for_dataset($dataset_slug) {
        $dataset_slug = is_string($dataset_slug) ? trim($dataset_slug) : '';
        if ($dataset_slug === '') return null;
        $root = plugin_dir_path(__FILE__) . 'data/' . $dataset_slug . '/html/';
        if (is_dir($root)) return trailingslashit($root);
        $old = plugin_dir_path(__FILE__) . 'data/' . $dataset_slug . '_books_html/';
        if (is_dir($old)) return trailingslashit($old);
        return null;
    }

    private static function parse_verse_nodes_by_number($html, $book_slug, $ch) {
        $out = [];
        if (!is_string($html) || $html === '') return $out;
        if (!is_string($book_slug) || $book_slug === '') return $out;
        $ch = absint($ch);
        if ($ch <= 0) return $out;

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $prefix = $book_slug . '-' . $ch . '-';
        $nodes = $xp->query('//*[@id and starts-with(@id, "' . $prefix . '")]');
        if (!$nodes) return $out;
        foreach ($nodes as $n) {
            if (!$n->hasAttribute('id')) continue;
            $id = (string)$n->getAttribute('id');
            if (strpos($id, $prefix) !== 0) continue;
            $v = absint(substr($id, strlen($prefix)));
            if ($v <= 0) continue;
            $out[$v] = $n;
        }
        return [$doc, $out];
    }

    private static function strip_element_by_id($html, $id) {
        if (!is_string($html) || $html === '') return $html;
        if (!is_string($id) || $id === '') return $html;
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $nodes = $xp->query('//*[@id="' . $id . '"]');
        if ($nodes && $nodes->length) {
            $n = $nodes->item(0);
            if ($n && $n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) return $html;
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function extract_nav_blocks_from_chapter_html($chapter_html) {
        if (!is_string($chapter_html) || $chapter_html === '') return '';
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $chapter_html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $out = '';
        $chapters = $xp->query('//p[contains(concat(" ", normalize-space(@class), " "), " chapters ")]');
        if ($chapters && $chapters->length) {
            $out .= $doc->saveHTML($chapters->item(0));
        }
        $verses = $xp->query('//p[contains(concat(" ", normalize-space(@class), " "), " verses ")]');
        if ($verses && $verses->length) {
            $out .= $doc->saveHTML($verses->item(0));
        }
        return $out;
    }

    private static function render_multilingual_book($url_book_slug, $slug_combo) {
        $url_book_slug = is_string($url_book_slug) ? $url_book_slug : '';
        $slug_combo = is_string($slug_combo) ? trim($slug_combo, "/ ") : '';
        $canonical_key = self::slugify($url_book_slug);
        if ($canonical_key === '' || $slug_combo === '') {
            self::render_404();
            return;
        }

        $datasets = array_values(array_filter(array_map('trim', explode('-', $slug_combo))));
        $datasets = array_values(array_unique($datasets));
        if (count($datasets) < 1 || count($datasets) > 3) {
            self::render_404();
            return;
        }

        $entries = [];
        $docs = [];
        $nodes_by_dataset = [];

        foreach ($datasets as $dataset) {
            if (!is_string($dataset) || $dataset === '') {
                self::render_404();
                return;
            }

            $dataset_short = self::resolve_book_for_dataset($canonical_key, $dataset);
            if (!is_string($dataset_short) || $dataset_short === '') {
                $dataset_short = $canonical_key;
            }

            $entry = self::get_book_entry_for_dataset($dataset, $dataset_short);
            if (!$entry) {
                self::render_404();
                return;
            }

            $dir = self::html_dir_for_dataset($dataset);
            if (!$dir) {
                self::render_404();
                return;
            }

            $file = $dir . $entry['filename'];
            if (!file_exists($file)) {
                self::render_404();
                return;
            }

            $entries[$dataset] = $entry;
            $html = (string) file_get_contents($file);
            $entries[$dataset]['_raw_html'] = $html;
        }

        $ch = absint(get_query_var(self::QV_CHAPTER));
        if ($ch <= 0) {
            $ch = 1;
            set_query_var(self::QV_CHAPTER, $ch);
        }

        $nav_blocks = '';
        foreach ($datasets as $dataset) {
            $html = (string) $entries[$dataset]['_raw_html'];
            $chapter_html = self::extract_chapter_from_html($html, $ch);
            if ($chapter_html === null) {
                self::render_404();
                return;
            }

            // Keep chapters/verses navigation blocks from the first dataset
            if ($dataset === $datasets[0]) {
                $nav_blocks = self::extract_nav_blocks_from_chapter_html($chapter_html);
            }

            // Remove the chapter heading node (e.g. id="genesis-ch-1") to avoid duplicate/unstyled chapter titles
            $dataset_book_slug = self::slugify($entries[$dataset]['short_name']);
            $chapter_heading_id = $dataset_book_slug . '-ch-' . $ch;
            $chapter_html = self::strip_element_by_id($chapter_html, $chapter_heading_id);

            $parsed = self::parse_verse_nodes_by_number($chapter_html, $dataset_book_slug, $ch);
            if (!is_array($parsed) || count($parsed) !== 2) {
                self::render_404();
                return;
            }
            list($doc, $nodes) = $parsed;
            $docs[$dataset] = $doc;
            $nodes_by_dataset[$dataset] = $nodes;
        }

        $verses = [];
        foreach ($datasets as $dataset) {
            $verses = array_merge($verses, array_keys($nodes_by_dataset[$dataset]));
        }
        $verses = array_values(array_unique($verses));
        sort($verses);

        $out = '<div class="thebible thebible-book thebible-interlinear">';
        if (is_string($nav_blocks) && $nav_blocks !== '') {
            $out .= $nav_blocks;
        }
        foreach ($verses as $v) {
            $out .= '<div class="thebible-interlinear-verse" data-verse="' . esc_attr((string)$v) . '">';
            foreach ($datasets as $idx => $dataset) {
                $node = $nodes_by_dataset[$dataset][$v] ?? null;
                if (!$node) {
                    continue;
                }
                $doc = $docs[$dataset];
                $node = $doc->importNode($node, true);
                $class_suffix = chr(ord('a') + $idx);
                $node->setAttribute('class', trim($node->getAttribute('class') . ' thebible-interlinear-' . $class_suffix . ' thebible-interlinear-' . $dataset));
                if ($idx === 0) {
                    $id = $canonical_key . '-' . $ch . '-' . $v;
                    $node->setAttribute('id', $id);
                } else {
                    if ($node->hasAttribute('id')) { $node->removeAttribute('id'); }
                }
                $out .= $doc->saveHTML($node);
            }
            $out .= '</div>';
        }
        $out .= '</div>';

        // Build highlight/scroll targets from URL like /book/20:2-4 or /book/20
        $targets = [];
        $chapter_scroll_id = null;
        $vf = absint(get_query_var(self::QV_VFROM));
        $vt = absint(get_query_var(self::QV_VTO));
        if ($ch && $vf) {
            if (!$vt || $vt < $vf) { $vt = $vf; }
            for ($i = $vf; $i <= $vt; $i++) {
                $targets[] = $canonical_key . '-' . $ch . '-' . $i;
            }
        } elseif ($ch && !$vf) {
            $chapter_scroll_id = $canonical_key . '-ch-' . $ch;
        }

        // Inject navigation helpers and sticky header for interlinear pages
        $first_entry = $entries[$datasets[0]] ?? null;
        $human = $first_entry && isset($first_entry['display_name']) && $first_entry['display_name'] !== ''
            ? $first_entry['display_name']
            : ($first_entry ? self::pretty_label($first_entry['short_name']) : '');
        $out = self::inject_nav_helpers($out, $targets, $chapter_scroll_id, $human, [
            'book' => $canonical_key,
            'chapter' => $ch,
        ]);

        status_header(200);
        nocache_headers();

        $first = $datasets[0];
        $first_entry = $entries[$first] ?? null;
        $base_title = ($first_entry && isset($first_entry['display_name']) && $first_entry['display_name'] !== '')
            ? $first_entry['display_name']
            : ($first_entry ? self::pretty_label($first_entry['short_name']) : '');

        $title = trim($base_title . ' ' . $ch);
        $vf = absint(get_query_var(self::QV_VFROM));
        $vt = absint(get_query_var(self::QV_VTO));
        if ($ch && $vf) {
            if (!$vt || $vt < $vf) { $vt = $vf; }
            $title = trim($base_title . ' ' . $ch . ':' . ($vf === $vt ? $vf : ($vf . '-' . $vt)));
        }

        $footer = self::render_footer_html();
        if ($footer !== '') { $out .= $footer; }
        self::output_with_theme($title, $out, 'book');
    }

    public static function filter_document_title($title) {
        if (!self::is_bible_request()) {
            return $title;
        }
        if (is_string(self::$current_page_title) && self::$current_page_title !== '') {
            return self::$current_page_title;
        }
        return $title;
    }

    public static function filter_document_title_parts($parts) {
        if (!self::is_bible_request()) {
            return $parts;
        }
        if (!is_array($parts)) {
            $parts = [];
        }
        if (is_string(self::$current_page_title) && self::$current_page_title !== '') {
            $parts['title'] = self::$current_page_title;
        }
        return $parts;
    }

    private static function output_with_theme($title, $content_html, $context = '') {
        // Allow theme override templates (e.g., dwtheme/thebible/...).
        // If a template is found, it is responsible for calling get_header/get_footer and echoing content.
        self::$current_page_title = is_string($title) ? $title : '';
        $context = is_string($context) ? $context : '';
        if ( function_exists('locate_template') ) {
            $thebible_title   = $title;        // available to template
            $thebible_content = $content_html; // available to template
            $thebible_context = $context;      // 'index' | 'book'
            $templates = [];
            if ($context === 'book') {
                $templates = [ 'thebible/single-book.php', 'thebible/thebible.php' ];
            } elseif ($context === 'index') {
                $templates = [ 'thebible/index.php', 'thebible/thebible.php' ];
            } else {
                $templates = [ 'thebible/thebible.php' ];
            }
            $found = locate_template( $templates, false, false );
            if ( $found ) {
                // Load the found template within current scope so our variables are available
                require $found;
                return;
            }
        }

        // Fallback: use plugin's built-in wrapper
        if (function_exists('get_header')) get_header();
        echo '<main id="primary" class="site-main container mt-2">';
        echo '<article class="thebible-article">';
        echo '<header class="entry-header mb-3"><h1 class="entry-title">' . esc_html($title) . '</h1></header>';
        echo '<div class="entry-content">' . $content_html . '</div>';
        echo '</article>';
        echo '</main>';
        if (function_exists('get_footer')) get_footer();
    }

    public static function register_settings() {
        register_setting(
            'thebible_options',
            'thebible_slugs',
            [
                'type'              => 'string',
                'sanitize_callback' => function( $val ) {
                    // If this save does not provide the field (e.g. another settings tab),
                    // keep the existing value instead of resetting slugs.
                    if ( ! isset( $val ) || $val === '' ) {
                        $current = get_option( 'thebible_slugs', 'bible,bibel' );
                        return is_string( $current ) && $current !== '' ? $current : 'bible,bibel';
                    }

                    if ( ! is_string( $val ) ) return 'bible,bibel';
                    // normalize comma-separated list
                    $parts = array_filter( array_map( 'trim', explode( ',', $val ) ) );
                    // only allow known slugs for now
                    $known = [ 'bible', 'bibel' ];
                    $out = [];
                    foreach ( $parts as $p ) { if ( in_array( $p, $known, true ) ) $out[] = $p; }
                    if ( empty( $out ) ) $out = [ 'bible' ];
                    return implode( ',', array_unique( $out ) );
                },
                'default'           => 'bible,bibel',
            ]
        );

        register_setting('thebible_options', 'thebible_og_enabled', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_enabled', '1'); return $c === '0' ? '0' : '1'; } return $v === '0' ? '0' : '1'; }, 'default' => '1' ]);
        register_setting('thebible_options', 'thebible_og_width', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_width', 1200); $n = absint($v); return $n < 100 ? 1200 : $n; }, 'default' => 1200 ]);
        register_setting('thebible_options', 'thebible_og_height', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_height', 630); $n = absint($v); return $n < 100 ? 630 : $n; }, 'default' => 630 ]);
        register_setting('thebible_options', 'thebible_og_bg_color', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_bg_color', '#111111'); return is_string($c) && $c !== '' ? $c : '#111111'; } return is_string($v) ? $v : '#111111'; }, 'default' => '#111111' ]);
        register_setting('thebible_options', 'thebible_og_text_color', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_text_color', '#ffffff'); return is_string($c) && $c !== '' ? $c : '#ffffff'; } return is_string($v) ? $v : '#ffffff'; }, 'default' => '#ffffff' ]);
        register_setting('thebible_options', 'thebible_og_font_ttf', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v)) return (string) get_option('thebible_og_font_ttf', ''); return is_string($v) ? $v : ''; }, 'default' => '' ]);
        register_setting('thebible_options', 'thebible_og_font_url', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v)) return (string) get_option('thebible_og_font_url', ''); return is_string($v) ? esc_url_raw($v) : ''; }, 'default' => '' ]);
        // Back-compat size (still read as fallback)
        register_setting('thebible_options', 'thebible_og_font_size', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_font_size', 40); $n = absint($v); return $n < 8 ? 40 : $n; }, 'default' => 40 ]);
        // New: separate sizes for main text and reference
        register_setting('thebible_options', 'thebible_og_font_size_main', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_font_size_main', 40); $n = absint($v); return $n < 8 ? 40 : $n; }, 'default' => 40 ]);
        register_setting('thebible_options', 'thebible_og_font_size_ref', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_font_size_ref', 40); $n = absint($v); return $n < 8 ? 40 : $n; }, 'default' => 40 ]);
        // Minimum main size before truncation kicks in
        register_setting('thebible_options', 'thebible_og_min_font_size_main', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_min_font_size_main', 18); $n = absint($v); return $n < 8 ? 18 : $n; }, 'default' => 18 ]);
        // Layout & spacing
        // Specific paddings (defaults 50px). General padding deprecated.
        register_setting('thebible_options', 'thebible_og_padding_x', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_padding_x', 50); return absint($v); }, 'default' => 50 ]);
        register_setting('thebible_options', 'thebible_og_padding_top', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_padding_top', 50); return absint($v); }, 'default' => 50 ]);
        register_setting('thebible_options', 'thebible_og_padding_bottom', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_padding_bottom', 50); return absint($v); }, 'default' => 50 ]);
        register_setting('thebible_options', 'thebible_og_min_gap', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_min_gap', 16); return absint($v); }, 'default' => 16 ]);
        // Main text line-height (as a factor, e.g., 1.35)
        register_setting('thebible_options', 'thebible_og_line_height_main', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_line_height_main', '1.35'); return is_string($c) && $c !== '' ? $c : '1.35'; } return is_string($v) ? trim($v) : '1.35'; }, 'default' => '1.35' ]);
        // Icon settings
        register_setting('thebible_options', 'thebible_og_icon_url', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v)) return (string) get_option('thebible_og_icon_url', ''); return is_string($v) ? esc_url_raw($v) : ''; }, 'default' => '' ]);
        // Simplified placement: always bottom; choose which side holds the logo; source uses the opposite
        register_setting('thebible_options', 'thebible_og_logo_side', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_logo_side', 'left'); return in_array($c, ['left','right'], true) ? $c : 'left'; } return in_array($v, ['left','right'], true) ? $v : 'left'; }, 'default' => 'left' ]);
        // Padding adjust for logo relative to general padding (can be negative)
        register_setting('thebible_options', 'thebible_og_logo_pad_adjust', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_logo_pad_adjust', 0); return intval($v); }, 'default' => 0 ]); // legacy single-axis
        register_setting('thebible_options', 'thebible_og_logo_pad_adjust_x', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_logo_pad_adjust_x', 0); return intval($v); }, 'default' => 0 ]);
        register_setting('thebible_options', 'thebible_og_logo_pad_adjust_y', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_logo_pad_adjust_y', 0); return intval($v); }, 'default' => 0 ]);
        register_setting('thebible_options', 'thebible_og_icon_max_w', [ 'type' => 'integer', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') return (int) get_option('thebible_og_icon_max_w', 160); $n = absint($v); return $n < 1 ? 160 : $n; }, 'default' => 160 ]);
        register_setting('thebible_options', 'thebible_og_background_image_url', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v)) return (string) get_option('thebible_og_background_image_url', ''); return is_string($v) ? $v : ''; }, 'default' => '' ]);
        // Quotation marks and reference position
        register_setting('thebible_options', 'thebible_og_quote_left', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_quote_left', '«'); return is_string($c) && $c !== '' ? $c : '«'; } return is_string($v) ? $v : '«'; }, 'default' => '«' ]);
        register_setting('thebible_options', 'thebible_og_quote_right', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_quote_right', '»'); return is_string($c) && $c !== '' ? $c : '»'; } return is_string($v) ? $v : '»'; }, 'default' => '»' ]);
        register_setting('thebible_options', 'thebible_og_ref_position', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_ref_position', 'bottom'); return in_array($c, ['top','bottom'], true) ? $c : 'bottom'; } return in_array($v, ['top','bottom'], true) ? $v : 'bottom'; }, 'default' => 'bottom' ]);
        register_setting('thebible_options', 'thebible_og_ref_align', [ 'type' => 'string', 'sanitize_callback' => function($v){ if (!isset($v) || $v === '') { $c = get_option('thebible_og_ref_align', 'left'); return in_array($c, ['left','right'], true) ? $c : 'left'; } return in_array($v, ['left','right'], true) ? $v : 'left'; }, 'default' => 'left' ]);
    }

    public static function customize_register( $wp_customize ) {
        if ( ! class_exists('WP_Customize_Control') ) return;
        // Section for The Bible footer appearance
        $wp_customize->add_section('thebible_footer_section', [
            'title'       => __('Bible Footer CSS','thebible'),
            'priority'    => 160,
            'description' => __('Custom CSS applied to the footer area rendered by The Bible plugin (.thebible-footer, .thebible-footer-title).','thebible'),
        ]);
        // Setting: footer-specific CSS
        $wp_customize->add_setting('thebible_footer_css', [
            'type'              => 'option',
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => function( $css ) { return is_string($css) ? $css : ''; },
            'default'           => '',
            'transport'         => 'refresh',
        ]);
        // Control: textarea for CSS
        $wp_customize->add_control('thebible_footer_css', [
            'section'  => 'thebible_footer_section',
            'label'    => __('Custom CSS for Bible Footer','thebible'),
            'type'     => 'textarea',
            'settings' => 'thebible_footer_css',
        ]);
    }

    public static function admin_menu() {
        // Top-level menu
        add_menu_page(
            'The Bible',
            'The Bible',
            'manage_options',
            'thebible',
            [ __CLASS__, 'render_settings_page' ],
            'dashicons-book-alt',
            58
        );

        // Sub-pages: main settings (default), OG image/layout, and per-Bible footers
        add_submenu_page(
            'thebible',
            'The Bible',
            'The Bible',
            'manage_options',
            'thebible',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'thebible',
            'OG Image & Layout',
            'OG Image & Layout',
            'manage_options',
            'thebible_og',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'thebible',
            'Footers',
            'Footers',
            'manage_options',
            'thebible_footers',
            [ __CLASS__, 'render_settings_page' ]
        );

        add_submenu_page(
            'thebible',
            'Verse Importer',
            'Verse Importer',
            'manage_options',
            'thebible_import',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function admin_enqueue($hook) {
        // Only enqueue on our settings pages (hook varies by WP version/menu title)
        // Match any hook containing 'thebible'
        if (strpos($hook, 'thebible') === false) {
            return;
        }
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }
        // Enqueue our admin media picker script (depends on wp.media via jquery)
        wp_enqueue_script(
            'thebible-admin-media',
            plugin_dir_url(__FILE__) . 'assets/admin-media.js',
            ['jquery'],
            '1.0.1',
            true
        );
    }

    public static function allow_font_uploads($mimes) {
        if (!is_array($mimes)) { $mimes = []; }
        // Common font MIME types
        $mimes['ttf'] = 'font/ttf';
        $mimes['otf'] = 'font/otf';
        $mimes['woff'] = 'font/woff';
        $mimes['woff2'] = 'font/woff2';
        // Some hosts map fonts as octet-stream; allow anyway to select in media library
        if (!isset($mimes['ttf'])) { $mimes['ttf'] = 'application/octet-stream'; }
        if (!isset($mimes['otf'])) { $mimes['otf'] = 'application/octet-stream'; }
        return $mimes;
    }

    public static function allow_font_filetype($data, $file, $filename, $mimes, $real_mime) {
        if (!current_user_can('manage_options')) return $data;
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['ttf','otf','woff','woff2'], true)) {
            $type = ($ext === 'otf') ? 'font/otf' : (($ext === 'ttf') ? 'font/ttf' : (($ext==='woff2')?'font/woff2':'font/woff'));
            return [ 'ext' => $ext, 'type' => $type, 'proper_filename' => $data['proper_filename'] ];
        }
        return $data;
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'thebible';

        $slugs_opt = get_option( 'thebible_slugs', 'bible,bibel' );
        $active = array_filter( array_map( 'trim', explode( ',', is_string($slugs_opt)?$slugs_opt:'' ) ) );
        $known = [ 'bible' => 'English (Douay)', 'bibel' => 'Deutsch (Menge)' ];
        $og_enabled = get_option('thebible_og_enabled','1');
        $og_w = intval(get_option('thebible_og_width',1200));
        $og_h = intval(get_option('thebible_og_height',630));
        $og_bg = (string) get_option('thebible_og_bg_color','#111111');
        $og_fg = (string) get_option('thebible_og_text_color','#ffffff');
        $og_font = (string) get_option('thebible_og_font_ttf','');
        $og_font_url = (string) get_option('thebible_og_font_url','');
        $og_size_legacy = intval(get_option('thebible_og_font_size',40));
        $og_size_main = intval(get_option('thebible_og_font_size_main', $og_size_legacy?:40));
        $og_size_ref  = intval(get_option('thebible_og_font_size_ref',  $og_size_legacy?:40));
        $og_min_main  = intval(get_option('thebible_og_min_font_size_main', 18));
        $og_img = (string) get_option('thebible_og_background_image_url','');
        // Layout & icon options for settings UI
        $og_pad_x = intval(get_option('thebible_og_padding_x', 50));
        $og_pad_top = intval(get_option('thebible_og_padding_top', 50));
        $og_pad_bottom = intval(get_option('thebible_og_padding_bottom', 50));
        $og_min_gap = intval(get_option('thebible_og_min_gap', 16));
        $og_icon_url = (string) get_option('thebible_og_icon_url','');
        $og_logo_side = (string) get_option('thebible_og_logo_side','left');
        $og_logo_pad_adjust = intval(get_option('thebible_og_logo_pad_adjust', 0));
        $og_logo_pad_adjust_x = intval(get_option('thebible_og_logo_pad_adjust_x', $og_logo_pad_adjust));
        $og_logo_pad_adjust_y = intval(get_option('thebible_og_logo_pad_adjust_y', 0));
        $og_icon_max_w = intval(get_option('thebible_og_icon_max_w', 160));
        $og_line_main = (string) get_option('thebible_og_line_height_main','1.35');
        $og_line_main_f = floatval($og_line_main ? $og_line_main : '1.35');
        $og_qL = (string) get_option('thebible_og_quote_left','»');
        $og_qR = (string) get_option('thebible_og_quote_right','«');
        $og_refpos = (string) get_option('thebible_og_ref_position','bottom');
        $og_refalign = (string) get_option('thebible_og_ref_align','left');

        // Handle footer save (all-at-once)
        if ( isset($_POST['thebible_footer_nonce_all']) && wp_verify_nonce( $_POST['thebible_footer_nonce_all'], 'thebible_footer_save_all' ) && current_user_can('manage_options') ) {
            foreach ($known as $fs => $label) {
                $field = 'thebible_footer_text_' . $fs;
                $ft = isset($_POST[$field]) ? (string) wp_unslash( $_POST[$field] ) : '';
                // New preferred location
                $root = plugin_dir_path(__FILE__) . 'data/' . $fs . '/';
                $ok = is_dir($root) || wp_mkdir_p($root);
                if ( $ok ) {
                    @file_put_contents( trailingslashit($root) . 'copyright.md', $ft );
                } else {
                    // Legacy fallback
                    $dir = plugin_dir_path(__FILE__) . 'data/' . $fs . '_books_html/';
                    if ( is_dir($dir) || wp_mkdir_p($dir) ) {
                        @file_put_contents( trailingslashit($dir) . 'copyright.txt', $ft );
                    }
                }
            }
            echo '<div class="updated notice"><p>Footers saved.</p></div>';
        }
        // Handle OG layout reset to safe defaults
        if ( isset($_POST['thebible_og_reset_defaults_nonce']) && wp_verify_nonce($_POST['thebible_og_reset_defaults_nonce'],'thebible_og_reset_defaults') && current_user_can('manage_options') ) {
            update_option('thebible_og_enabled', '1');
            update_option('thebible_og_width', 1600);
            update_option('thebible_og_height', 900);
            update_option('thebible_og_bg_color', '#111111');
            update_option('thebible_og_text_color', '#ffffff');
            update_option('thebible_og_font_size', 60);
            update_option('thebible_og_font_size_main', 60);
            update_option('thebible_og_font_size_ref', 40);
            update_option('thebible_og_min_font_size_main', 24);
            update_option('thebible_og_padding_x', 60);
            update_option('thebible_og_padding_top', 60);
            update_option('thebible_og_padding_bottom', 60);
            update_option('thebible_og_min_gap', 30);
            update_option('thebible_og_line_height_main', '1.35');
            update_option('thebible_og_logo_side', 'left');
            update_option('thebible_og_logo_pad_adjust', 0);
            update_option('thebible_og_logo_pad_adjust_x', 0);
            update_option('thebible_og_logo_pad_adjust_y', 0);
            update_option('thebible_og_icon_max_w', 200);
            update_option('thebible_og_quote_left', '«');
            update_option('thebible_og_quote_right', '»');
            update_option('thebible_og_ref_position', 'bottom');
            update_option('thebible_og_ref_align', 'left');
            // Note: font_url, icon_url, background_image_url are NOT reset to preserve user uploads
            $deleted = TheBible_OG_Image::og_cache_purge();
            echo '<div class="updated notice"><p>OG layout and typography reset to safe defaults (1600×900). Cache cleared (' . intval($deleted) . ' files removed).</p></div>';
        }
        // Handle cache purge
        if ( isset($_POST['thebible_og_purge_cache_nonce']) && wp_verify_nonce($_POST['thebible_og_purge_cache_nonce'],'thebible_og_purge_cache') && current_user_can('manage_options') ) {
            $deleted = TheBible_OG_Image::og_cache_purge();
            echo '<div class="updated notice"><p>OG image cache cleared (' . intval($deleted) . ' files removed).</p></div>';
        }
        if ( isset($_POST['thebible_regen_sitemaps_nonce']) && wp_verify_nonce($_POST['thebible_regen_sitemaps_nonce'],'thebible_regen_sitemaps') && current_user_can('manage_options') ) {
            $slugs = self::base_slugs();
            foreach ($slugs as $slug) {
                $slug = trim($slug, "/ ");
                if ($slug !== 'bible' && $slug !== 'bibel') continue;
                $path = ($slug === 'bible') ? '/bible-sitemap-bible.xml' : '/bible-sitemap-bibel.xml';
                $url = home_url($path);
                wp_remote_get($url, ['timeout' => 10]);
            }
            echo '<div class="updated notice"><p>Bible sitemaps refreshed. If generation is heavy, it may take a moment for all URLs to be crawled.</p></div>';
        }

        // Handle Verse Importer CSV (fills free dates from today onwards)
        if ( isset($_POST['thebible_import_nonce']) && wp_verify_nonce($_POST['thebible_import_nonce'],'thebible_import') && current_user_can('manage_options') ) {
            $raw_csv = isset($_POST['thebible_import_csv']) ? (string) wp_unslash($_POST['thebible_import_csv']) : '';
            $today_str = current_time('Y-m-d');
            if ($raw_csv !== '') {
                $lines = preg_split("/\r\n|\r|\n/", $raw_csv);
                $header = null;
                $rows = [];
                foreach ($lines as $line) {
                    $line = trim((string) $line);
                    if ($line === '') continue;
                    if ($header === null) {
                        $header = str_getcsv($line);
                    } else {
                        $rows[] = str_getcsv($line);
                    }
                }
                $created = 0;
                if (is_array($header) && !empty($rows)) {
                    $index = [];
                    foreach ($header as $i => $name) {
                        $name = strtolower(trim((string)$name));
                        if ($name !== '') { $index[$name] = $i; }
                    }
                    // Only citation fields are required; dataset_slug/date/text/note columns are ignored by the importer
                    $required = ['canonical_book_key','chapter','verse_from','verse_to'];
                    $has_all = true;
                    foreach ($required as $key) {
                        if (!isset($index[$key])) { $has_all = false; break; }
                    }
                    if ($has_all) {
                        $cursor = new DateTime($today_str);
                        $by_date = get_option('thebible_votd_by_date', []);
                        $used = [];
                        if (is_array($by_date)) {
                            foreach ($by_date as $d => $_entry) {
                                if (is_string($d) && $d !== '' && $d >= $today_str) {
                                    $used[$d] = true;
                                }
                            }
                        }
                        foreach ($rows as $cols) {
                            // Extract core fields
                            $book_key = isset($cols[$index['canonical_book_key']]) ? trim((string)$cols[$index['canonical_book_key']]) : '';
                            $chapter  = isset($cols[$index['chapter']]) ? (int)$cols[$index['chapter']] : 0;
                            $vfrom    = isset($cols[$index['verse_from']]) ? (int)$cols[$index['verse_from']] : 0;
                            $vto      = isset($cols[$index['verse_to']]) ? (int)$cols[$index['verse_to']] : 0;
                            if ($book_key === '' || $chapter <= 0 || $vfrom <= 0) {
                                continue;
                            }
                            if ($vto <= 0 || $vto < $vfrom) {
                                $vto = $vfrom;
                            }

                            // Find next free date from cursor onwards
                            while (true) {
                                $d = $cursor->format('Y-m-d');
                                if (!isset($used[$d])) {
                                    break;
                                }
                                $cursor = $cursor->modify('+1 day');
                            }
                            $assigned_date = $cursor->format('Y-m-d');
                            $used[$assigned_date] = true;
                            // Advance cursor for next verse
                            $cursor = $cursor->modify('+1 day');

                            // Create VOTD post
                            $post_id = wp_insert_post([
                                'post_type'   => 'thebible_votd',
                                'post_status' => 'publish',
                                'post_title'  => '',
                                'post_content'=> '',
                            ], true);
                            if (is_wp_error($post_id) || !$post_id) {
                                continue;
                            }

                            update_post_meta($post_id, '_thebible_votd_book', $book_key);
                            update_post_meta($post_id, '_thebible_votd_chapter', $chapter);
                            update_post_meta($post_id, '_thebible_votd_vfrom', $vfrom);
                            update_post_meta($post_id, '_thebible_votd_vto', $vto);
                            update_post_meta($post_id, '_thebible_votd_date', $assigned_date);

                            // Generate a title like save_votd_meta() does
                            $entry = self::normalize_votd_entry(get_post($post_id));
                            if (is_array($entry)) {
                                $book_key_norm = $entry['book_slug'];
                                $short = self::resolve_book_for_dataset($book_key_norm, 'bible');
                                if (!is_string($short) || $short === '') {
                                    $label = ucwords(str_replace('-', ' ', (string) $book_key_norm));
                                } else {
                                    $label = self::pretty_label($short);
                                }
                                $ref = $label . ' ' . $entry['chapter'] . ':' . ($entry['vfrom'] === $entry['vto'] ? $entry['vfrom'] : ($entry['vfrom'] . '-' . $entry['vto']));
                                $title = $ref . ' (' . $entry['date'] . ')';
                                wp_update_post([
                                    'ID'         => $post_id,
                                    'post_title' => $title,
                                    'post_name'  => sanitize_title($title),
                                ]);
                            }

                            $created++;
                        }
                        // Rebuild VOTD cache once after import
                        if ($created > 0) {
                            self::rebuild_votd_cache();
                        }
                    }
                }

                echo '<div class="updated notice"><p>Verse importer created ' . intval($created) . ' new Verse-of-the-Day entries, filling free dates from ' . esc_html($today_str) . ' onward.</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1>The Bible</h1>

            <?php if ( $page === 'thebible' ) : ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'thebible_options' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label>Active bibles</label></th>
                            <td>
                                <?php foreach ( $known as $slug => $label ): $checked = in_array($slug, $active, true); ?>
                                    <label style="display:block;margin:.2em 0;">
                                        <input type="checkbox" name="thebible_slugs_list[]" value="<?php echo esc_attr($slug); ?>" <?php checked( $checked ); ?>>
                                        <code>/<?php echo esc_html($slug); ?>/</code> — <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                                <input type="hidden" name="thebible_slugs" id="thebible_slugs" value="<?php echo esc_attr( implode(',', $active ) ); ?>">
                                <script>(function(){function sync(){var boxes=document.querySelectorAll('input[name=\"thebible_slugs_list[]\"]');var out=[];boxes.forEach(function(b){if(b.checked) out.push(b.value);});document.getElementById('thebible_slugs').value=out.join(',');}document.addEventListener('change',function(e){if(e.target && e.target.name==='thebible_slugs_list[]'){sync();}});document.addEventListener('DOMContentLoaded',sync);})();</script>
                                <p class="description">Select which bibles are publicly accessible. Others remain installed but routed pages are disabled.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Sitemaps</label></th>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('thebible_regen_sitemaps','thebible_regen_sitemaps_nonce'); ?>
                                    <button type="submit" class="button">Refresh Bible sitemaps</button>
                                </form>
                                <?php
                                $active_slugs = $active;
                                $links = [];
                                if (in_array('bible', $active_slugs, true)) {
                                    $links[] = '<a href="' . esc_url( home_url('/bible-sitemap-bible.xml') ) . '" target="_blank" rel="noopener noreferrer">English sitemap</a>';
                                }
                                if (in_array('bibel', $active_slugs, true)) {
                                    $links[] = '<a href="' . esc_url( home_url('/bible-sitemap-bibel.xml') ) . '" target="_blank" rel="noopener noreferrer">German sitemap</a>';
                                }
                                ?>
                                <p class="description">Triggers regeneration of per-verse Bible sitemaps for active bibles by requesting their sitemap URLs on the server. <?php if (!empty($links)) { echo 'View: ' . implode(' | ', $links); } ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_export_bible_slug">Export Bible as .txt</label></th>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                                    <?php wp_nonce_field('thebible_export_bible','thebible_export_bible_nonce'); ?>
                                    <input type="hidden" name="action" value="thebible_export_bible">
                                    <label for="thebible_export_bible_slug">Bible:</label>
                                    <select name="thebible_export_bible_slug" id="thebible_export_bible_slug">
                                        <?php foreach ($known as $slug => $label): ?>
                                            <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="button">Download .txt</button>
                                    <p class="description">Downloads a plain-text file with one verse per line in a machine-friendly format.</p>
                                </form>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php elseif ( $page === 'thebible_og' ) : ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'thebible_options' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>

                        <tr>
                            <th scope="row"><label>Quotation marks</label></th>
                            <td>
                                <p><strong>OG images and widgets always use fixed outer guillemets:</strong> opening  bb and closing  ab. These marks are not configurable.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_ref_position">Reference position</label></th>
                            <td>
                                <select name="thebible_og_ref_position" id="thebible_og_ref_position">
                                    <option value="bottom" <?php selected($og_refpos==='bottom'); ?>>Bottom</option>
                                    <option value="top" <?php selected($og_refpos==='top'); ?>>Top</option>
                                </select>
                                &nbsp;
                                <label for="thebible_og_ref_align">Alignment</label>
                                <select name="thebible_og_ref_align" id="thebible_og_ref_align">
                                    <option value="left" <?php selected($og_refalign==='left'); ?>>Left</option>
                                    <option value="right" <?php selected($og_refalign==='right'); ?>>Right</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_enabled">Social image (Open Graph)</label></th>
                            <td>
                                <label><input type="checkbox" name="thebible_og_enabled" id="thebible_og_enabled" value="1" <?php checked($og_enabled==='1'); ?>> Enable dynamic image for verse URLs</label>
                                <p class="description">Generates a PNG for <code>og:image</code> when a URL includes chapter and verse, e.g. <code>/bible/john/3:16</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_width">Image size</label></th>
                            <td>
                                <input type="number" min="100" name="thebible_og_width" id="thebible_og_width" value="<?php echo esc_attr($og_w); ?>" style="width:7em;"> ×
                                <input type="number" min="100" name="thebible_og_height" id="thebible_og_height" value="<?php echo esc_attr($og_h); ?>" style="width:7em;"> px
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_bg_color">Colors</label></th>
                            <td>
                                <input type="text" name="thebible_og_bg_color" id="thebible_og_bg_color" value="<?php echo esc_attr($og_bg); ?>" placeholder="#111111" style="width:8em;"> background
                                <span style="display:inline-block;width:1.2em;height:1.2em;vertical-align:middle;border:1px solid #ccc;background:<?php echo esc_attr($og_bg); ?>"></span>
                                &nbsp; <input type="text" name="thebible_og_text_color" id="thebible_og_text_color" value="<?php echo esc_attr($og_fg); ?>" placeholder="#ffffff" style="width:8em;"> text
                                <span style="display:inline-block;width:1.2em;height:1.2em;vertical-align:middle;border:1px solid #ccc;background:<?php echo esc_attr($og_fg); ?>"></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_font_ttf">Font</label></th>
                            <td>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Server path: <input type="text" name="thebible_og_font_ttf" id="thebible_og_font_ttf" value="<?php echo esc_attr($og_font); ?>" class="regular-text" placeholder="/path/to/font.ttf"></label>
                                </p>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Or uploaded URL: <input type="url" name="thebible_og_font_url" id="thebible_og_font_url" value="<?php echo esc_attr($og_font_url); ?>" class="regular-text" placeholder="https://.../yourfont.ttf"></label>
                                    <button type="button" class="button" id="thebible_pick_font">Select/upload font</button>
                                </p>
                                <p class="description">TTF/OTF recommended. If path is invalid, the uploader URL will be mapped to a local file under Uploads. Without a valid font file, non‑ASCII quotes may fall back to straight quotes.</p>
                                <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                                    <label>Max main size <input type="number" min="8" name="thebible_og_font_size_main" id="thebible_og_font_size_main" value="<?php echo esc_attr($og_size_main); ?>" style="width:6em;"></label>
                                    <label>Min main size <input type="number" min="8" name="thebible_og_min_font_size_main" id="thebible_og_min_font_size_main" value="<?php echo esc_attr($og_min_main); ?>" style="width:6em;"></label>
                                    <label>Max source size <input type="number" min="8" name="thebible_og_font_size_ref" id="thebible_og_font_size_ref" value="<?php echo esc_attr($og_size_ref); ?>" style="width:6em;"></label>
                                    <label>Line height (main) <input type="number" step="0.05" min="1" name="thebible_og_line_height_main" id="thebible_og_line_height_main" value="<?php echo esc_attr($og_line_main); ?>" style="width:6em;"></label>
                                </div>
                                <p class="description">Main text auto-shrinks between Max and Min. If still too long at Min, it is truncated with … Source uses up to its max size and wraps as needed.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Cache</label></th>
                            <td>
                                <form method="post" style="display:inline;margin-right:0.5em;">
                                    <?php wp_nonce_field('thebible_og_purge_cache','thebible_og_purge_cache_nonce'); ?>
                                    <button type="submit" class="button">Clear cached images</button>
                                </form>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('thebible_og_reset_defaults','thebible_og_reset_defaults_nonce'); ?>
                                    <button type="submit" class="button button-secondary">Reset layout to safe defaults</button>
                                </form>
                                <p class="description">Cached OG images are stored under Uploads/thebible-og-cache and reused for identical requests. Clear the cache after changing design settings. Use the reset button if layout values became extreme and the verse/logo no longer show.</p>
                                <p class="description">For a one-off debug render that skips the cache, append <code>&thebible_og_nocache=1</code> to a verse URL that already has <code>thebible_og=1</code>, for example: <code>?thebible_og=1&amp;thebible_og_nocache=1</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_background_image_url">Background image</label></th>
                            <td>
                                <p style="margin:.2em 0 .6em;">
                                    <input type="url" name="thebible_og_background_image_url" id="thebible_og_background_image_url" value="<?php echo esc_attr($og_img); ?>" class="regular-text" placeholder="https://.../image.jpg">
                                    <button type="button" class="button" id="thebible_pick_bg">Select/upload image</button>
                                </p>
                                <p class="description">Optional. If set, the image is used as a cover background with a dark overlay for readability.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Layout</label></th>
                            <td>
                                <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                                    <label>Side padding <input type="number" min="0" name="thebible_og_padding_x" id="thebible_og_padding_x" value="<?php echo esc_attr($og_pad_x); ?>" style="width:6em;"> px</label>
                                    <label>Top padding <input type="number" min="0" name="thebible_og_padding_top" id="thebible_og_padding_top" value="<?php echo esc_attr($og_pad_top); ?>" style="width:6em;"> px</label>
                                    <label>Bottom padding <input type="number" min="0" name="thebible_og_padding_bottom" id="thebible_og_padding_bottom" value="<?php echo esc_attr($og_pad_bottom); ?>" style="width:6em;"> px</label>
                                    <label>Min gap text↔source <input type="number" min="0" name="thebible_og_min_gap" id="thebible_og_min_gap" value="<?php echo esc_attr($og_min_gap); ?>" style="width:6em;"> px</label>
                                </div>
                                <p class="description">Set exact paddings for sides, top, and bottom. The min gap enforces spacing between the main text and the bottom row.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_icon_url">Icon</label></th>
                            <td>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Icon URL: <input type="url" name="thebible_og_icon_url" id="thebible_og_icon_url" value="<?php echo esc_attr($og_icon_url); ?>" class="regular-text" placeholder="https://.../icon.png"></label>
                                    <button type="button" class="button" id="thebible_pick_icon">Select/upload image</button>
                                </p>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Logo side 
                                        <select name="thebible_og_logo_side" id="thebible_og_logo_side">
                                            <option value="left" <?php selected($og_logo_side==='left'); ?>>Left</option>
                                            <option value="right" <?php selected($og_logo_side==='right'); ?>>Right</option>
                                        </select>
                                    </label>
                                    &nbsp;
                                    <label>Logo padding X <input type="number" name="thebible_og_logo_pad_adjust_x" id="thebible_og_logo_pad_adjust_x" value="<?php echo esc_attr($og_logo_pad_adjust_x); ?>" style="width:6em;"> px</label>
                                    &nbsp;
                                    <label>Logo padding Y <input type="number" name="thebible_og_logo_pad_adjust_y" id="thebible_og_logo_pad_adjust_y" value="<?php echo esc_attr($og_logo_pad_adjust_y); ?>" style="width:6em;"> px</label>
                                    &nbsp;
                                    <label>Max width <input type="number" min="1" name="thebible_og_icon_max_w" id="thebible_og_icon_max_w" value="<?php echo esc_attr($og_icon_max_w); ?>" style="width:6em;"> px</label>
                                </p>
                                <p class="description">Logo and source are always at the bottom. Choose which side holds the logo; the source uses the other side. Logo padding X/Y shift the logo relative to side/bottom padding (can be negative). Use raster images such as PNG or JPEG; SVG and other vector formats are not supported by the image renderer.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php elseif ( $page === 'thebible_import' ) : ?>

            <h2>Verse Importer (CSV)</h2>

            <form method="post">
                <?php wp_nonce_field('thebible_import','thebible_import_nonce'); ?>

            <p class="description">
                This page documents a machine-friendly CSV format for importing verses into The Bible plugin.
                Paste CSV data into the textarea below to be consumed by an external importer or future automation.
                No data is imported yet; this UI is documentation and a staging area only.
            </p>

            <h3>CSV format overview</h3>
            <p>
                The importer expects a UTF-8 CSV with a header row and one verse (or verse range) per line.
                Columns are designed to be unambiguous for an AI or script:
            </p>

            <table class="widefat striped" style="max-width:960px;margin-top:1em;">
                <thead>
                    <tr>
                        <th scope="col">Column</th>
                        <th scope="col">Required?</th>
                        <th scope="col">Example</th>
                        <th scope="col">Meaning</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>canonical_book_key</code></td>
                        <td>Yes</td>
                        <td><code>john</code>, <code>psalms</code></td>
                        <td>
                            Canonical book key as used by <code>book_map.json</code> and VOTD (see <code>list_canonical_books()</code>).
                            The mapping in <code>book_map.json</code> determines the correct title for each language, so no separate bible/bibel column is needed.
                        </td>
                    </tr>
                    <tr>
                        <td><code>chapter</code></td>
                        <td>Yes</td>
                        <td><code>3</code></td>
                        <td>Positive integer chapter number within the book.</td>
                    </tr>
                    <tr>
                        <td><code>verse_from</code></td>
                        <td>Yes</td>
                        <td><code>16</code></td>
                        <td>First verse number in the range (inclusive).</td>
                    </tr>
                    <tr>
                        <td><code>verse_to</code></td>
                        <td>Optional</td>
                        <td><code>18</code> or empty</td>
                        <td>Last verse number in the range (inclusive). If empty or &lt; <code>verse_from</code>, treat as a single verse.</td>
                    </tr>
                    <tr>
                        <td><code>date</code></td>
                        <td>Ignored</td>
                        <td>(leave empty)</td>
                        <td>
                            The importer always assigns dates automatically from today forward, filling free days.
                            You may omit this column entirely or leave it empty; it is not read.
                        </td>
                    </tr>
                </tbody>
            </table>

            <h3>Header and example rows</h3>
            <p>Recommended header line (only citation fields):</p>
            <pre class="code">canonical_book_key,chapter,verse_from,verse_to</pre>

            <p>Example lines (one single verse and one range):</p>
            <pre class="code" style="white-space:pre-wrap;">
john,3,16,
johannes,3,16,18
            </pre>

            <h3>Staging textarea</h3>
            <p>
                Use this textarea as a scratchpad when preparing CSV data (for example, when collaborating with an AI that generates verses).
                The prefilled text below is written as direct instructions that an AI can follow to emit valid CSV for this importer.
            </p>

            <?php
                $instructions_file = plugin_dir_path( __FILE__ ) . 'assets/verse-csv-instructions.txt';
                $instructions      = '';
                if ( file_exists( $instructions_file ) ) {
                    $instructions = (string) file_get_contents( $instructions_file );
                }
            ?>
            <textarea class="large-text code" rows="16" style="max-width:960px;" name="thebible_import_csv"><?php echo esc_textarea( $instructions ); ?></textarea>

                <?php submit_button( __( 'Import verses (fill free dates from today)', 'thebible' ) ); ?>
            </form>

            <?php endif; // $page === 'thebible' / 'thebible_og' / 'thebible_import' ?>

            <?php if ( $page === 'thebible_footers' ) : ?>

            <h2>Per‑Bible footers</h2>
            <form method="post">
                <?php wp_nonce_field('thebible_footer_save_all', 'thebible_footer_nonce_all'); ?>
                <p class="description">Preferred location: <code>wp-content/plugins/thebible/data/{slug}/copyright.md</code>. Legacy fallback: <code>data/{slug}_books_html/copyright.txt</code>.</p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach ($known as $slug => $label): ?>
                        <?php
                            // Load existing footer for display
                            $root = plugin_dir_path(__FILE__) . 'data/' . $slug . '/';
                            $val = '';
                            if ( file_exists( $root . 'copyright.md' ) ) {
                                $val = (string) file_get_contents( $root . 'copyright.md' );
                            } else {
                                $legacy = plugin_dir_path(__FILE__) . 'data/' . $slug . '_books_html/copyright.txt';
                                if ( file_exists( $legacy ) ) { $val = (string) file_get_contents( $legacy ); }
                            }
                        ?>
                        <tr>
                            <th scope="row"><label for="thebible_footer_text_<?php echo esc_attr($slug); ?>"><?php echo esc_html('/' . $slug . '/ — ' . $label); ?></label></th>
                            <td>
                                <textarea name="thebible_footer_text_<?php echo esc_attr($slug); ?>" id="thebible_footer_text_<?php echo esc_attr($slug); ?>" class="large-text code" rows="6" style="font-family:monospace;"><?php echo esc_textarea( $val ); ?></textarea>
                                <p class="description">Markdown supported for links and headings; line breaks are preserved.</p>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button('Save Footers'); ?>
            </form>

            <h2>CSS reference</h2>
            <div class="thebible-css-reference" style="max-width:900px;">
                <p>Selectors you can target:</p>
                <ul style="list-style:disc;margin-left:1.2em;">
                    <li><code>.thebible</code> wrapper on all plugin output</li>
                    <li><code>.thebible-index</code> on /bible</li>
                    <li><code>.thebible-book</code> around a rendered book</li>
                    <li><code>.chapters</code> list of chapter links on top of a book</li>
                    <li><code>.verses</code> blocks of verses</li>
                    <li><code>.verse</code> each verse paragraph (added at render time)</li>
                    <li><code>.verse-num</code> the verse number span within a verse paragraph</li>
                    <li><code>.verse-body</code> the verse text span within a verse paragraph</li>
                    <li><code>.verse-num</code> the verse number span within a verse paragraph</li>
                    <li><code>.verse-body</code> the verse text span within a verse paragraph</li>
                    <li><code>.verse-highlight</code> added when a verse is highlighted from a URL fragment</li>
                    <li><code>.thebible-sticky</code> top status bar with chapter info and controls
                        <ul style="list-style:circle;margin-left:1.2em;">
                            <li><code>.thebible-sticky__left</code>, <code>[data-label]</code>, <code>[data-ch]</code></li>
                            <li><code>.thebible-sticky__controls</code> with <code>.thebible-ctl</code> buttons (<code>[data-prev]</code>, <code>[data-top]</code>, <code>[data-next]</code>)</li>
                        </ul>
                    </li>
                    <li><code>.thebible-up</code> small up-arrow links inserted before chapters/verses</li>
                </ul>
                <p>Anchors and IDs:</p>
                <ul style="list-style:disc;margin-left:1.2em;">
                    <li>At very top of each book: <code>#thebible-book-top</code></li>
                    <li>Chapter headings: <code>h2[id^="{book-slug}-ch-"]</code>, e.g. <code>#sophonias-ch-3</code></li>
                    <li>Verse paragraphs: <code>p[id^="{book-slug}-"]</code> with pattern <code>{slug}-{chapter}-{verse}</code>, e.g. <code>#sophonias-3-5</code></li>
                </ul>
            </div>

            <?php endif; // $page === 'thebible_footers' ?>
        </div>
        <?php
    }

    public static function handle_export_bible_txt() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        if (!isset($_POST['thebible_export_bible_nonce']) || !wp_verify_nonce($_POST['thebible_export_bible_nonce'], 'thebible_export_bible')) {
            wp_die('Invalid request');
        }
        $slug = isset($_POST['thebible_export_bible_slug']) ? sanitize_text_field(wp_unslash($_POST['thebible_export_bible_slug'])) : '';
        if ($slug !== 'bible' && $slug !== 'bibel') {
            wp_die('Unknown bible');
        }
        $root = plugin_dir_path(__FILE__) . 'data/' . $slug . '/';
        $text_dir = trailingslashit($root) . 'text/';
        $html_dir = trailingslashit($root) . 'html/';
        $index = '';
        if (file_exists($text_dir . 'index.csv')) {
            $index = $text_dir . 'index.csv';
        } elseif (file_exists($html_dir . 'index.csv')) {
            $index = $html_dir . 'index.csv';
        }
        if ($index === '') {
            wp_die('Text data not available for export');
        }
        $fh = fopen($index, 'r');
        if (!$fh) {
            wp_die('Could not open text index');
        }
        $rows = [];
        $header = fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if (!is_array($row) || count($row) < 4) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($fh);
        if (empty($rows)) {
            wp_die('No books found for export');
        }
        nocache_headers();
        status_header(200);
        header('Content-Type: text/plain; charset=UTF-8');
        $filename = 'thebible-' . $slug . '.txt';
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'wb');
        if (!$out) {
            wp_die('Could not open output stream');
        }
        $bible_name = ($slug === 'bibel') ? 'Deutsch (Menge)' : 'English (Douay-Rheims)';
        fwrite($out, $bible_name . "\n");
        fwrite($out, "schema|slug|book|chapter|verse|text\n");
        foreach ($rows as $row) {
            $short = isset($row[1]) ? (string) $row[1] : '';
            $file = isset($row[3]) ? (string) $row[3] : '';
            if ($file === '') {
                continue;
            }
            $book_key = self::slugify($short);
            // Prefer text/ source, falling back to same file under html/ if needed.
            $path_text = $text_dir . preg_replace('/\.html$/i', '.txt', $file);
            $path = file_exists($path_text) ? $path_text : trailingslashit(dirname($index)) . $file;
            if (!file_exists($path)) {
                continue;
            }
            $tfh = fopen($path, 'r');
            if (!$tfh) {
                continue;
            }
            $chapter = 0;
            while (($line = fgets($tfh)) !== false) {
                $trim = trim($line);
                if ($trim === '' || $trim[0] === '#') {
                    if (strpos($trim, '## Chapter') === 0) {
                        if (preg_match('/^##\s+Chapter\s+(\d+)/i', $trim, $m)) {
                            $chapter = (int) $m[1];
                        }
                    }
                    continue;
                }
                if (!preg_match('/^(\d+):(\d+)\s+(.*)$/u', $trim, $m)) {
                    continue;
                }
                $v_ch = (int) $m[1];
                $v_vs = (int) $m[2];
                $text = trim($m[3]);
                $ch_out = $chapter > 0 ? $chapter : $v_ch;
                $line_out = $slug . '|' . $book_key . '|' . $ch_out . '|' . $v_vs . '|' . $text . "\n";
                fwrite($out, $line_out);
            }
            fclose($tfh);
        }
        fclose($out);
        exit;
    }

    public static function print_custom_css() {
        $is_bible = get_query_var( self::QV_FLAG );
        if ( ! $is_bible ) return;
        $footer_css = get_option( 'thebible_footer_css', '' );
        $out = '';
        if ( is_string($footer_css) && $footer_css !== '' ) { $out .= $footer_css . "\n"; }
        if ( $out !== '' ) {
            echo '<style id="thebible-custom-css">' . $out . '</style>';
        }
    }

    public static function print_og_meta() {
        $flag = get_query_var(self::QV_FLAG);
        if (!$flag) return;
        $book = get_query_var(self::QV_BOOK);
        $ch = absint(get_query_var(self::QV_CHAPTER));
        $vf = absint(get_query_var(self::QV_VFROM));
        $vt = absint(get_query_var(self::QV_VTO));
        if (!$book || !$ch || !$vf) return;
        if (!$vt || $vt < $vf) $vt = $vf;

        $entry = self::get_book_entry_by_slug($book);
        if (!$entry) return;
        $label = isset($entry['display_name']) && $entry['display_name'] !== '' ? $entry['display_name'] : self::pretty_label($entry['short_name']);
        $title = $label . ' ' . $ch . ':' . ($vf === $vt ? $vf : ($vf . '-' . $vt));

        $base_slug = get_query_var(self::QV_SLUG);
        if (!is_string($base_slug) || $base_slug==='') $base_slug = 'bible';
        $path = '/' . trim($base_slug,'/') . '/' . trim($book,'/') . '/' . $ch . ':' . $vf . ($vt>$vf?('-'.$vt):'');
        $url = home_url($path);
        $og_url = add_query_arg(self::QV_OG, '1', $url);
        $desc = self::extract_verse_text($entry, $ch, $vf, $vt);
        $desc = wp_strip_all_tags($desc);

        echo "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta property="og:image" content="' . esc_url($og_url) . '" />' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url($og_url) . '" />' . "\n";
    }

    private static function render_footer_html() {
        // Prefer new markdown footer at dataset root, fallback to old copyright.txt in html dir
        $raw = '';
        $root = self::data_root_dir();
        if ($root) {
            $md = trailingslashit($root) . 'copyright.md';
            if (file_exists($md)) {
                $raw = (string) file_get_contents($md);
            }
        }
        if ($raw === '') {
            $txt_path = self::html_dir() . 'copyright.txt';
            if (file_exists($txt_path)) { $raw = (string) file_get_contents($txt_path); }
        }
        if (!is_string($raw) || $raw === '') return '';
        // Very light Markdown to HTML: allow links and simple headings; escape everything else
        $safe = esc_html($raw);
        // Convert [text](url) style links
        $safe = preg_replace('/\[(.*?)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $safe);
        // Split into lines and build blocks: headings or paragraphs
        $lines = preg_split('/\r?\n/', $safe);
        $blocks = [];
        $para = [];
        $flush_para = function() use (&$para, &$blocks) {
            if (!empty($para)) {
                // Join paragraph lines with spaces
                $text = trim(preg_replace('/\s+/', ' ', implode(' ', array_map('trim', $para))));
                if ($text !== '') { $blocks[] = '<p>' . $text . '</p>'; }
                $para = [];
            }
        };
        foreach ($lines as $ln) {
            if (preg_match('/^###\s+(.*)$/', $ln, $m)) { $flush_para(); $blocks[] = '<h3 class="thebible-footer-title">' . $m[1] . '</h3>'; continue; }
            if (preg_match('/^##\s+(.*)$/', $ln, $m))  { $flush_para(); $blocks[] = '<h2 class="thebible-footer-title">' . $m[1] . '</h2>'; continue; }
            if (preg_match('/^#\s+(.*)$/', $ln, $m))   { $flush_para(); $blocks[] = '<h1 class="thebible-footer-title">' . $m[1] . '</h1>'; continue; }
            if (trim($ln) === '') { $flush_para(); continue; }
            $para[] = $ln;
        }
        $flush_para();
        return '<footer class="thebible-footer">' . implode('', $blocks) . '</footer>';
    }
}

TheBible_Plugin::init();

require_once plugin_dir_path(__FILE__) . 'includes/class-thebible-votd-widget.php';

