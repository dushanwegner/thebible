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

    public function widget($args, $instance) {
        $title      = isset($instance['title']) ? $instance['title'] : '';
        $date_mode  = isset($instance['date_mode']) ? $instance['date_mode'] : 'today_fallback';
        $pick_date  = isset($instance['pick_date']) ? $instance['pick_date'] : '';
        $lang_mode  = isset($instance['lang_mode']) ? $instance['lang_mode'] : 'bible';
        $custom_css = isset($instance['custom_css']) ? $instance['custom_css'] : '';
        $tpl_en     = isset($instance['template_en']) ? $instance['template_en'] : '';
        $tpl_de     = isset($instance['template_de']) ? $instance['template_de'] : '';

        if (!is_string($title)) $title = '';
        if (!in_array($date_mode, ['today_fallback', 'random', 'pick_date'], true)) {
            $date_mode = 'today_fallback';
        }
        if (!is_string($pick_date)) $pick_date = '';
        if (!in_array($lang_mode, ['bible', 'bibel', 'both'], true)) {
            $lang_mode = 'bible';
        }
        if (!is_string($custom_css)) $custom_css = '';
        if (!is_string($tpl_en)) $tpl_en = '';
        if (!is_string($tpl_de)) $tpl_de = '';

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
                $display_date = date_i18n(get_option('date_format'), $ts);
            }
        }

        // Default templates if none set (same structure for all languages; date is localized via date_i18n)
        // Note: {votd-content} already includes cleaned quotation marks from clean_verse_quotes(),
        // so we do NOT wrap it in additional guillemets here.
        $default_tpl = "<h2 class=\"thebible-votd-heading\">Vers des Tages {votd-date}</h2>\n"
                     . "<p class=\"thebible-votd-text\">{votd-content}</p>\n"
                     . "<div class=\"thebible-votd-context\"><a class=\"thebible-votd-context-link\" href=\"{votd-link}\">{votd-citation}, jetzt lesen </a></div>";

        if ($tpl_en === '') {
            $tpl_en = $default_tpl;
        }
        if ($tpl_de === '') {
            $tpl_de = $default_tpl;
        }

        echo '<div class="thebible-votd-widget thebible-votd-widget-' . esc_attr($lang_mode) . '">';

        // Verse text blocks per language
        $langs_to_show = [];
        if ($lang_mode === 'both') {
            $langs_to_show = ['bible', 'bibel'];
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

            // Choose template per language
            $tpl = ($ds === 'bibel') ? $tpl_de : $tpl_en;

            // Prepare share payloads (mirroring frontend behavior):
            // cleaned verse text wrapped in guillemets, plus citation and URL.
            $share_core   = trim((string) $text);
            $share_ref    = (string) $citation;
            $share_url    = (string) $url_ds;
            $share_text   = trim($share_core . ' (' . $share_ref . ') ' . $share_url);
            $share_text_q = rawurlencode($share_text);
            $share_url_q  = rawurlencode($share_url);

            // X (Twitter) and Facebook share URLs
            $share_x_url  = 'https://x.com/intent/tweet?text=' . $share_text_q;
            $share_fb_url = 'https://www.facebook.com/sharer/sharer.php?u=' . $share_url_q . '&quote=' . $share_text_q;

            // Handle parameterized placeholders first, e.g. {post-to-x linktext="nach X posten"}
            $tpl = preg_replace_callback(
                '/\{post-to-x\s+linktext="([^"]*)"\}/',
                function ( $m ) use ( $share_x_url ) {
                    $txt = isset( $m[1] ) ? $m[1] : '';
                    return '<a href="' . esc_url( $share_x_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $txt ) . '</a>';
                },
                $tpl
            );

            $tpl = preg_replace_callback(
                '/\{post-to-facebook\s+linktext="([^"]*)"\}/',
                function ( $m ) use ( $share_fb_url ) {
                    $txt = isset( $m[1] ) ? $m[1] : '';
                    return '<a href="' . esc_url( $share_fb_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $txt ) . '</a>';
                },
                $tpl
            );

            // Default full HTML links for bare placeholders
            $share_x_link  = '<a href="' . esc_url( $share_x_url ) . '" target="_blank" rel="noopener noreferrer">Post to X</a>';
            $share_fb_link = '<a href="' . esc_url( $share_fb_url ) . '" target="_blank" rel="noopener noreferrer">Post to Facebook</a>';

            // Prepare placeholder replacements
            $replacements = [
                '{votd-date}'         => (string) $display_date,
                '{votd-content}'      => (string) $text,
                '{votd-citation}'     => (string) $citation,
                '{votd-link}'         => (string) $url_ds,
                // These render full <a> elements
                '{post-to-x}'         => (string) $share_x_link,
                '{post-to-facebook}'  => (string) $share_fb_link,
            ];

            $rendered = strtr($tpl, $replacements);

            echo '<div class="thebible-votd-lang thebible-votd-lang-' . esc_attr($ds) . '">';
            echo wp_kses_post($rendered);
            echo '</div>';
        }

        echo '</div>';

        echo isset($args['after_widget']) ? $args['after_widget'] : '';
    }

    public function form($instance) {
        $title      = isset($instance['title']) ? $instance['title'] : '';
        $date_mode  = isset($instance['date_mode']) ? $instance['date_mode'] : 'today_fallback';
        $pick_date  = isset($instance['pick_date']) ? $instance['pick_date'] : '';
        $lang_mode  = isset($instance['lang_mode']) ? $instance['lang_mode'] : 'bible';
        $custom_css = isset($instance['custom_css']) ? $instance['custom_css'] : '';
        $tpl_en     = isset($instance['template_en']) ? $instance['template_en'] : '';
        $tpl_de     = isset($instance['template_de']) ? $instance['template_de'] : '';
        if (!is_string($title)) $title = '';
        if (!in_array($date_mode, ['today_fallback', 'random', 'pick_date'], true)) {
            $date_mode = 'today_fallback';
        }
        if (!is_string($pick_date)) $pick_date = '';
        if (!in_array($lang_mode, ['bible', 'bibel', 'both'], true)) {
            $lang_mode = 'bible';
        }
        if (!is_string($custom_css)) $custom_css = '';
        if (!is_string($tpl_en)) $tpl_en = '';
        if (!is_string($tpl_de)) $tpl_de = '';

        // Show default templates in the form when empty (same structure for all languages)
        $default_tpl = "<h2 class=\"thebible-votd-heading\">Vers des Tages {votd-date}</h2>\n"
                     . "<p class=\"thebible-votd-text\">{votd-content}</p>\n"
                     . "<div class=\"thebible-votd-context\"><a class=\"thebible-votd-context-link\" href=\"{votd-link}\">{votd-citation}, jetzt lesen </a></div>";

        if ($tpl_en === '') {
            $tpl_en = $default_tpl;
        }
        if ($tpl_de === '') {
            $tpl_de = $default_tpl;
        }

        $title_id = $this->get_field_id('title');
        $title_name = $this->get_field_name('title');
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

        echo '<p>';
        echo '<label for="' . esc_attr($title_id) . '">' . esc_html__('Title:', 'thebible') . '</label> ';
        echo '<input class="widefat" id="' . esc_attr($title_id) . '" name="' . esc_attr($title_name) . '" type="text" value="' . esc_attr($title) . '" />';
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
        echo '<select id="' . esc_attr($lang_mode_id) . '
