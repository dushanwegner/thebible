<?php

if (!defined('ABSPATH')) {
    exit;
}

class TheBible_VOTD_Admin {
    public static function register_votd_cpt() {
        $labels = [
            'name'                  => __('Verses of the Day', 'thebible'),
            'singular_name'         => __('Verse of the Day', 'thebible'),
            'add_new'               => __('Add New', 'thebible'),
            'add_new_item'          => __('Add New Verse of the Day', 'thebible'),
            'edit_item'             => __('Edit Verse of the Day', 'thebible'),
            'new_item'              => __('New Verse of the Day', 'thebible'),
            'view_item'             => __('View Verse of the Day', 'thebible'),
            'search_items'          => __('Search Verses of the Day', 'thebible'),
            'not_found'             => __('No verses of the day found.', 'thebible'),
            'not_found_in_trash'    => __('No verses of the day found in Trash.', 'thebible'),
            'all_items'             => __('Verses of the Day', 'thebible'),
            'menu_name'             => __('Verse of the Day', 'thebible'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => false,
            'show_in_admin_bar'  => false,
            'exclude_from_search'=> true,
            'publicly_queryable' => false,
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => [],
            'menu_position'      => null,
        ];

        register_post_type('thebible_votd', $args);
    }

    public static function votd_columns($columns) {
        if (!is_array($columns)) {
            $columns = [];
        }
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['votd_date'] = __('VOTD Date', 'thebible');
                $new['votd_texts'] = __('EN/DE/LA', 'thebible');
            }
        }
        if (!isset($new['votd_date'])) {
            $new['votd_date'] = __('VOTD Date', 'thebible');
        }
        if (!isset($new['votd_texts'])) {
            $new['votd_texts'] = __('EN/DE/LA', 'thebible');
        }
        return $new;
    }

    public static function votd_sortable_columns($columns) {
        if (!is_array($columns)) {
            $columns = [];
        }
        $columns['votd_date'] = 'votd_date';
        return $columns;
    }

    public static function render_votd_column($column, $post_id) {
        if ($column === 'votd_date') {
            $date = get_post_meta($post_id, '_thebible_votd_date', true);
            if (!is_string($date) || $date === '') {
                echo '&mdash;';
                return;
            }
            $ts = strtotime($date . ' 00:00:00');
            if ($ts) {
                $human = date_i18n('Y-m-d (D)', $ts);
            } else {
                $human = $date;
            }
            echo esc_html($human);
            return;
        }

        if ($column === 'votd_texts') {
            $all = get_option('thebible_votd_all', []);
            $en = '';
            $de = '';
            $la = '';
            if (is_array($all)) {
                foreach ($all as $entry) {
                    if (isset($entry['post_id']) && (int) $entry['post_id'] === (int) $post_id && isset($entry['texts']) && is_array($entry['texts'])) {
                        if (isset($entry['texts']['bible']) && is_string($entry['texts']['bible'])) {
                            $en = $entry['texts']['bible'];
                        }
                        if (isset($entry['texts']['bibel']) && is_string($entry['texts']['bibel'])) {
                            $de = $entry['texts']['bibel'];
                        }
                        if (isset($entry['texts']['latin']) && is_string($entry['texts']['latin'])) {
                            $la = $entry['texts']['latin'];
                        }
                        break;
                    }
                }
            }

            if ($en === '' && $de === '' && $la === '') {
                echo '<small>&mdash;</small>';
                return;
            }

            echo '<small>';
            if ($en !== '') {
                echo '<strong>EN:</strong> ' . esc_html(trim((string) $en));
            }
            if ($de !== '') {
                if ($en !== '') {
                    echo '<br />';
                }
                echo '<strong>DE:</strong> ' . esc_html(trim((string) $de));
            }
            if ($la !== '') {
                if ($en !== '' || $de !== '') {
                    echo '<br />';
                }
                echo '<strong>LA:</strong> ' . esc_html(trim((string) $la));
            }
            echo '</small>';
        }
    }

    public static function votd_date_filter() {
        global $typenow;
        if ($typenow !== 'thebible_votd') {
            return;
        }
        $map = get_option('thebible_votd_by_date', []);
        if (!is_array($map) || empty($map)) {
            return;
        }
        $months = [];
        foreach ($map as $d => $_entry) {
            if (!is_string($d) || $d === '') {
                continue;
            }
            if (preg_match('/^(\d{4}-\d{2})-\d{2}$/', $d, $m)) {
                $months[$m[1]] = true;
            }
        }
        if (empty($months)) {
            return;
        }
        $months = array_keys($months);
        sort($months);
        $selected = isset($_GET['thebible_votd_month']) ? (string) wp_unslash($_GET['thebible_votd_month']) : '';

        $cleanup_url = wp_nonce_url(add_query_arg(['thebible_votd_action' => 'cleanup']), 'thebible_votd_cleanup');
        echo '<a href="' . esc_url($cleanup_url) . '" class="button">' . esc_html__('Clean up VOTD schedule', 'thebible') . '</a> ';

        $shuffle_all_url = wp_nonce_url(add_query_arg(['thebible_votd_action' => 'shuffle_all']), 'thebible_votd_shuffle_all');
        echo '<a href="' . esc_url($shuffle_all_url) . '" class="button">' . esc_html__('Shuffle all VOTDs', 'thebible') . '</a> ';

        $shuffle_all_not_today_url = wp_nonce_url(add_query_arg(['thebible_votd_action' => 'shuffle_all_not_today']), 'thebible_votd_shuffle_all_not_today');
        echo '<a href="' . esc_url($shuffle_all_not_today_url) . '" class="button">' . esc_html__('Shuffle all VOTDs (except today)', 'thebible') . '</a> ';

        $flush_cache_url = wp_nonce_url(add_query_arg(['thebible_votd_action' => 'flush_cache']), 'thebible_votd_flush_cache');
        echo '<a href="' . esc_url($flush_cache_url) . '" class="button">' . esc_html__('Flush VOTD cache', 'thebible') . '</a> ';

        echo '<label for="thebible_votd_month" class="screen-reader-text">' . esc_html__('Filter by VOTD month', 'thebible') . '</label>';
        echo '<select name="thebible_votd_month" id="thebible_votd_month">';
        echo '<option value="">' . esc_html__('All VOTD months', 'thebible') . '</option>';
        foreach ($months as $m) {
            $label = $m;
            $ts = strtotime($m . '-01 00:00:00');
            if ($ts) {
                $label = date_i18n('F Y', $ts);
            }
            echo '<option value="' . esc_attr($m) . '"' . selected($selected, $m, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public static function apply_votd_date_filter($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $post_type = $screen && isset($screen->post_type) ? $screen->post_type : (isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '');
        if ($post_type !== 'thebible_votd') {
            return;
        }

        $month = isset($_GET['thebible_votd_month']) ? sanitize_text_field(wp_unslash($_GET['thebible_votd_month'])) : '';

        $meta_query = (array) $query->get('meta_query');

        if ($month !== '') {
            $meta_query[] = [
                'key' => '_thebible_votd_date',
                'value' => $month . '-',
                'compare' => 'LIKE',
            ];
        }

        $show_all = isset($_GET['thebible_votd_show_all']) && $_GET['thebible_votd_show_all'] === '1';
        if (!$show_all) {
            $today = current_time('Y-m-d');
            $meta_query[] = [
                'key' => '_thebible_votd_date',
                'value' => $today,
                'compare' => '>=',
                'type' => 'CHAR',
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }

        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : '';
        $order = isset($_GET['order']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['order']))) : '';

        if ($orderby === '' || $orderby === 'votd_date') {
            $query->set('meta_key', '_thebible_votd_date');
            $query->set('orderby', 'meta_value');
            $query->set('order', ($order === 'DESC' && $orderby === 'votd_date') ? 'DESC' : 'ASC');
        }
    }

    public static function handle_votd_condense_request() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-thebible_votd') {
            return;
        }
        if (!isset($_GET['thebible_votd_action'])) {
            return;
        }
        $action = (string) $_GET['thebible_votd_action'];
        if (!current_user_can('edit_posts')) {
            return;
        }

        if ($action === 'flush_cache') {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'thebible_votd_flush_cache')) {
                return;
            }

            self::rebuild_votd_cache();

            $redirect = remove_query_arg(['thebible_votd_action', '_wpnonce']);
            $redirect = add_query_arg(['thebible_votd_cache_flushed' => 1], $redirect);
            wp_safe_redirect($redirect);
            exit;
        }

        if ($action === 'cleanup') {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'thebible_votd_cleanup')) {
                return;
            }

            self::rebuild_votd_cache();
            $all = get_option('thebible_votd_all', []);
            if (!is_array($all) || empty($all)) {
                $redirect = remove_query_arg(['thebible_votd_action', '_wpnonce']);
                $redirect = add_query_arg(['thebible_votd_condensed' => 1, 'moved' => 0, 'gaps' => 0], $redirect);
                wp_safe_redirect($redirect);
                exit;
            }

            $today = current_time('Y-m-d');
            $entries = [];
            foreach ($all as $entry) {
                if (!is_array($entry) || !isset($entry['date']) || !is_string($entry['date']) || $entry['date'] === '') {
                    continue;
                }
                if ($entry['date'] < $today) {
                    continue;
                }
                $entries[] = $entry;
            }

            if (count($entries) > 1) {
                shuffle($entries);
            }

            if (empty($entries)) {
                $redirect = remove_query_arg(['thebible_votd_action', '_wpnonce']);
                $redirect = add_query_arg(['thebible_votd_condensed' => 1, 'moved' => 0, 'gaps' => 0], $redirect);
                wp_safe_redirect($redirect);
                exit;
            }

            $first_date = $entries[0]['date'];
            $last_date = $entries[count($entries) - 1]['date'];
            $gaps = 0;
            if (is_string($first_date) && is_string($last_date) && $first_date !== '' && $last_date !== '') {
                $dt_first = new DateTime(max($today, $first_date));
                $dt_last = new DateTime($last_date);
                if ($dt_last >= $dt_first) {
                    $span_days = (int) $dt_first->diff($dt_last)->days + 1;
                    $gaps = max(0, $span_days - count($entries));
                }
            }

            $cursor = new DateTime($today);
            $moved = 0;
            foreach ($entries as $entry) {
                if (!isset($entry['post_id'])) {
                    continue;
                }
                $post_id = (int) $entry['post_id'];
                $new_date = $cursor->format('Y-m-d');
                if (!isset($entry['date']) || !is_string($entry['date']) || $entry['date'] !== $new_date) {
                    update_post_meta($post_id, '_thebible_votd_date', $new_date);

                    $post_obj = get_post($post_id);
                    if ($post_obj && $post_obj->post_type === 'thebible_votd') {
                        $norm = self::normalize_votd_entry($post_obj);
                        if (is_array($norm)) {
                            $book_key = $norm['book_slug'];
                            $short = TheBible_Plugin::resolve_book_for_dataset($book_key, 'bible');
                            if (!is_string($short) || $short === '') {
                                $label = ucwords(str_replace('-', ' ', (string) $book_key));
                            } else {
                                $label = TheBible_Plugin::pretty_label($short);
                            }
                            $ref = $label . ' ' . $norm['chapter'] . ':' . ($norm['vfrom'] === $norm['vto'] ? $norm['vfrom'] : ($norm['vfrom'] . '-' . $norm['vto']));
                            $title = $ref . ' (' . $new_date . ')';

                            wp_update_post([
                                'ID' => $post_id,
                                'post_title' => $title,
                                'post_name' => sanitize_title($title),
                            ]);
                        }
                    }

                    $moved++;
                }
                $cursor->modify('+1 day');
            }

            self::rebuild_votd_cache();

            $redirect = remove_query_arg(['thebible_votd_action', '_wpnonce']);
            $redirect = add_query_arg(['thebible_votd_condensed' => 1, 'moved' => $moved, 'gaps' => $gaps], $redirect);
            wp_safe_redirect($redirect);
            exit;
        }

        if ($action === 'shuffle_all' || $action === 'shuffle_all_not_today') {
            $nonce_action = ($action === 'shuffle_all') ? 'thebible_votd_shuffle_all' : 'thebible_votd_shuffle_all_not_today';
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], $nonce_action)) {
                return;
            }

            $redirect = remove_query_arg(['thebible_votd_action', '_wpnonce']);
            $doaction = ($action === 'shuffle_all') ? 'thebible_votd_shuffle_all' : 'thebible_votd_shuffle_all_not_today';
            $redirect2 = self::votd_handle_bulk_actions($redirect, $doaction, []);
            wp_safe_redirect($redirect2);
            exit;
        }
    }

    public static function votd_condense_notice() {
        if (!is_admin()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-thebible_votd') {
            return;
        }
        $did_cleanup = isset($_GET['thebible_votd_condensed']);
        $shuffled = isset($_GET['thebible_votd_shuffled']) ? (string) $_GET['thebible_votd_shuffled'] : '';
        $cache_flushed = isset($_GET['thebible_votd_cache_flushed']);

        if (!$did_cleanup && $shuffled === '' && !$cache_flushed) {
            return;
        }

        if ($did_cleanup) {
            $moved = isset($_GET['moved']) ? (int) $_GET['moved'] : 0;
            $gaps = isset($_GET['gaps']) ? (int) $_GET['gaps'] : 0;

            if ($moved === 0 && $gaps === 0) {
                echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('VOTD schedule is already clean; no changes were made.', 'thebible') . '</p></div>';
            } elseif ($gaps > 0) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf(esc_html__('Cleaned up Verse-of-the-Day schedule: filled %1$d empty day slots and adjusted %2$d entries from today forward.', 'thebible'), $gaps, $moved) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(esc_html__('Cleaned up Verse-of-the-Day schedule: adjusted %1$d entries from today forward.', 'thebible'), $moved) . '</p></div>';
            }
        }

        if ($shuffled !== '') {
            if ($shuffled === 'selected') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Shuffled selected VOTD entries.', 'thebible') . '</p></div>';
            } elseif ($shuffled === 'all_not_today') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Shuffled all VOTD entries except today.', 'thebible') . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Shuffled all VOTD entries.', 'thebible') . '</p></div>';
            }
        }

        if ($cache_flushed) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Flushed Verse-of-the-Day cache.', 'thebible') . '</p></div>';
        }
    }

    public static function add_votd_meta_box() {
        add_meta_box(
            'thebible_votd_meta',
            __('Verse reference', 'thebible'),
            [__CLASS__, 'render_votd_meta_box'],
            'thebible_votd',
            'normal',
            'default'
        );
    }

    public static function render_votd_meta_box($post) {
        wp_nonce_field('thebible_votd_meta_save', 'thebible_votd_meta_nonce');

        $book = get_post_meta($post->ID, '_thebible_votd_book', true);
        $ch = get_post_meta($post->ID, '_thebible_votd_chapter', true);
        $vfrom = get_post_meta($post->ID, '_thebible_votd_vfrom', true);
        $vto = get_post_meta($post->ID, '_thebible_votd_vto', true);
        $date = get_post_meta($post->ID, '_thebible_votd_date', true);

        if (!is_string($book)) $book = '';
        if (!is_string($date)) $date = '';
        $ch = (string) (int) $ch;
        $vfrom = (string) (int) $vfrom;
        $vto = (string) (int) $vto;

        $canonical_books = TheBible_Plugin::list_canonical_books();

        echo '<p>' . esc_html__('Define the Bible reference and optional calendar date for this verse-of-the-day entry.', 'thebible') . '</p>';

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="thebible_votd_book">' . esc_html__('Book', 'thebible') . '</label></th><td>';
        echo '<select id="thebible_votd_book" name="thebible_votd_book">';
        echo '<option value="">' . esc_html__('Select a book…', 'thebible') . '</option>';
        if (is_array($canonical_books)) {
            foreach ($canonical_books as $key) {
                if (!is_string($key) || $key === '') continue;
                $label = $key;
                $label = str_replace('-', ' ', $label);
                $label = ucwords($label);
                echo '<option value="' . esc_attr($key) . '"' . selected($book, $key, false) . '>' . esc_html($label) . '</option>';
            }
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Canonical book key used to map to all Bible datasets (e.g. "john", "psalms").', 'thebible') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="thebible_votd_chapter">' . esc_html__('Chapter', 'thebible') . '</label></th><td>';
        echo '<input type="number" min="1" step="1" class="small-text" id="thebible_votd_chapter" name="thebible_votd_chapter" value="' . esc_attr($ch) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="thebible_votd_vfrom">' . esc_html__('First verse', 'thebible') . '</label></th><td>';
        echo '<input type="number" min="1" step="1" class="small-text" id="thebible_votd_vfrom" name="thebible_votd_vfrom" value="' . esc_attr($vfrom) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="thebible_votd_vto">' . esc_html__('Last verse', 'thebible') . '</label></th><td>';
        echo '<input type="number" min="1" step="1" class="small-text" id="thebible_votd_vto" name="thebible_votd_vto" value="' . esc_attr($vto) . '" />';
        echo '<p class="description">' . esc_html__('If empty or smaller than first verse, the range is treated as a single verse.', 'thebible') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="thebible_votd_date">' . esc_html__('Calendar date (optional)', 'thebible') . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="thebible_votd_date" name="thebible_votd_date" value="' . esc_attr($date) . '" placeholder="YYYY-MM-DD" />';
        echo '<p class="description">' . esc_html__('If set, this entry will be used as the verse of the day for that local date (widget mode "today").', 'thebible') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        $entry_for_preview = [
            'book_slug' => $book,
            'chapter' => (int) $ch,
            'vfrom' => (int) $vfrom,
            'vto' => (int) ((int) $vto > 0 ? $vto : $vfrom),
            'date' => $date,
        ];
        $texts = self::extract_votd_texts_for_entry($entry_for_preview);
        if (is_array($texts) && (!empty($texts['bible']) || !empty($texts['bibel']) || !empty($texts['latin']))) {
            echo '<h3>' . esc_html__('Preview (cached verse text)', 'thebible') . '</h3>';
            echo '<p class="description">' . esc_html__('These snippets are read from the verse cache for English (Douay), German (Menge), and Latin.', 'thebible') . '</p>';
            echo '<div style="padding:.5em 1em;border:1px solid #ccd0d4;background:#f6f7f7;max-width:48em;">';
            if (!empty($texts['bible']) && is_string($texts['bible'])) {
                echo '<p><strong>EN:</strong> ' . esc_html($texts['bible']) . '</p>';
            }
            if (!empty($texts['bibel']) && is_string($texts['bibel'])) {
                echo '<p><strong>DE:</strong> ' . esc_html($texts['bibel']) . '</p>';
            }
            if (!empty($texts['latin']) && is_string($texts['latin'])) {
                echo '<p><strong>LA:</strong> ' . esc_html($texts['latin']) . '</p>';
            }
            echo '</div>';
        }
    }

    public static function save_votd_meta($post_id, $post) {
        if ($post->post_type !== 'thebible_votd') {
            return;
        }
        if (!isset($_POST['thebible_votd_meta_nonce']) || !wp_verify_nonce($_POST['thebible_votd_meta_nonce'], 'thebible_votd_meta_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $book = isset($_POST['thebible_votd_book']) ? sanitize_text_field(wp_unslash($_POST['thebible_votd_book'])) : '';
        $ch = isset($_POST['thebible_votd_chapter']) ? (int) $_POST['thebible_votd_chapter'] : 0;
        $vfrom = isset($_POST['thebible_votd_vfrom']) ? (int) $_POST['thebible_votd_vfrom'] : 0;
        $vto = isset($_POST['thebible_votd_vto']) ? (int) $_POST['thebible_votd_vto'] : 0;
        $date = isset($_POST['thebible_votd_date']) ? sanitize_text_field(wp_unslash($_POST['thebible_votd_date'])) : '';

        if ($book !== '') {
            update_post_meta($post_id, '_thebible_votd_book', $book);
        } else {
            delete_post_meta($post_id, '_thebible_votd_book');
        }

        if ($ch > 0) {
            update_post_meta($post_id, '_thebible_votd_chapter', $ch);
        } else {
            delete_post_meta($post_id, '_thebible_votd_chapter');
        }

        if ($vfrom > 0) {
            update_post_meta($post_id, '_thebible_votd_vfrom', $vfrom);
        } else {
            delete_post_meta($post_id, '_thebible_votd_vfrom');
        }

        if ($vto > 0) {
            update_post_meta($post_id, '_thebible_votd_vto', $vto);
        } else {
            delete_post_meta($post_id, '_thebible_votd_vto');
        }

        if ($date !== '') {
            $date = preg_replace('/[^0-9\-]/', '', $date);
            update_post_meta($post_id, '_thebible_votd_date', $date);
        } else {
            $date = current_time('Y-m-d');
            update_post_meta($post_id, '_thebible_votd_date', $date);
        }

        $entry = self::normalize_votd_entry(get_post($post_id));
        if (is_array($entry)) {
            $book_key = $entry['book_slug'];
            $short = TheBible_Plugin::resolve_book_for_dataset($book_key, 'bible');
            if (!is_string($short) || $short === '') {
                $label = ucwords(str_replace('-', ' ', (string) $book_key));
            } else {
                $label = TheBible_Plugin::pretty_label($short);
            }
            $ref = $label . ' ' . $entry['chapter'] . ':' . ($entry['vfrom'] === $entry['vto'] ? $entry['vfrom'] : ($entry['vfrom'] . '-' . $entry['vto']));
            $title = $ref . ' (' . $entry['date'] . ')';

            remove_action('save_post', [__CLASS__, 'save_votd_meta'], 10);
            wp_update_post([
                'ID' => $post_id,
                'post_title' => $title,
                'post_name' => sanitize_title($title),
            ]);
            add_action('save_post', [__CLASS__, 'save_votd_meta'], 10, 2);
        }

        self::rebuild_votd_cache();
    }

    private static function rebuild_votd_cache() {
        $by_date = [];
        $all = [];

        $q = new WP_Query([
            'post_type' => 'thebible_votd',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ]);

        if (!empty($q->posts)) {
            foreach ($q->posts as $post) {
                $entry = self::normalize_votd_entry($post);
                if (!is_array($entry)) {
                    continue;
                }
                $entry['texts'] = self::extract_votd_texts_for_entry($entry);
                $all[] = $entry;
                if (!empty($entry['date'])) {
                    $by_date[$entry['date']] = $entry;
                }
            }
        }

        update_option('thebible_votd_by_date', $by_date, false);
        update_option('thebible_votd_all', $all, false);
    }

    private static function normalize_votd_entry($post) {
        if (!$post || $post->post_type !== 'thebible_votd') return null;

        $book = get_post_meta($post->ID, '_thebible_votd_book', true);
        $ch = (int) get_post_meta($post->ID, '_thebible_votd_chapter', true);
        $vfrom = (int) get_post_meta($post->ID, '_thebible_votd_vfrom', true);
        $vto = (int) get_post_meta($post->ID, '_thebible_votd_vto', true);
        $date = get_post_meta($post->ID, '_thebible_votd_date', true);

        if (!is_string($book) || $book === '' || $ch <= 0 || $vfrom <= 0) {
            return null;
        }
        if ($vto <= 0 || $vto < $vfrom) {
            $vto = $vfrom;
        }
        if (!is_string($date)) {
            $date = '';
        }

        return [
            'post_id' => (int) $post->ID,
            'book_slug' => $book,
            'chapter' => $ch,
            'vfrom' => $vfrom,
            'vto' => $vto,
            'date' => $date,
        ];
    }

    public static function get_votd_for_date($date = null) {
        if ($date === null) {
            $date = current_time('Y-m-d');
        }
        if (!is_string($date) || $date === '') {
            return null;
        }
        $map = get_option('thebible_votd_by_date', []);
        if (!is_array($map) || empty($map)) {
            return null;
        }
        if (!isset($map[$date]) || !is_array($map[$date])) {
            return null;
        }
        return $map[$date];
    }

    public static function get_votd_random($exclude_ids = []) {
        $all = get_option('thebible_votd_all', []);
        if (!is_array($all) || empty($all)) {
            return null;
        }
        $exclude_ids = array_map('intval', (array) $exclude_ids);
        $candidates = [];
        foreach ($all as $entry) {
            if (!is_array($entry) || empty($entry['post_id'])) {
                continue;
            }
            if (in_array((int) $entry['post_id'], $exclude_ids, true)) {
                continue;
            }
            $candidates[] = $entry;
        }
        if (empty($candidates)) {
            return null;
        }
        $idx = array_rand($candidates);
        return $candidates[$idx];
    }

    public static function get_votd_random_not_today() {
        $today = self::get_votd_for_date();
        $exclude = [];
        if (is_array($today) && !empty($today['post_id'])) {
            $exclude[] = (int) $today['post_id'];
        }
        return self::get_votd_random($exclude);
    }

    public static function votd_register_bulk_actions($bulk_actions) {
        if (!is_array($bulk_actions)) {
            return $bulk_actions;
        }
        $bulk_actions['thebible_votd_shuffle_selected'] = __('Shuffle selected', 'thebible');
        $bulk_actions['thebible_votd_shuffle_all'] = __('Shuffle all', 'thebible');
        $bulk_actions['thebible_votd_shuffle_all_not_today'] = __('Shuffle all (except today)', 'thebible');
        return $bulk_actions;
    }

    public static function votd_handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if (!is_array($post_ids)) {
            $post_ids = [];
        }

        if ($doaction === 'thebible_votd_shuffle_selected') {
            $ids = array_map('intval', $post_ids);
            if (!empty($ids)) {
                shuffle($ids);
                $dates = [];
                foreach ($ids as $id) {
                    $d = get_post_meta($id, '_thebible_votd_date', true);
                    if (is_string($d) && $d !== '') {
                        $dates[] = $d;
                    }
                }
                sort($dates);
                $i = 0;
                foreach ($ids as $id) {
                    if (!isset($dates[$i])) break;
                    update_post_meta($id, '_thebible_votd_date', $dates[$i]);
                    $i++;
                }
                self::rebuild_votd_cache();
            }
            return add_query_arg(['thebible_votd_shuffled' => 'selected'], $redirect_to);
        }

        if ($doaction === 'thebible_votd_shuffle_all' || $doaction === 'thebible_votd_shuffle_all_not_today') {
            $today = current_time('Y-m-d');
            $q = new WP_Query([
                'post_type' => 'thebible_votd',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'no_found_rows' => true,
                'fields' => 'ids',
            ]);
            $ids = is_array($q->posts) ? array_map('intval', $q->posts) : [];
            if ($doaction === 'thebible_votd_shuffle_all_not_today') {
                $ids = array_values(array_filter($ids, function($id) use ($today) {
                    $d = get_post_meta($id, '_thebible_votd_date', true);
                    return !(is_string($d) && $d === $today);
                }));
            }
            if (count($ids) > 1) {
                $dates = [];
                foreach ($ids as $id) {
                    $d = get_post_meta($id, '_thebible_votd_date', true);
                    if (is_string($d) && $d !== '') {
                        $dates[] = $d;
                    }
                }
                sort($dates);
                shuffle($ids);
                $i = 0;
                foreach ($ids as $id) {
                    if (!isset($dates[$i])) break;
                    update_post_meta($id, '_thebible_votd_date', $dates[$i]);
                    $i++;
                }
                self::rebuild_votd_cache();
            }
            $flag = ($doaction === 'thebible_votd_shuffle_all_not_today') ? 'all_not_today' : 'all';
            return add_query_arg(['thebible_votd_shuffled' => $flag], $redirect_to);
        }

        return $redirect_to;
    }

    private static function extract_votd_texts_for_entry($entry) {
        if (!is_array($entry)) return [];
        $out = [];
        $datasets = ['bible', 'bibel', 'latin'];
        foreach ($datasets as $dataset) {
            $short = TheBible_Plugin::resolve_book_for_dataset($entry['book_slug'], $dataset);
            if (!is_string($short) || $short === '') {
                continue;
            }
            $index_file = plugin_dir_path(__FILE__) . '../data/' . $dataset . '/html/index.csv';
            if (!file_exists($index_file)) {
                continue;
            }
            $filename = '';
            if (($fh = fopen($index_file, 'r')) !== false) {
                fgetcsv($fh);
                while (($row = fgetcsv($fh)) !== false) {
                    if (!is_array($row) || count($row) < 4) continue;
                    if ((string) $row[1] === (string) $short) {
                        $filename = (string) $row[3];
                        break;
                    }
                }
                fclose($fh);
            }
            if ($filename === '') {
                continue;
            }
            $html_path = plugin_dir_path(__FILE__) . '../data/' . $dataset . '/html/' . $filename;
            if (!file_exists($html_path)) {
                continue;
            }
            $html = (string) file_get_contents($html_path);
            if ($html === '') {
                continue;
            }
            $book_slug = TheBible_Plugin::slugify($short);
            $txt = TheBible_Plugin::extract_verse_text_from_html($html, $book_slug, (int) $entry['chapter'], (int) $entry['vfrom'], (int) $entry['vto']);
            if (is_string($txt) && $txt !== '') {
                $out[$dataset] = $txt;
            }
        }
        return $out;
    }
}
