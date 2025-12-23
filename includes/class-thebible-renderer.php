<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TheBible_Renderer {
    public static function render_index() {
        TheBible_Plugin::render_index();
    }

    public static function render_book( $slug ) {
        TheBible_Plugin::render_book( $slug );
    }

    public static function output_with_theme( $title, $content_html, $context = '' ) {
        TheBible_Plugin::output_with_theme( $title, $content_html, $context );
    }
}
