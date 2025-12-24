<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait TheBible_Rewrites_Trait {
    /**
     * Reset rewrite flush flag to trigger flush on next page load.
     */
    private static function reset_rewrite_flush_impl() {
        delete_option('thebible_flushed_rules');
    }

    private static function activate_impl() {
        self::add_rewrite_rules();
        flush_rewrite_rules();
    }

    private static function deactivate_impl() {
        flush_rewrite_rules();
        // Clean up legacy options no longer used by the plugin.
        delete_option( 'thebible_custom_css' );
        delete_option( 'thebible_prod_domain' );
    }

    private static function add_rewrite_rules_impl() {
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

        // Dynamic dual-language URLs based on configuration
        $first_lang = get_option('thebible_first_language', 'bible');
        $second_lang = get_option('thebible_second_language', '');

        $known_langs = [ 'bible', 'bibel', 'latin' ];
        foreach ( $known_langs as $a ) {
            foreach ( $known_langs as $b ) {
                if ( $a === $b ) {
                    continue;
                }
                $dual_slug = $a . '-' . $b;
                // index
                add_rewrite_rule('^' . preg_quote($dual_slug, '/') . '/?$', 'index.php?' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $a . '&thebible_secondary_lang=' . $b, 'top');
                // /{dual}/{book}
                add_rewrite_rule('^' . preg_quote($dual_slug, '/') . '/([^/]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $a . '&thebible_secondary_lang=' . $b, 'top');
                // /{dual}/{book}/{chapter}:{verse} or {chapter}:{from}-{to}
                add_rewrite_rule('^' . preg_quote($dual_slug, '/') . '/([^/]+)/([0-9]+):([0-9]+)(?:-([0-9]+))?/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $a . '&thebible_secondary_lang=' . $b, 'top');
                // /{dual}/{book}/{chapter}
                add_rewrite_rule('^' . preg_quote($dual_slug, '/') . '/([^/]+)/([0-9]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $a . '&thebible_secondary_lang=' . $b, 'top');
            }
        }

        // Sitemaps: English, German, Latin (use unique endpoints to avoid conflicts with other sitemap plugins)
        add_rewrite_rule('^bible-sitemap-bible\\.xml$', 'index.php?' . self::QV_SITEMAP . '=bible&' . self::QV_SLUG . '=bible', 'top');
        add_rewrite_rule('^bible-sitemap-bibel\\.xml$', 'index.php?' . self::QV_SITEMAP . '=bibel&' . self::QV_SLUG . '=bibel', 'top');
        add_rewrite_rule('^bible-sitemap-latin\\.xml$', 'index.php?' . self::QV_SITEMAP . '=latin&' . self::QV_SLUG . '=latin', 'top');

        // One-time flush after rewrite changes (avoids manual Permalinks save).
        $ver = '0.1.1-dual-language-any-pair';
        $flushed = get_option('thebible_flushed_rules', '');
        if ($flushed !== $ver) {
            flush_rewrite_rules(false);
            update_option('thebible_flushed_rules', $ver);
        }
    }
}
