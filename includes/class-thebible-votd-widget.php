<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TheBible_VOTD_Widget extends WP_Widget {
  public function __construct() {
        parent::__construct(
            'thebible_votd',
            __('Verse of the Day (Bible)', 'thebible'),
            ['description' => __('Displays a simple verse-of-the-day reference from The Bible plugin.', 'thebible')]
        );
    }

    private static function available_language_slugs() {
        $list = get_option('thebible_slugs', 'bible,bibel,latin');
        if (!is_string($list)) {
            $list = 'bible';
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $list))));
        if (empty($parts)) {
            $parts = ['bible'];
        }
        $out = [];
        foreach ($parts as $p) {
            $p = sanitize_key($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        $out = array_values(array_unique($out));
        if (empty($out)) {
            $out = ['bible'];
        }
        return $out;
    }

    public function widget($args, $instance) {
        $title           = isset($instance['title']) ? $instance['title'] : '';
        $title_tpl       = isset($instance['title_template']) ? $instance['title_template'] : '';
        $date_mode       = isset($instance['date_mode']) ? $instance['date_mode'] : 'today_fallback';
        $pick_date       = isset($instance['pick_date']) ? $instance['pick_date'] : '';
        $lang_first      = isset($instance['lang_first']) ? $instance['lang_first'] : '';
        $lang_last       = isset($instance['lang_last']) ? $instance['lang_last'] : '';
        $lang_mode       = isset($instance['lang_mode']) ? $instance['lang_mode'] : 'bible';
        $custom_css      = isset($instance['custom_css']) ? $instance['custom_css'] : '';
        $tpl_en          = isset($instance['template_en']) ? $instance['template_en'] : '';
        $tpl_de          = isset($instance['template_de']) ? $instance['template_de'] : '';
        $date_fmt_mode   = isset($instance['date_format_mode']) ? $instance['date_format_mode'] : '';

        if (!is_string($title)) $title = '';
        if (!is_string($title_tpl)) $title_tpl = '';
        if (!in_array($date_mode, ['today_fallback', 'random', 'pick_date'], true)) {
            $date_mode = 'today_fallback';
        }
        if (!is_string($pick_date)) $pick_date = '';
        $available = self::available_language_slugs();
        if (!is_string($lang_first)) $lang_first = '';
        if (!is_string($lang_last)) $lang_last = '';
        $lang_first = sanitize_key($lang_first);
        $lang_last = sanitize_key($lang_last);

        // Backwards compatibility with legacy single-select mode
        if ($lang_first === '') {
            if (!in_array($lang_mode, ['bible', 'bibel', 'both'], true)) {
                $lang_mode = 'bible';
            }
            if ($lang_mode === 'both') {
                $lang_first = 'bible';
                $lang_last = 'bibel';
            } else {
                $lang_first = $lang_mode;
                $lang_last = $lang_mode;
            }
        }

        if (!in_array($lang_first, $available, true)) {
            $lang_first = $available[0];
        }
        if ($lang_last === '') {
            $lang_last = $lang_first;
        }
        if (!in_array($lang_last, $available, true)) {
            $lang_last = $lang_first;
        }

        $langs_to_show = ($lang_last !== $lang_first) ? [$lang_first, $lang_last] : [$lang_first];
        $link_slug = ($lang_last !== $lang_first) ? ($lang_first . '-' . $lang_last) : $lang_first;
        if (!is_string($custom_css)) $custom_css = '';
        if (!is_string($tpl_en)) $tpl_en = '';
        if (!is_string($tpl_de)) $tpl_de = '';
        $allowed_fmt_modes = ['','site_default','en_long','en_short','de_long','de_short','de_numeric'];
        if (!in_array($date_fmt_mode, $allowed_fmt_modes, true)) {
            $date_fmt_mode = '';
        }

        // Resolve VOTD entry based on date mode
        $ref = null;
        if ($date_mode === 'random') {
            $ref = TheBible_VOTD_Admin::get_votd_random();
        } elseif ($date_mode === 'pick_date' && $pick_date !== '') {
            $ref = TheBible_VOTD_Admin::get_votd_for_date($pick_date);
        } else { // today_fallback
            $ref = TheBible_VOTD_Admin::get_votd_for_date();
            if (!is_array($ref)) {
                $ref = TheBible_VOTD_Admin::get_votd_random();
            }
        }

        if (!is_array($ref)) {
            return;
        }

        $canonical = isset($ref['book_slug']) ? $ref['book_slug'] : '';
        $chapter   = isset($ref['chapter']) ? (int) $ref['chapter'] : 0;
        $vfrom     = isset($ref['vfrom']) ? (int) $ref['vfrom'] : 0;
        $vto       = isset($ref['vto']) ? (int) $ref['vto'] : 0;
        $date      = isset($ref['date']) ? $ref['date'] : '';
        $texts     = isset($ref['texts']) && is_array($ref['texts']) ? $ref['texts'] : [];

        if (!is_string($canonical) || $canonical === '' || $chapter <= 0 || $vfrom <= 0) {
            return;
        }
        if ($vto <= 0 || $vto < $vfrom) {
            $vto = $vfrom;
        }

        // Always build the main reference label from English dataset
        $short_en = TheBible_Plugin::resolve_book_for_dataset($canonical, 'bible');
        if (!is_string($short_en) || $short_en === '') {
            $label = ucwords(str_replace('-', ' ', (string) $canonical));
        } else {
            $label = TheBible_Plugin::pretty_label($short_en);
        }

        $book_slug_en = TheBible_Plugin::slugify($short_en ? $short_en : $canonical);
        $ref_str = $label . ' ' . $chapter . ':' . ($vfrom === $vto ? $vfrom : ($vfrom . '-' . $vto));
        if (is_string($date) && $date !== '') {
            $ref_str .= ' (' . $date . ')';
        }

        // Single canonical URL for the whole widget.
        // If two languages are selected, link to interlinear slug (lang_first-lang_last), otherwise single dataset.
        $book_for_url = TheBible_Plugin::resolve_book_for_dataset($canonical, $lang_first);
        if (!is_string($book_for_url) || $book_for_url === '') {
            $book_for_url = $canonical;
        }
        $book_slug_for_url = TheBible_Plugin::slugify($book_for_url);
        if (!is_string($book_slug_for_url) || $book_slug_for_url === '') {
            $book_slug_for_url = (string) $canonical;
        }
        $path_ref = '/' . trim($link_slug, '/') . '/' . trim($book_slug_for_url, '/') . '/' . $chapter . ':' . $vfrom . ($vto > $vfrom ? ('-' . $vto) : '');
        $url_ref  = home_url($path_ref);

        echo isset($args['before_widget']) ? $args['before_widget'] : '';

        if ($custom_css !== '') {
            echo '<style class="thebible-votd-widget-css">' . $custom_css . '</style>';
        }

        // Localized, pretty date (shared for all languages)
        $display_date = $date;
        if (is_string($date) && $date !== '') {
            $ts = strtotime($date . ' 00:00:00');
            if ($ts) {
                if ($date_fmt_mode === 'en_long' || $date_fmt_mode === 'en_short') {
                    // English formats with ordinal day suffix (1st, 2nd, 3rd, 4th, ...), independent of site locale
                    $day  = (int) gmdate('j', $ts);
                    $mod100 = $day % 100;
                    if ($mod100 >= 11 && $mod100 <= 13) {
                        $suffix = 'th';
                    } else {
                        $last = $day % 10;
                        if ($last === 1) {
                            $suffix = 'st';
                        } elseif ($last === 2) {
                            $suffix = 'nd';
                        } elseif ($last === 3) {
                            $suffix = 'rd';
                        } else {
                            $suffix = 'th';
                        }
                    }
                    $day_ordinal = $day . $suffix;

                    if ($date_fmt_mode === 'en_long') {
                        $month = gmdate('F', $ts);
                        $year  = gmdate('Y', $ts);
                        $display_date = $month . ' ' . $day_ordinal . ', ' . $year; // e.g. March 4th, 2025
                    } else { // en_short
                        $month = gmdate('M', $ts);
                        $year  = gmdate('Y', $ts);
                        $display_date = $month . '. ' . $day_ordinal . ', ' . $year; // e.g. Mar. 4th, 2025
                    }
                } elseif ($date_fmt_mode === 'de_long') {
                    $display_date = date_i18n('j. F Y', $ts); // e.g. 4. März 2025
                } elseif ($date_fmt_mode === 'de_short') {
                    $display_date = date_i18n('d.m.Y', $ts); // e.g. 04.03.2025
                } elseif ($date_fmt_mode === 'de_numeric') {
                    $display_date = date_i18n('j.n.Y', $ts); // e.g. 4.3.2025
                } else {
                    $display_date = date_i18n(get_option('date_format'), $ts);
                }
            }
        }

        echo '<div class="thebible-votd-widget thebible-votd-widget-' . esc_attr($link_slug) . '">';

        // Title inside widget, above verse
        if ($title_tpl !== '' || $title !== '') {
            if ($title_tpl !== '') {
                $title_rendered = strtr($title_tpl, [
                    '{date}' => (string) $display_date,
                ]);
            } else {
                $title_rendered = $title;
            }
            $title_rendered = apply_filters('widget_title', $title_rendered, $instance, $this->id_base);
            echo '<div class="thebible-votd-heading">' . esc_html($title_rendered) . '</div>';
        }

        // Verse text blocks per language, but only ONE link (to single or interlinear page)
        foreach ($langs_to_show as $ds) {
            $text = isset($texts[$ds]) ? $texts[$ds] : '';
            if (!is_string($text) || $text === '') {
                continue;
            }

            // Normalize and clean quotation marks for widget output, and wrap once in outer guillemets
            $text = TheBible_Plugin::clean_verse_text_for_output($text, true, '»', '«');

            // Dataset-specific book label (for internal display only; link is rendered once below)
            $short_ds = TheBible_Plugin::resolve_book_for_dataset($canonical, $ds);

            // Citation label for this dataset
            if (!is_string($short_ds) || $short_ds === '') {
                $heading_label_ds = $label;
            } else {
                // Special case for German Matthew - display with umlaut but keep URL slug as ASCII
                if ($ds === 'bibel' && $canonical === 'matthew') {
                    $heading_label_ds = 'Matthäus';
                } else {
                    $heading_label_ds = TheBible_Plugin::pretty_label($short_ds);
                }
            }
            $citation = $heading_label_ds . ' ' . $chapter . ':' . ($vfrom === $vto ? $vfrom : ($vfrom . '-' . $vto));

            echo '<div class="thebible-votd-lang thebible-votd-lang-' . esc_attr($ds) . '">';
            echo '<p class="thebible-votd-text">' . wp_kses_post($text) . '</p>';
            echo '</div>';
        }

        echo '<div class="thebible-votd-context">';
        echo '<a class="thebible-votd-context-link" href="' . esc_url( $url_ref ) . '">' . esc_html( $ref_str ) . '</a>';
        echo '</div>';

        echo '</div>';

        echo isset($args['after_widget']) ? $args['after_widget'] : '';
    }

    public function form($instance) {
        $title        = isset($instance['title']) ? $instance['title'] : '';
        $title_tpl    = isset($instance['title_template']) ? $instance['title_template'] : '';
        $date_mode    = isset($instance['date_mode']) ? $instance['date_mode'] : 'today_fallback';
        $pick_date    = isset($instance['pick_date']) ? $instance['pick_date'] : '';
        $lang_first   = isset($instance['lang_first']) ? $instance['lang_first'] : '';
        $lang_last    = isset($instance['lang_last']) ? $instance['lang_last'] : '';
        $lang_mode    = isset($instance['lang_mode']) ? $instance['lang_mode'] : 'bible';
        $custom_css   = isset($instance['custom_css']) ? $instance['custom_css'] : '';
        $tpl_en       = isset($instance['template_en']) ? $instance['template_en'] : '';
        $tpl_de       = isset($instance['template_de']) ? $instance['template_de'] : '';
        $date_fmt_mode = isset($instance['date_format_mode']) ? $instance['date_format_mode'] : '';
        if (!is_string($title)) $title = '';
        if (!is_string($title_tpl)) $title_tpl = '';
        if (!in_array($date_mode, ['today_fallback', 'random', 'pick_date'], true)) {
            $date_mode = 'today_fallback';
        }
        if (!is_string($pick_date)) $pick_date = '';
        $available = self::available_language_slugs();
        if (!is_string($lang_first)) $lang_first = '';
        if (!is_string($lang_last)) $lang_last = '';
        $lang_first = sanitize_key($lang_first);
        $lang_last = sanitize_key($lang_last);
        if ($lang_first === '') {
            if (!in_array($lang_mode, ['bible', 'bibel', 'both'], true)) {
                $lang_mode = 'bible';
            }
            if ($lang_mode === 'both') {
                $lang_first = 'bible';
                $lang_last = 'bibel';
            } else {
                $lang_first = $lang_mode;
                $lang_last = $lang_mode;
            }
        }
        if (!in_array($lang_first, $available, true)) {
            $lang_first = $available[0];
        }
        if ($lang_last === '' || !in_array($lang_last, $available, true)) {
            $lang_last = $lang_first;
        }
        if (!is_string($custom_css)) $custom_css = '';
        if (!is_string($tpl_en)) $tpl_en = '';
        if (!is_string($tpl_de)) $tpl_de = '';
        if (!in_array($date_fmt_mode, ['', 'site_default', 'en_long', 'en_short', 'de_long', 'de_short', 'de_numeric'], true)) {
            $date_fmt_mode = '';
        }

        // Show default templates in the form when empty (same structure for all languages)
        $default_tpl = "<p class=\"thebible-votd-text\">{votd-content}</p>\n"
                     . "<div class=\"thebible-votd-context\"><a class=\"thebible-votd-context-link\" href=\"{votd-link}\">{votd-citation}, jetzt lesen ���</a></div>";

        if ($tpl_en === '') {
            $tpl_en = $default_tpl;
        }
        if ($tpl_de === '') {
            $tpl_de = $default_tpl;
        }

        $title_id = $this->get_field_id('title');
        $title_name = $this->get_field_name('title');
        $title_tpl_id = $this->get_field_id('title_template');
        $title_tpl_name = $this->get_field_name('title_template');
        $date_mode_id = $this->get_field_id('date_mode');
        $date_mode_name = $this->get_field_name('date_mode');
        $pick_date_id = $this->get_field_id('pick_date');
        $pick_date_name = $this->get_field_name('pick_date');
        $lang_first_id = $this->get_field_id('lang_first');
        $lang_first_name = $this->get_field_name('lang_first');
        $lang_last_id = $this->get_field_id('lang_last');
        $lang_last_name = $this->get_field_name('lang_last');
        $css_id = $this->get_field_id('custom_css');
        $css_name = $this->get_field_name('custom_css');
        $tpl_en_id = $this->get_field_id('template_en');
        $tpl_en_name = $this->get_field_name('template_en');
        $tpl_de_id = $this->get_field_id('template_de');
        $tpl_de_name = $this->get_field_name('template_de');
        $date_fmt_mode_id = $this->get_field_id('date_format_mode');
        $date_fmt_mode_name = $this->get_field_name('date_format_mode');

        echo '<p>';
        echo '<label for="' . esc_attr($title_id) . '">' . esc_html__('Title:', 'thebible') . '</label> ';
        echo '<input class="widefat" id="' . esc_attr($title_id) . '" name="' . esc_attr($title_name) . '" type="text" value="' . esc_attr($title) . '" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="' . esc_attr($title_tpl_id) . '">' . esc_html__('Title template (use {date}):', 'thebible') . '</label> ';
        echo '<input class="widefat" id="' . esc_attr($title_tpl_id) . '" name="' . esc_attr($title_tpl_name) . '" type="text" value="' . esc_attr($title_tpl) . '" />';
        echo '</p>';

        echo '<p>';
        echo '<label for="' . esc_attr($date_fmt_mode_id) . '">' . esc_html__('Date format:', 'thebible') . '</label> ';
        echo '<select id="' . esc_attr($date_fmt_mode_id) . '" name="' . esc_attr($date_fmt_mode_name) . '">';
        echo '<option value=""' . selected($date_fmt_mode, '', false) . '>' . esc_html__('Site default', 'thebible') . '</option>';
        echo '<option value="en_long"' . selected($date_fmt_mode, 'en_long', false) . '>' . esc_html__('English long (e.g. March 4, 2025)', 'thebible') . '</option>';
        echo '<option value="en_short"' . selected($date_fmt_mode, 'en_short', false) . '>' . esc_html__('English short (e.g. Mar 4, 2025)', 'thebible') . '</option>';
        echo '<option value="de_long"' . selected($date_fmt_mode, 'de_long', false) . '>' . esc_html__('German long (e.g. 4. März 2025)', 'thebible') . '</option>';
        echo '<option value="de_short"' . selected($date_fmt_mode, 'de_short', false) . '>' . esc_html__('German short (e.g. 04.03.2025)', 'thebible') . '</option>';
        echo '<option value="de_numeric"' . selected($date_fmt_mode, 'de_numeric', false) . '>' . esc_html__('German numeric (e.g. 4.3.2025)', 'thebible') . '</option>';
        echo '</select>';
        echo '</p>';

        echo '<p>';
        echo '<label for="' . esc_attr($date_mode_id) . '">' . esc_html__('Date mode:', 'thebible') . '</label> ';
        echo '<select id="' . esc_attr($date_mode_id) . '" name="' . esc_attr($date_mode_name) . '">';
        $date_modes = [
            'today_fallback' => __('Today (fallback to random)', 'thebible'),
            'random'         => __('Random', 'thebible'),
            'pick_date'      => __('Pick specific date', 'thebible'),
        ];
        foreach ($date_modes as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($date_mode, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        // Date picker: list existing dates from cache
        $by_date = get_option('thebible_votd_by_date', []);
        if (is_array($by_date) && !empty($by_date)) {
            $dates = array_keys($by_date);
            sort($dates);
            echo '<p>';
            echo '<label for="' . esc_attr($pick_date_id) . '">' . esc_html__('Pick date (from existing):', 'thebible') . '</label> ';
            echo '<select id="' . esc_attr($pick_date_id) . '" name="' . esc_attr($pick_date_name) . '">';
            echo '<option value="">' . esc_html__('— none —', 'thebible') . '</option>';
            foreach ($dates as $d) {
                echo '<option value="' . esc_attr($d) . '"' . selected($pick_date, $d, false) . '>' . esc_html($d) . '</option>';
            }
            echo '</select>';
            echo '</p>';
        }

        echo '<p>';
        echo '<label for="' . esc_attr($lang_first_id) . '">' . esc_html__('First language:', 'thebible') . '</label> ';
        echo '<select id="' . esc_attr($lang_first_id) . '" name="' . esc_attr($lang_first_name) . '">';
        foreach ($available as $ds) {
            echo '<option value="' . esc_attr($ds) . '"' . selected($lang_first, $ds, false) . '>' . esc_html($ds) . '</option>';
        }
        echo '</select>';
        echo '</p>';

        echo '<p>';
        echo '<label for="' . esc_attr($lang_last_id) . '">' . esc_html__('Last language:', 'thebible') . '</label> ';
        echo '<select id="' . esc_attr($lang_last_id) . '" name="' . esc_attr($lang_last_name) . '">';
        foreach ($available as $ds) {
            echo '<option value="' . esc_attr($ds) . '"' . selected($lang_last, $ds, false) . '>' . esc_html($ds) . '</option>';
        }
        echo '</select>';
        echo '</p>';

    }

    public function update($new_instance, $old_instance) {
        $instance = $old_instance;

        $instance['title'] = isset($new_instance['title']) ? sanitize_text_field($new_instance['title']) : '';
        $instance['title_template'] = isset($new_instance['title_template']) ? sanitize_text_field($new_instance['title_template']) : '';
        $instance['date_mode'] = isset($new_instance['date_mode']) ? sanitize_key($new_instance['date_mode']) : 'today_fallback';
        $instance['pick_date'] = isset($new_instance['pick_date']) ? sanitize_text_field($new_instance['pick_date']) : '';
        $instance['custom_css'] = isset($new_instance['custom_css']) ? (string) $new_instance['custom_css'] : '';
        $instance['template_en'] = isset($new_instance['template_en']) ? (string) $new_instance['template_en'] : '';
        $instance['template_de'] = isset($new_instance['template_de']) ? (string) $new_instance['template_de'] : '';
        $instance['date_format_mode'] = isset($new_instance['date_format_mode']) ? sanitize_key($new_instance['date_format_mode']) : '';

        // New range-based language selection
        $instance['lang_first'] = isset($new_instance['lang_first']) ? sanitize_key($new_instance['lang_first']) : '';
        $instance['lang_last'] = isset($new_instance['lang_last']) ? sanitize_key($new_instance['lang_last']) : '';

        // Keep legacy setting for backwards compatibility (may still exist in old widgets)
        $instance['lang_mode'] = isset($new_instance['lang_mode']) ? sanitize_key($new_instance['lang_mode']) : (isset($old_instance['lang_mode']) ? $old_instance['lang_mode'] : 'bible');

        return $instance;
    }

}
