<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TheBible_Markup {
    public static function build_class_attr( $classes ) {
        if ( ! is_array( $classes ) ) {
            $classes = [];
        }
        $out = [];
        foreach ( $classes as $c ) {
            if ( ! is_string( $c ) ) {
                continue;
            }
            $c = trim( $c );
            if ( $c === '' ) {
                continue;
            }
            $out[] = $c;
        }
        $out = implode( ' ', array_unique( $out ) );
        if ( $out === '' ) {
            return '';
        }
        return ' class="' . esc_attr( $out ) . '"';
    }

    public static function build_data_attrs( $attrs ) {
        if ( ! is_array( $attrs ) ) {
            return '';
        }
        $out = '';
        foreach ( $attrs as $k => $v ) {
            if ( ! is_string( $k ) ) {
                continue;
            }
            $k = trim( $k );
            if ( $k === '' ) {
                continue;
            }
            $k = preg_replace( '/[^a-z0-9_\-]/i', '', $k );
            if ( $k === '' ) {
                continue;
            }
            if ( is_bool( $v ) ) {
                $v = $v ? '1' : '0';
            }
            if ( is_int( $v ) || is_float( $v ) ) {
                $v = (string) $v;
            }
            if ( $v === null ) {
                $v = '';
            }
            if ( ! is_string( $v ) ) {
                continue;
            }
            $out .= ' data-' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
        }
        return $out;
    }
}
