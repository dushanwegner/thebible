<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Data_Paths {
    public static function data_root_dir() {
        $slug = get_query_var(DwBible_Plugin::QV_SLUG);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        set_query_var(DwBible_Plugin::QV_SLUG, $slug);
        $root = dwbible_data_dir() . $slug . '/';
        if (is_dir($root)) {
            return $root;
        }
        return null;
    }

    public static function html_dir() {
        $root = self::data_root_dir();
        if ($root) {
            $h = trailingslashit($root) . 'html/';
            if (is_dir($h)) {
                return $h;
            }
        }
        // There is one data layout: <dataset>/html/. This path is returned even
        // when the directory is absent — an unknown dataset slug yields a path
        // that does not exist, so the caller's file_exists() fails on the path
        // it actually asked for.
        $slug = get_query_var(DwBible_Plugin::QV_SLUG);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        return dwbible_data_dir() . $slug . '/html/';
    }

    public static function text_dir() {
        $root = self::data_root_dir();
        if ($root) {
            $t = trailingslashit($root) . 'text/';
            if (is_dir($t)) {
                return $t;
            }
        }
        // There is one data layout: <dataset>/text/. This path is returned even
        // when the directory is absent — an unknown dataset slug yields a path
        // that does not exist, so the caller's file_exists() fails on the path
        // it actually asked for.
        $slug = get_query_var(DwBible_Plugin::QV_SLUG);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        return dwbible_data_dir() . $slug . '/text/';
    }
}
