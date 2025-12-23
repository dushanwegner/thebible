<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TheBible_Admin {
    public static function register_settings() {
        TheBible_Plugin::register_settings();
    }

    public static function customize_register( $wp_customize ) {
        TheBible_Plugin::customize_register( $wp_customize );
    }

    public static function admin_menu() {
        TheBible_Plugin::admin_menu();
    }

    public static function admin_enqueue( $hook ) {
        TheBible_Plugin::admin_enqueue( $hook );
    }

    public static function handle_export_bible_txt() {
        TheBible_Plugin::handle_export_bible_txt();
    }
}
