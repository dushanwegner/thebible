<?php
/**
 * WHAT: Serves the QR code for a Bible verse as a downloadable image.
 *       `/{lang}/biblia/ioannes/3:16/?dwbible_qr=1&dwbible_qr_download=1`
 * WHY:  The verse toolbar's "Image" button hands you a picture of the verse to
 *       post. This is the other half: the picture that gets somebody ELSE to the
 *       verse — a code small enough to print on a holy card, in the language of
 *       the page it was taken from.
 * INPUT: The same query vars the page itself was routed by (book, chapter,
 *       verse-from), plus `dwbible_qr_download` and an optional `format`.
 * OUTPUT: image/svg+xml (default) or image/png, inline or as an attachment.
 * DEPENDS ON: dwqr for the code and the encoder — degrades to 404 without it.
 *       dwi18n for the current language.
 *
 * ─── Why the FIRST verse of a selection ───────────────────────────────────
 *
 * A selection can span verses; a code addresses one. The first verse is the
 * only defensible choice — it is where the passage starts, it is what the
 * reference reads as, and it is stable when the reader extends the selection
 * downward. A range could have its own code, but ranges are allocated on
 * demand and a printed card wants the address that already exists.
 *
 * ─── Why SVG by default ───────────────────────────────────────────────────
 *
 * These get printed. A QR symbol is pure geometry, so SVG is both smaller and
 * exact at any size, and it drops into a layout tool without anyone thinking
 * about resolution. It also needs no GD, so it cannot fail on a server that
 * could not render the OG image. `&format=png` is there for slides.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DwBible_QR_Image {

    public static function render() {
        // dwqr owns the codes and the encoder. Without it there is nothing to
        // draw — and a 404 is the honest answer, not a broken image.
        if ( ! class_exists( 'DWQR_Shortlink' ) || ! class_exists( 'DWQR_Encoder' ) ) {
            status_header( 404 );
            exit;
        }

        $book_slug = get_query_var( DwBible_Plugin::QV_BOOK );
        $ch        = absint( get_query_var( DwBible_Plugin::QV_CHAPTER ) );
        $vf        = absint( get_query_var( DwBible_Plugin::QV_VFROM ) );
        if ( ! $book_slug || ! $ch || ! $vf ) {
            status_header( 400 );
            exit;
        }

        // The language of the page this was taken from, so a reader printing
        // from the German Bible gets a card that opens in German.
        $lang = function_exists( 'dwi18n_current' ) ? dwi18n_current() : 'en';

        $code = DWQR_Shortlink::code_for_verse( $book_slug, $ch, $vf, $lang );
        if ( $code === '' ) {
            // No such verse. Same answer the page itself would give.
            status_header( 404 );
            exit;
        }

        $payload = DWQR_Shortlink::qr_payload( $code );

        $format = isset( $_GET['format'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['format'] ) ) ) : 'svg';
        if ( ! in_array( $format, array( 'svg', 'png' ), true ) ) {
            $format = 'svg';
        }
        // PNG needs GD; SVG never does. Fall back rather than fail.
        if ( $format === 'png' && ! function_exists( 'imagecreatetruecolor' ) ) {
            $format = 'svg';
        }

        try {
            $settings = DWQR_Settings::get();
            $symbol   = DWQR_Encoder::encode( $payload, $settings['ecc'] );
        } catch ( Exception $e ) {
            status_header( 500 );
            exit;
        }

        $download = ! empty( $_GET['dwbible_qr_download'] );
        // Named after the code, not the verse: a folder of these sorts by the
        // thing actually printed on the card, and the code is what someone
        // needs to match a file back to a symbol.
        $filename = strtolower( $code ) . '-' . sanitize_title( $book_slug . '-' . $ch . '-' . $vf ) . '.' . $format;

        nocache_headers();
        status_header( 200 );

        if ( $format === 'png' ) {
            $body = DWQR_Renderer::raster( $symbol, 'png', DWQR_Settings::render_options() );
            header( 'Content-Type: image/png' );
        } else {
            $body = DWQR_Renderer::svg( $symbol, DWQR_Settings::render_options() );
            header( 'Content-Type: image/svg+xml; charset=utf-8' );
        }

        if ( $download ) {
            header( 'Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
        }
        header( 'Content-Length: ' . strlen( $body ) );
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput — binary/SVG image body
        exit;
    }
}
