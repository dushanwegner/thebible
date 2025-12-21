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
        // Ensure interlinear_langs is always an array
        if (isset($new_instance['interlinear_langs']) && !is_array($new_instance['interlinear_langs'])) {
            $new_instance['interlinear_langs'] = ['bible'];
        } elseif (!isset($new_instance['interlinear_langs'])) {
            $new_instance['interlinear_langs'] = ['bible', 'bibel', 'latin'];
        }
        
        // Ensure at least one language is selected
        if (empty($new_instance['interlinear_langs'])) {
            $new_instance['interlinear_langs'] = ['bible'];
        }
        
        return $new_instance;
    }

    public function widget($args, $instance) {
        $title           = isset($instance['title']) ? $instance['title'] : '';
        $title_tpl       = isset($instance['title_template']) ? $instance['title_template'] : '';
        $date_mode       = isset($instance['date_mode']) ? $instance['date_mode'] : 'today_fallback';
        $pick_date       = isset($instance['pick_date']) ? $instance['pick_date'] : '';
        $lang_mode       = isset($instance['lang_mode']) ? $instance['lang_mode'] : 'bible';
        $custom_css      = isset($instance['custom_css']) ? $instance['custom_css'] : '';
        $tpl_en          = isset($instance['template_en']) ? $instance['template_en'] : '';
        $tpl_de          = isset($instance['template_de']) ? $instance['template_de'] : '';
        $date_fmt_mode   = isset($instance['date_format_mode']) ? $instance['date_format_mode'] : '';
        $interlinear_langs = isset($instance['interlinear_langs']) ? $instance['interlinear_langs'] : ['bible', 'bibel', 'latin'];

        if (!is_string($title)) $title = '';
        if (!is_string($title_tpl)) $title_tpl = '';
        if (!in_array($date_mode, ['today_fallback', 'random', 'pick_date'], true)) {
            $date_mode = 'today_fallback';
        }
        if (!is_string($pick_date)) $pick_date = '';
        if (!in_array($lang_mode, ['bible', 'bibel', 'latin', 'both', 'interlinear'], true)) {
            $lang_mode = 'bible';
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

        $widget_classes = 'thebible-votd-widget thebible-votd-widget-' . esc_attr($lang_mode);
        if ($lang_mode === 'interlinear') {
            $widget_classes .= ' thebible-votd-widget-interlinear';
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

        // Verse text blocks per language, using a fixed layout (no per-language templates)
        $langs_to_show = [];
        if ($lang_mode === 'both') {
            $langs_to_show = ['bible', 'bibel'];
        } elseif ($lang_mode === 'interlinear') {
            $langs_to_show = $interlinear_langs;
        } else {
            $langs_to_show = [$lang_mode];
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
            
            // Add language label for interlinear mode
            if ($lang_mode === 'interlinear') {
                $lang_label = '';
                if ($ds === 'bible') {
                    $lang_label = 'EN';
                } elseif ($ds === 'bibel') {
                    $lang_label = 'DE';
                } elseif ($ds === 'latin') {
                    $lang_label = 'LA';
                }
                echo '<div class="thebible-votd-lang-label">' . esc_html($lang_label) . '</div>';
            }
            
            echo '<p class="thebible-votd-text">' . wp_kses_post($text) . '</p>';
            echo '<div class="thebible-votd-context">';
            echo '<a class="thebible-votd-context-link" href="' . esc_url( $url_ds ) . '">' . esc_html( $citation ) . '</a>';
            echo '</div>';
            echo '</div>';
        }

        // Add interlinear link if showing multiple languages or if there are at least 2 languages available
        if (count($langs_to_show) > 1 || ($lang_mode !== 'interlinear' && count(['bible', 'bibel', 'latin']) >= 2)) {
            // Build interlinear URL
            $interlinear_path = '/interlinear/' . trim($book_slug_en, '/') . '/' . $chapter . ':' . $vfrom . ($vto > $vfrom ? ('-' . $vto) : '');
            $interlinear_url = home_url($interlinear_path);
            
            echo '<div class="thebible-votd-interlinear-link">';
            echo '<a href="' . esc_url($interlinear_url) . '" class="thebible-votd-interlinear-button">' . esc_html__('View Interlinear', 'thebible') . '</a>';
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
        $lang_mode    = isset($instance['lang_mode']) ? $instance['lang_mode'] : 'bible';
        $custom_css   = isset($instance['custom_css']) ? $instance['custom_css'] : '';
        $tpl_en       = isset($instance['template_en']) ? $instance['template_en'] : '';
        $tpl_de       = isset($instance['template_de']) ? $instance['template_de'] : '';
        $date_fmt_mode = isset($instance['date_format_mode']) ? $instance['date_format_mode'] : '';
        $interlinear_langs = isset($instance['interlinear_langs']) ? $instance['interlinear_langs'] : ['bible', 'bibel', 'latin'];
        if (!is_string($title)) $title = '';
        if (!is_string($title_tpl)) $title_tpl = '';
        if (!in_array($date_mode, ['today_fallback', 'random', 'pick_date'], true)) {
            $date_mode = 'today_fallback';
        }
        if (!is_string($pick_date)) $pick_date = '';
        if (!in_array($lang_mode, ['bible', 'bibel', 'latin', 'both', 'interlinear'], true)) {
            $lang_mode = 'bible';
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
        $lang_mode_id = $this->get_field_id('lang_mode');
        $lang_mode_name = $this->get_field_name('lang_mode');
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
        echo '<label for="' . esc_attr($lang_mode_id) . '">' . esc_html__('Language(s):', 'thebible') . '</label> ';
        echo '<select id="' . esc_attr($lang_mode_id) . '" name="' . esc_attr($lang_mode_name) . '" class="thebible-votd-lang-mode">';
        echo '<option value="bible"' . selected($lang_mode, 'bible', false) . '>' . esc_html__('English Bible only', 'thebible') . '</option>';
        echo '<option value="bibel"' . selected($lang_mode, 'bibel', false) . '>' . esc_html__('German Bibel only', 'thebible') . '</option>';
        echo '<option value="latin"' . selected($lang_mode, 'latin', false) . '>' . esc_html__('Latin Vulgate only', 'thebible') . '</option>';
        echo '<option value="both"' . selected($lang_mode, 'both', false) . '>' . esc_html__('Multiple languages (stacked)', 'thebible') . '</option>';
        echo '<option value="interlinear"' . selected($lang_mode, 'interlinear', false) . '>' . esc_html__('Interlinear (side by side)', 'thebible') . '</option>';
        echo '</select>';
        echo '</p>';
        
        // Language selection for interlinear mode
        $interlinear_langs_id = $this->get_field_id('interlinear_langs');
        $interlinear_langs_name = $this->get_field_name('interlinear_langs');
        
        echo '<div class="thebible-votd-interlinear-langs" style="' . ($lang_mode === 'interlinear' ? '' : 'display:none;') . ' padding-left: 15px; margin-bottom: 10px;">';
        echo '<p>' . esc_html__('Select languages for interlinear display:', 'thebible') . '</p>';
        
        $available_langs = [
            'bible' => __('English', 'thebible'),
            'bibel' => __('German', 'thebible'),
            'latin' => __('Latin', 'thebible')
        ];
        
        foreach ($available_langs as $lang_key => $lang_label) {
            $checked = in_array($lang_key, $interlinear_langs) ? 'checked' : '';
            echo '<label style="display:block; margin-bottom:5px;">';
            echo '<input type="checkbox" name="' . esc_attr($interlinear_langs_name) . '[]" value="' . esc_attr($lang_key) . '" ' . $checked . '> ';
            echo esc_html($lang_label);
            echo '</label>';
        }
        
        echo '</div>';
        
        // Add JavaScript to show/hide interlinear language options
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                $(".thebible-votd-lang-mode").on("change", function() {
                    if ($(this).val() === "interlinear") {
                        $(this).closest("form").find(".thebible-votd-interlinear-langs").show();
                    } else {
                        $(this).closest("form").find(".thebible-votd-interlinear-langs").hide();
                    }
                });
            });
        </script>';

    }

}
