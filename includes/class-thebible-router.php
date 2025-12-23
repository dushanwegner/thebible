<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TheBible_Router {
    public static function add_rewrite_rules() {
        TheBible_Plugin::add_rewrite_rules();
    }

    public static function add_query_vars( $vars ) {
        return TheBible_Plugin::add_query_vars( $vars );
    }

    public static function handle_sitemap() {
        TheBible_Plugin::handle_sitemap();
    }

    public static function handle_template_redirect() {
        TheBible_Plugin::handle_template_redirect();
    }
}
