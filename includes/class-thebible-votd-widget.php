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
    
    public function update($new_instance, $old_instance) {
        // Validate first language
        $allowed_langs = ['bible', 'bibel', 'latin'];
        $first_lang = isset($new_instance['first_language']) ? $new_instance['first_language'] : 'bible';
        if (!in_array($first_lang, $allowed_langs, true)) {
            $first_lang = 'bible';
        }
        $new_instance['first_language'] = $first_lang;
        
        // Validate second language (optional)
        $second_lang = isset($new_instance['second_language']) ? $new_instance['second_language'] : '';
        if ($second_lang !== '' && !in_array($second_lang, $allowed_langs, true)) {
            $second_lang = '';
        }
        // Ensure second language is different from first
        if ($second_lang === $first_lang) {
            $second_lang = '';
        }
        $new_instance['second_language'] = $second_lang;
        
        // Set lang_mode for backward compatibility
        if ($second_lang !== '') {
            $new_instance['lang_mode'] = 'both';
        } else {
            $new_instance['lang_mode'] = $first_lang;
        }
        
        // Set interlinear_langs for backward compatibility  
        if ($second_lang !== '') {
            $new_instance['interlinear_langs'] = [$first_lang, $second_lang];
        } else {
            $new_instance['interlinear_langs'] = [$first_lang];
        }
        
        return $new_instance;
    }

    public function widget($args, $instance) {
        $title           = isset($instance['title']) ? $instance['title'] : '';
        $title_tpl       = isset($instance['title_template']) ? $instance['title_template'] : '';
        $date_mode       = isset($instance['date_mode']) ? $instance['date_mode'] : 'today_fallback';
        $pick_date       = isset($instance['pick_date']) ? $instance['pick_date'] : '';
        $first_lang      = isset($instance['first_language']) ? $instance['first_language'] : 'bible';
        $second_lang     = isset($instance['second_language']) ? $instance['second_language'] : '';
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
        if (!in_array($first_lang, ['bible', 'bibel', 'latin'], true)) {
            $first_lang = 'bible';
        }
        if (!in_array($second_lang, ['', 'bible', 'bibel', 'latin'], true)) {
            $second_lang = '';
        }
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
            $ref = TheBible_Plugin::get_votd_random();
        } elseif ($date_mode === 'pick_date' && $pick_date !== '') {
            $ref = TheBible_Plugin::get_votd_for_date($pick_date);
        } else { // today_fallback
            $ref = TheBible_Plugin::get_votd_for_date();
            if (!is_array($ref)) {
                $ref = TheBible_Plugin::get_votd_random();
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

        // Primary URL: English dataset
        $path_en = '/bible/' . trim($book_slug_en, '/') . '/' . $chapter . ':' . $vfrom . ($vto > $vfrom ? ('-' . $vto) : '');
        $url_en  = home_url($path_en);

        echo isset($args['before_widget']) ? $args['before_widget'] : '';

        if ($custom_css !== '') {
            $safe_css = (string) wp_strip_all_tags($custom_css);
            $safe_css = str_ireplace(['</style', '<style'], '', $safe_css);
            echo '<style class="thebible-votd-widget-css">' . $safe_css . '</style>';
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

        $widget_classes = 'thebible-votd-widget thebible-votd-widget-' . esc_attr($first_lang);
        if ($second_lang !== '') {
            $widget_classes .= ' thebible-votd-widget-dual';
        }
        echo '<div class="' . esc_attr($widget_classes) . '">';

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

        // Verse text blocks per language, using the new first/second language configuration
        $langs_to_show = [$first_lang];
        if ($second_lang !== '') {
            $langs_to_show[] = $second_lang;
        }

        foreach ($langs_to_show as $ds) {
            $text = isset($texts[$ds]) ? $texts[$ds] : '';
            if (!is_string($text) || $text === '') {
                continue;
            }

            // Normalize and clean quotation marks for widget output, and wrap once in outer guillemets
            $text = TheBible_Plugin::clean_verse_text_for_output($text, true, '»', '«');

            $short_ds = TheBible_Plugin::resolve_book_for_dataset($canonical, $ds);
            $book_slug_ds = TheBible_Plugin::slugify($short_ds ? $short_ds : $canonical);
            $path_ds = '/' . trim($ds, '/') . '/' . trim($book_slug_ds, '/') . '/' . $chapter . ':' . $vfrom . ($vto > $vfrom ? ('-' . $vto) : '');
            $url_ds  = home_url($path_ds);

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
            echo '<div class="thebible-votd-context">';
            echo '<a class="thebible-votd-context-link" href="' . esc_url( $url_ds ) . '">' . esc_html( $citation ) . '</a>';
            echo '</div>';
            echo '</div>';
        }

        // If multiple languages are shown, link to the unified dual-language display.
        if ($second_lang !== '') {
            $dual_slug = $first_lang . '-' . $second_lang;
            // Use canonical book slug; router will normalize further if needed.
            $hybrid_path = '/' . $dual_slug . '/' . trim($canonical, '/') . '/' . $chapter . ':' . $vfrom . ($vto > $vfrom ? ('-' . $vto) : '');
            $hybrid_url  = home_url($hybrid_path);

            echo '<div class="thebible-votd-interlinear-link">';
            echo '<a href="' . esc_url($hybrid_url) . '" class="thebible-votd-interlinear-button">' . esc_html__('View Bilingual', 'thebible') . '</a>';
            echo '</div>';
        }

        echo '</div>';

        echo isset($args['after_widget']) ? $args['after_widget'] : '';
    }

    public function form($instance) {
        $title        = isset($instance['title']) ? $instance['title'] : '';
        $title_tpl    = isset($instance['title_template']) ? $instance['title_template'] : '';
        $date_mode    = isset($instance['date_mode']) ? $instance['date_mode'] : 'today_fallback';
        $pick_date    = isset($instance['pick_date']) ? $instance['pick_date'] : '';
        $first_lang   = isset($instance['first_language']) ? $instance['first_language'] : 'bible';
        $second_lang  = isset($instance['second_language']) ? $instance['second_language'] : '';
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
        if (!in_array($first_lang, ['bible', 'bibel', 'latin'], true)) {
            $first_lang = 'bible';
        }
        if (!in_array($second_lang, ['', 'bible', 'bibel', 'latin'], true)) {
            $second_lang = '';
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

        // First language selector
        $first_lang_id = $this->get_field_id('first_language');
        $first_lang_name = $this->get_field_name('first_language');
        $first_lang = isset($instance['first_language']) ? $instance['first_language'] : 'bible';
        
        // Second language selector  
        $second_lang_id = $this->get_field_id('second_language');
        $second_lang_name = $this->get_field_name('second_language');
        $second_lang = isset($instance['second_language']) ? $instance['second_language'] : '';

        echo '<p>';
        echo '<label for="' . esc_attr($first_lang_id) . '">' . esc_html__('First Language:', 'thebible') . '</label> ';
        echo '<select id="' . esc_attr($first_lang_id) . '" name="' . esc_attr($first_lang_name) . '">';
        echo '<option value="bible"' . selected($first_lang, 'bible', false) . '>' . esc_html__('English (Douay)', 'thebible') . '</option>';
        echo '<option value="bibel"' . selected($first_lang, 'bibel', false) . '>' . esc_html__('German (Menge)', 'thebible') . '</option>';
        echo '<option value="latin"' . selected($first_lang, 'latin', false) . '>' . esc_html__('Latin (Vulgate)', 'thebible') . '</option>';
        echo '</select>';
        echo '</p>';

        echo '<p>';
        echo '<label for="' . esc_attr($second_lang_id) . '">' . esc_html__('Second Language (Optional):', 'thebible') . '</label> ';
        echo '<select id="' . esc_attr($second_lang_id) . '" name="' . esc_attr($second_lang_name) . '">';
        echo '<option value=""' . selected($second_lang, '', false) . '>' . esc_html__('None (single language)', 'thebible') . '</option>';
        echo '<option value="bible"' . selected($second_lang, 'bible', false) . '>' . esc_html__('English (Douay)', 'thebible') . '</option>';
        echo '<option value="bibel"' . selected($second_lang, 'bibel', false) . '>' . esc_html__('German (Menge)', 'thebible') . '</option>';
        echo '<option value="latin"' . selected($second_lang, 'latin', false) . '>' . esc_html__('Latin (Vulgate)', 'thebible') . '</option>';
        echo '</select>';
        echo '</p>';

    }

}
