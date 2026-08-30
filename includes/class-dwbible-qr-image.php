<?php
/**
 * WHAT: Serves the QR code for a Bible verse as a downloadable image.
 *       `/{lang}/biblia/ioannes/3:16/?dwbible_qr=1&dwbible_qr_download=1`
 * WHY:  The verse toolbar's "Image" button hands you a picture of the verse to
 *       post. This is the other half: the picture that gets somebody ELSE to the
 *       verse — a code small enough to print on a holy card, in the language of
 *       the page it was taken from.
 * INPUT: The same query vars the page itself was routed by (book, chapter,
 *       verse-from, verse-to), plus `dwbible_qr_download`, an optional format
 *       on `dwbible_qr` itself, and — for the passage code — `dwbible_qr_range`
 *       carrying a nonce.
 * OUTPUT: image/svg+xml (default) or image/png, inline or as an attachment.
 * DEPENDS ON: dwqr for the code and the encoder — degrades to 404 without it.
 *       dwi18n for the current language.
 *
 * ─── Why the FIRST verse of a selection, unless an editor asks otherwise ──
 *
 * A selection can span verses; a verse code addresses one. The first verse is
 * the only defensible default — it is where the passage starts, it is what the
 * reference reads as, it is stable when the reader extends the selection
 * downward, and above all it is FREE: a verse's code is arithmetic, so every
 * verse has always had one and nothing is spent by asking.
 *
 * A passage code is a different kind of thing. There are ~640 million possible
 * passages in the Vulgate and 46,656 addresses in the whole scheme, so a range
 * has no computed address — one is minted, permanently, out of an allowance of
 * 3,000 (DWQR_Ranges). That is an editorial act, so it is asked for explicitly
 * with `dwbible_qr_range` and granted only to somebody who could edit the page
 * anyway. Everyone else keeps the free first-verse code, unchanged.
 *
 * ─── Why the nonce, on a request that only reads an image ─────────────────
 *
 * Because this one does not only read. Minting writes an ordinal that can never
 * be reused or renumbered — the same promise every printed code rests on — and
 * a capability check alone would let a crafted link spend one from an editor's
 * browser without their ever meaning to. The nonce is what makes the request
 * evidence of intent rather than merely evidence of who sent it.
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

    /**
     * The nonce action guarding a passage mint. Named here rather than written
     * out at both ends: the creating side lives in another plugin (dwtexttools
     * localises it for the flyout), and a nonce action that disagrees between
     * the two sides fails as "permission denied" with nothing to point at.
     */
    const RANGE_NONCE_ACTION = 'dwbible_qr_range';

    /** The query parameter that carries that nonce, and asks for the passage code. */
    const RANGE_PARAM = 'dwbible_qr_range';

    /**
     * Whether this request may mint a passage code.
     *
     * Three separate answers, and they are deliberately not collapsed:
     *   null  — nobody asked; serve the free first-verse code as always.
     *   true  — asked, and allowed.
     *   false — asked, and refused. That is an error worth reporting, not a
     *           silent downgrade to a different code than the one requested:
     *           an editor whose nonce has expired must find out here, not by
     *           printing a card that points at one verse of three.
     *
     * @return bool|null
     */
    private static function range_request() {
        if ( ! isset( $_GET[ self::RANGE_PARAM ] ) ) {
            return null;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }
        $nonce = sanitize_text_field( wp_unslash( $_GET[ self::RANGE_PARAM ] ) );
        return (bool) wp_verify_nonce( $nonce, self::RANGE_NONCE_ACTION );
    }

    public static function render() {
        // ── THIS MUST BE FIRST, AND IT IS NOT DECORATION ──────────────────
        //
        // dwcache opens its output buffer at template_redirect:0; this router
        // runs at template_redirect:1, so by the time we emit an image the
        // buffer is already capturing it. And dwcache's cache KEY drops every
        // query parameter outside its allow-list — `dwbible_qr` among them — so
        // the key for this request is the bare verse URL.
        //
        // Without this line the consequence is not a stale image, it is that
        // ONE person downloading a QR code replaces the verse page itself with
        // a picture, for everybody, until the cache expires. That is exactly
        // what happened on 2026-08-13: scanning a code landed on the verse URL
        // and got served the QR image back.
        //
        // nocache_headers() alone does NOT prevent this — see the dw-wp-1
        // reference; PHP's cache-control signalling is inert at the nginx layer
        // and dwcache keys on the URL, not on headers.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        // dwqr owns the codes and the encoder. Without it there is nothing to
        // draw — and a 404 is the honest answer, not a broken image.
        if ( ! class_exists( 'DWQR_Shortlink' ) || ! class_exists( 'DWQR_Encoder' ) ) {
            status_header( 404 );
            exit;
        }

        $book_slug = get_query_var( DwBible_Plugin::QV_BOOK );
        $ch        = absint( get_query_var( DwBible_Plugin::QV_CHAPTER ) );
        $vf        = absint( get_query_var( DwBible_Plugin::QV_VFROM ) );
        $vt        = absint( get_query_var( DwBible_Plugin::QV_VTO ) );
        if ( ! $book_slug || ! $ch || ! $vf ) {
            status_header( 400 );
            exit;
        }

        // The language of the page this was taken from, so a reader printing
        // from the German Bible gets a card that opens in German.
        $lang = function_exists( 'dwi18n_current' ) ? dwi18n_current() : 'en';

        $wants_range = self::range_request();
        if ( $wants_range === false ) {
            // Asked to mint and not allowed to. See range_request().
            status_header( 403 );
            exit;
        }

        // The reference this image is NAMED after — the passage when one was
        // minted, the single verse otherwise. Kept beside the code decision so
        // the filename can never describe a different thing than the symbol.
        $reference = $ch . '-' . $vf;

        if ( $wants_range ) {
            if ( ! $vt || $vt <= $vf ) {
                // A passage code for something that is not a passage. Refusing
                // is the whole point: the alternative is a permanent ordinal
                // spent on an address the free verse code already covers.
                status_header( 400 );
                exit;
            }
            $code      = DWQR_Shortlink::code_for_range( $book_slug, $ch, $vf, $vt, $lang );
            $reference = $ch . '-' . $vf . '-' . $vt;
            if ( $code === '' ) {
                // Either no such passage, or the range band is full. Both are
                // "there is no code for this", which is a 404 — and a full band
                // is loud in the admin's usage table long before it gets here.
                status_header( 404 );
                exit;
            }
        } else {
            $code = DWQR_Shortlink::code_for_verse( $book_slug, $ch, $vf, $lang );
            if ( $code === '' ) {
                // No such verse. Same answer the page itself would give.
                status_header( 404 );
                exit;
            }
        }

        $payload = DWQR_Shortlink::qr_payload( $code );

        // The format rides on dwbible_qr itself — `?dwbible_qr=png` — rather than
        // on a `format` parameter. `format` is NOT ours: dwtheme registers it
        // site-wide for the AI output surface (`?format=text` / `?format=json`),
        // so borrowing the name gave a request two meanings and the image came
        // out SVG no matter what was asked for. A parameter that already means
        // something on this site cannot be given a second meaning here.
        $format = strtolower( sanitize_key( (string) get_query_var( DwBible_Plugin::QV_QR ) ) );
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
        $filename = strtolower( $code ) . '-' . sanitize_title( $book_slug . '-' . $reference ) . '.' . $format;

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
