<?php
/**
 * DW Bible — references: the chapter/verse grammar, in one place.
 *
 * WHAT   Parsing and formatting of a citation — both the URL form the router
 *        already owns (`/{book}/{ch}:{v}[-{v}]`) and the TYPED form a reader
 *        puts into a search box ("Matthew 5:41", "Mt 5,41", "1 Cor 13").
 * WHY    The typed grammar is read in two runtimes — PHP resolves `?q=` on the
 *        server, the book index filters in the browser — so the pattern lives
 *        here ONCE and the browser is handed the same string. Two copies of a
 *        grammar drift; one copy cannot.
 * USED BY dwbible.php (the index filter + its inline JS), class-dwbible-router
 *        (the `?q=` resolver).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DwBible_Reference {

    /**
     * The typed-citation grammar: "<book> <chapter>[:<verse>[-<verse>]]".
     *
     * Written WITHOUT delimiters because it is used by both PCRE (`/…/u`) and
     * the browser (`new RegExp(…)`) — keep it to syntax both understand.
     *
     *   1  book name    lazy, so only a TRAILING run of digits can be the
     *                   chapter — "1 Cor 13" keeps its leading book number
     *   2  chapter      required once the name is followed by digits
     *   3  verse        optional, and optionally still being typed ("Mt 5:")
     *   4  range end    optional ("Luke 24:13-35")
     *
     * Separators: `:` `,` `.` between chapter and verse (the Latin/German
     * citation comma included); hyphen or dash for a range.
     */
    const CITATION_PATTERN = '^(.*?)[\\s.]*(\\d+)\\s*(?:[:,.]\\s*(\\d+)?(?:\\s*[-–—]\\s*(\\d+)?)?)?\\s*$';

    /**
     * Split a typed query into its book half and its citation half.
     *
     * A query with no trailing chapter is all book name; a query whose name
     * half is empty ("5:41") is treated as all book name too, since a citation
     * with nothing to cite cannot be resolved.
     *
     * @param string $raw What the reader typed.
     * @return array{name:string,ref:string} ref is the canonical "ch[:v[-v]]" ('' if none).
     */
    public static function parse_query($raw) {
        $s = trim((string) $raw);
        if ($s === '') {
            return ['name' => '', 'ref' => ''];
        }
        if (!preg_match('/' . self::CITATION_PATTERN . '/u', $s, $m)) {
            return ['name' => $s, 'ref' => ''];
        }
        $name = isset($m[1]) ? trim($m[1]) : '';
        if ($name === '') {
            return ['name' => $s, 'ref' => ''];
        }
        $ref = $m[2];
        if (!empty($m[3])) {
            $ref .= ':' . $m[3];
            if (!empty($m[4])) { $ref .= '-' . $m[4]; }
        }
        return ['name' => $name, 'ref' => $ref];
    }

    public static function parse_chapter_and_range($ch, $vf, $vt) {
        $ch = absint($ch);
        $vf = absint($vf);
        $vt_raw = $vt;
        $vt = absint($vt);

        if ($ch <= 0) {
            return new WP_Error('dwbible_invalid_chapter', 'Invalid chapter.');
        }

        if (($vf === 0 || $vf === null) && $vt_raw !== null && $vt_raw !== '') {
            return new WP_Error('dwbible_invalid_range', 'Invalid verse range.');
        }

        if ($vf <= 0) {
            return [
                'ch' => $ch,
                'vf' => null,
                'vt' => null,
            ];
        }

        if ($vt_raw === null || $vt_raw === '' || $vt <= 0) {
            $vt = $vf;
        }

        if ($vt < $vf) {
            return new WP_Error('dwbible_invalid_range', 'Invalid verse range.');
        }

        return [
            'ch' => $ch,
            'vf' => $vf,
            'vt' => $vt,
        ];
    }

    public static function highlight_ids_for_range($book_slug, $ch, $vf, $vt) {
        $book_slug = is_string($book_slug) ? $book_slug : '';
        $book_slug = $book_slug !== '' ? DwBible_Plugin::slugify($book_slug) : '';
        $ch = absint($ch);
        $vf = absint($vf);
        $vt = absint($vt);

        if ($book_slug === '' || $ch <= 0 || $vf <= 0 || $vt < $vf) {
            return [];
        }

        $out = [];
        for ($i = $vf; $i <= $vt; $i++) {
            $out[] = $book_slug . '-' . $ch . '-' . $i;
        }
        return $out;
    }

    public static function chapter_scroll_id($book_slug, $ch) {
        $book_slug = is_string($book_slug) ? $book_slug : '';
        $book_slug = $book_slug !== '' ? DwBible_Plugin::slugify($book_slug) : '';
        $ch = absint($ch);
        if ($book_slug === '' || $ch <= 0) {
            return null;
        }
        return $book_slug . '-ch-' . $ch;
    }
}
