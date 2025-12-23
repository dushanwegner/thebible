<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TheBible_VOTD {
    public static function register_votd_cpt() {
        TheBible_Plugin::register_votd_cpt();
    }

    public static function votd_columns( $columns ) {
        return TheBible_Plugin::votd_columns( $columns );
    }

    public static function votd_sortable_columns( $columns ) {
        return TheBible_Plugin::votd_sortable_columns( $columns );
    }

    public static function render_votd_column( $column, $post_id ) {
        TheBible_Plugin::render_votd_column( $column, $post_id );
    }

    public static function votd_date_filter() {
        TheBible_Plugin::votd_date_filter();
    }

    public static function apply_votd_date_filter( $query ) {
        TheBible_Plugin::apply_votd_date_filter( $query );
    }

    public static function votd_register_bulk_actions( $bulk_actions ) {
        return TheBible_Plugin::votd_register_bulk_actions( $bulk_actions );
    }

    public static function votd_handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
        return TheBible_Plugin::votd_handle_bulk_actions( $redirect_to, $doaction, $post_ids );
    }

    public static function handle_votd_condense_request() {
        TheBible_Plugin::handle_votd_condense_request();
    }

    public static function votd_condense_notice() {
        TheBible_Plugin::votd_condense_notice();
    }

    public static function add_votd_meta_box() {
        TheBible_Plugin::add_votd_meta_box();
    }

    public static function save_votd_meta( $post_id, $post ) {
        TheBible_Plugin::save_votd_meta( $post_id, $post );
    }
}
