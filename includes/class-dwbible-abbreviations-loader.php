<?php

if (!defined('ABSPATH')) {
    exit;
}

class DwBible_Abbreviations_Loader {
    public static function load_abbreviation_map($slug) {
        $slug = is_string($slug) ? trim($slug) : '';
        if ($slug === '') {
            return [];
        }
        $map = [];
        // Each dataset slug ships its abbreviation file in its own language.
        $slug_lang = [
            'bibel'   => 'de',
            'spanish' => 'es',
            'french'  => 'fr',
            'italian' => 'it',
            'latin'   => 'la',
        ];
        $lang = $slug_lang[$slug] ?? 'en';
        $file = dwbible_data_dir() . $slug . '/abbreviations.' . $lang . '.json';
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                error_log("DwBible: could not read abbreviation file: $file");
                return $map;
            }
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('DwBible: JSON parse error in ' . $file . ': ' . json_last_error_msg());
                return $map;
            }
            if (is_array($data) && !empty($data['books']) && is_array($data['books'])) {
                foreach ($data['books'] as $short => $variants) {
                    if (!is_array($variants)) {
                        continue;
                    }
                    foreach ($variants as $v) {
                        $key = trim(mb_strtolower((string) $v, 'UTF-8'));
                        if ($key === '') {
                            continue;
                        }
                        // First writer wins; avoid clobbering in case of collisions.
                        if (!isset($map[$key])) {
                            $map[$key] = (string) $short;
                        }
                    }
                }
            }
        }
        return $map;
    }

    /**
     * The tokens of a dataset that are BOOK NAMES rather than abbreviations.
     *
     * A name is a variant of five characters or more, OR the longest
     * single-word variant its book has in that language.
     *
     * Both halves are needed, and neither alone works:
     *
     *   - Length alone loses `Juan`, `Jean`, `Luc`, `John`, `Job`, `Rut` —
     *     real names that happen to be short.
     *   - "Longest single-word variant" alone loses `Psalm`, which sits behind
     *     `Psalms` and is nevertheless how a psalm is cited.
     *
     * The obvious third idea — "at least as long as the JSON key" — is wrong
     * here, and quietly: the Spanish and French files are keyed in ENGLISH
     * (`"John": ["Juan", "Jn"]`), so `Juan` measured against `John` looks like
     * an abbreviation and Spanish would never link a single reference. German
     * is keyed in German, so the fault is invisible until another language is
     * switched on. Multi-word variants are excluded from the comparison because
     * every book has a descriptive one ("The Gospel of John") that would make
     * every real name look short.
     *
     * @return array<string,string> lower-cased name token => canonical key
     */
    public static function load_name_map($slug) {
        $slug = is_string($slug) ? trim($slug) : '';
        if ($slug === '') {
            return [];
        }
        $slug_lang = [
            'bibel'   => 'de',
            'spanish' => 'es',
            'french'  => 'fr',
            'italian' => 'it',
            'latin'   => 'la',
        ];
        $lang = $slug_lang[$slug] ?? 'en';
        $file = dwbible_data_dir() . $slug . '/abbreviations.' . $lang . '.json';
        if (!file_exists($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || empty($data['books']) || !is_array($data['books'])) {
            return [];
        }

        $names = [];
        foreach ($data['books'] as $short => $variants) {
            if (!is_array($variants)) {
                continue;
            }
            // The longest variant that is a single word — the book's own name
            // in this language, whatever its length.
            $own = '';
            foreach ($variants as $v) {
                $v = trim((string) $v);
                if ($v === '' || preg_match('/\s/u', $v)) {
                    continue;
                }
                if (mb_strlen($v, 'UTF-8') > mb_strlen($own, 'UTF-8')) {
                    $own = $v;
                }
            }
            foreach ($variants as $v) {
                $v = trim((string) $v);
                if ($v === '') {
                    continue;
                }
                $key = mb_strtolower($v, 'UTF-8');
                if (isset($names[$key])) {
                    continue;
                }
                if (mb_strlen($v, 'UTF-8') >= 5 || ($own !== '' && $v === $own)) {
                    $names[$key] = (string) $short;
                }
            }
        }
        return $names;
    }
}
