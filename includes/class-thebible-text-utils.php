<?php

if (!defined('ABSPATH')) {
    exit;
}

class TheBible_Text_Utils {
    public static function normalize_whitespace($s) {
        // Replace various Unicode spaces/invisibles with normal space or remove, collapse, and trim
        $s = (string) $s;
        // Map a set of known invisibles to spaces or empty
        $map = [
            "\xC2\xA0" => ' ', // NBSP U+00A0
            "\xC2\xAD" => '',  // Soft hyphen U+00AD
            "\xE1\x9A\x80" => ' ', // OGHAM space mark U+1680
            "\xE2\x80\x80" => ' ', // En quad U+2000
            "\xE2\x80\x81" => ' ', // Em quad U+2001
            "\xE2\x80\x82" => ' ', // En space U+2002
            "\xE2\x80\x83" => ' ', // Em space U+2003
            "\xE2\x80\x84" => ' ', // Three-per-em space U+2004
            "\xE2\x80\x85" => ' ', // Four-per-em space U+2005
            "\xE2\x80\x86" => ' ', // Six-per-em space U+2006
            "\xE2\x80\x87" => ' ', // Figure space U+2007
            "\xE2\x80\x88" => ' ', // Punctuation space U+2008
            "\xE2\x80\x89" => ' ', // Thin space U+2009
            "\xE2\x80\x8A" => ' ', // Hair space U+200A
            "\xE2\x80\x8B" => '',  // Zero width space U+200B
            "\xE2\x80\x8C" => '',  // Zero width non-joiner U+200C
            "\xE2\x80\x8D" => '',  // Zero width joiner U+200D
            "\xE2\x80\x8E" => '',  // LRM U+200E
            "\xE2\x80\x8F" => '',  // RLM U+200F
            "\xE2\x80\xA8" => ' ', // Line separator U+2028
            "\xE2\x80\xA9" => ' ', // Paragraph separator U+2029
            "\xE2\x80\xAF" => ' ', // Narrow no-break space U+202F
            "\xE2\x81\xA0" => ' ', // Word joiner U+2060
            "\xEF\xBB\xBF" => '',  // BOM U+FEFF
        ];

        $s = strtr($s, $map);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string) $s);
    }
}
