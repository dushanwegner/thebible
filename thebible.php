<?php
/**
 * Plugin Name: The Bible
 * Description: Provides /bible/ with links to books; renders selected book HTML using the site's template.
 * Version: 0.1.0
 * Author: DW
 * License: GPL2+
 */

if (!defined('ABSPATH')) { exit; }

class TheBible_Plugin {
    const QV_FLAG = 'thebible';
    const QV_BOOK = 'thebible_book';

    private static $books = null; // array of [order, short_name, filename]
    private static $slug_map = null; // slug => array entry

    public static function init() {
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);
        add_action('template_redirect', [__CLASS__, 'handle_template_redirect']);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    private static function inject_nav_helpers($html) {
        if (!is_string($html) || $html === '') return $html;

        // Ensure a stable anchor at the very top of the book content
        if (strpos($html, 'id="thebible-book-top"') === false && strpos($html, 'id=\"thebible-book-top\"') === false) {
            $html = '<a id="thebible-book-top"></a>' . $html;
        }

        // Prepend an up-arrow to the first chapters block linking back to /bible/
        $bible_index = esc_url(trailingslashit(home_url('/bible/')));
        $chap_up = '<a class="thebible-up thebible-up-index" href="' . $bible_index . '" aria-label="Back to Bible">&#8593;</a> ';
        $html = preg_replace(
            '~<p\s+class=(["\"])chapters\1>~',
            '<p class="chapters">' . $chap_up,
            $html,
            1
        );

        // Prepend an up-arrow to every verses block linking back to top of book
        $book_top = '#thebible-book-top';
        $vers_up = '<a class="thebible-up thebible-up-book" href="' . $book_top . '" aria-label="Back to book">&#8593;</a> ';
        $html = preg_replace(
            '~<p\s+class=(["\"])verses\1>~',
            '<p class="verses">' . $vers_up,
            $html
        );

        return $html;
    }

    public static function activate() {
        self::add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule('^bible/?$', 'index.php?' . self::QV_FLAG . '=1', 'top');
        add_rewrite_rule('^bible/([^/]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_FLAG . '=1', 'top');
    }

    public static function add_query_vars($vars) {
        $vars[] = self::QV_FLAG;
        $vars[] = self::QV_BOOK;
        return $vars;
    }

    private static function data_dir() {
        return plugin_dir_path(__FILE__) . 'data/bible_books_html/';
    }

    private static function index_csv_path() {
        return self::data_dir() . 'index.csv';
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
                $filename = $row[2];
                $entry = [
                    'order' => $order,
                    'short_name' => $short,
                    'filename' => $filename,
                ];
                self::$books[] = $entry;
                $slug = self::slugify($short);
                self::$slug_map[$slug] = $entry;
            }
            fclose($fh);
        }
    }

    private static function slugify($name) {
        $slug = strtolower($name);
        $slug = str_replace([' ', '__'], ['-', '-'], $slug);
        $slug = str_replace(['_', '\\', '/'], ['-', '-', '-'], $slug);
        $slug = preg_replace('/[^a-z0-9\-]+/', '', $slug);
        $slug = preg_replace('/\-+/', '-', $slug);
        return trim($slug, '-');
    }

    private static function book_groups() {
        self::load_index();
        $ot = [];
        $nt = [];
        foreach (self::$books as $b) {
            if ($b['order'] <= 46) $ot[] = $b; else $nt[] = $b;
        }
        return [$ot, $nt];
    }

    public static function handle_template_redirect() {
        $flag = get_query_var(self::QV_FLAG);
        if (!$flag) return;

        // Prepare title and content
        $book_slug = get_query_var(self::QV_BOOK);
        if ($book_slug) {
            self::render_book($book_slug);
        } else {
            self::render_index();
        }
        exit; // prevent WP from continuing
    }

    private static function render_index() {
        self::load_index();
        status_header(200);
        nocache_headers();
        $title = 'The Bible';
        $content = self::build_index_html();
        self::output_with_theme($title, $content);
    }

    private static function render_book($slug) {
        self::load_index();
        $entry = self::$slug_map[$slug] ?? null;
        if (!$entry) {
            self::render_404();
            return;
        }
        $file = self::data_dir() . $entry['filename'];
        if (!file_exists($file)) {
            self::render_404();
            return;
        }
        $html = file_get_contents($file);
        // Inject navigation helpers: top anchor, up to /bible from chapters, up to top from each verses block
        $html = self::inject_nav_helpers($html);
        status_header(200);
        nocache_headers();
        $title = $entry['short_name'];
        $content = '<div class="thebible thebible-book">' . $html . '</div>';
        self::output_with_theme($title, $content);
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
        $home = home_url('/bible/');
        $out = '<div class="thebible thebible-index">';
        $out .= '<div class="thebible-groups">';
        $out .= '<section class="thebible-group thebible-ot"><h2>Old Testament</h2><ul>';
        foreach ($ot as $b) {
            $slug = self::slugify($b['short_name']);
            $url = trailingslashit($home) . $slug . '/';
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($b['short_name']) . '</a></li>';
        }
        $out .= '</ul></section>';
        $out .= '<section class="thebible-group thebible-nt"><h2>New Testament</h2><ul>';
        foreach ($nt as $b) {
            $slug = self::slugify($b['short_name']);
            $url = trailingslashit($home) . $slug . '/';
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($b['short_name']) . '</a></li>';
        }
        $out .= '</ul></section>';
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }

    private static function output_with_theme($title, $content_html) {
        if (function_exists('get_header')) get_header();
        echo '<main id="primary" class="site-main container mt-2">';
        echo '<article class="thebible-article">';
        echo '<header class="entry-header mb-3"><h1 class="entry-title">' . esc_html($title) . '</h1></header>';
        echo '<div class="entry-content">' . $content_html . '</div>';
        echo '</article>';
        echo '</main>';
        if (function_exists('get_footer')) get_footer();
    }
}

TheBible_Plugin::init();
