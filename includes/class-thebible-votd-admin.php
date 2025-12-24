<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait TheBible_VOTD_Admin_Trait {
    /**
     * Enqueue interlinear styles for VOTD widget
     */
    public static function enqueue_votd_interlinear_styles_impl() {
        // Always enqueue the interlinear VOTD widget styles
        // They will only apply to widgets with the interlinear class
        $plugin_main_file = dirname( __DIR__ ) . '/thebible.php';
        $interlinear_votd_css_url = plugins_url( 'assets/thebible-votd-interlinear.css', $plugin_main_file );
        wp_enqueue_style( 'thebible-votd-interlinear-styles', $interlinear_votd_css_url, [], '0.1.0' );
    }

    public static function register_votd_cpt_impl() {
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

    public static function votd_columns_impl( $columns ) {
        if ( ! is_array( $columns ) ) {
            $columns = [];
        }
        // Insert VOTD date near the title column
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['votd_date'] = __( 'VOTD Date', 'thebible' );
                $new['votd_texts'] = __( 'EN/DE/LA', 'thebible' );
            }
        }
        if ( ! isset( $new['votd_date'] ) ) {
            $new['votd_date'] = __( 'VOTD Date', 'thebible' );
        }
        if ( ! isset( $new['votd_texts'] ) ) {
            $new['votd_texts'] = __( 'EN/DE/LA', 'thebible' );
        }
        return $new;
    }

    public static function votd_sortable_columns_impl( $columns ) {
        if ( ! is_array( $columns ) ) {
            $columns = [];
        }
        // Make the VOTD Date column sortable via meta_key _thebible_votd_date
        $columns['votd_date'] = 'votd_date';
        return $columns;
    }

    public static function render_votd_column_impl( $column, $post_id ) {
        if ( $column === 'votd_date' ) {
            $date = get_post_meta( $post_id, '_thebible_votd_date', true );
            if ( ! is_string( $date ) || $date === '' ) {
                echo '&mdash;';
                return;
            }
            $ts = strtotime( $date . ' 00:00:00' );
            if ( $ts ) {
                $human = date_i18n( 'Y-m-d (D)', $ts );
            } else {
                $human = $date;
            }
            echo esc_html( $human );
            return;
        }

        if ( $column === 'votd_texts' ) {
            $all = get_option( 'thebible_votd_all', [] );
            $en  = '';
            $de  = '';
            $la  = '';
            if ( is_array( $all ) ) {
                foreach ( $all as $entry ) {
                    if ( isset( $entry['post_id'] ) && (int) $entry['post_id'] === (int) $post_id && isset( $entry['texts'] ) && is_array( $entry['texts'] ) ) {
                        if ( isset( $entry['texts']['bible'] ) && is_string( $entry['texts']['bible'] ) ) {
                            $en = $entry['texts']['bible'];
                        }
                        if ( isset( $entry['texts']['bibel'] ) && is_string( $entry['texts']['bibel'] ) ) {
                            $de = $entry['texts']['bibel'];
                        }
                        if ( isset( $entry['texts']['latin'] ) && is_string( $entry['texts']['latin'] ) ) {
                            $la = $entry['texts']['latin'];
                        }
                        break;
                    }
                }
            }

            $out = '';
            if ( $en !== '' ) {
                $out .= '<div><strong>EN:</strong> ' . esc_html( wp_trim_words( $en, 10, '...' ) ) . '</div>';
            }
            if ( $de !== '' ) {
                $out .= '<div><strong>DE:</strong> ' . esc_html( wp_trim_words( $de, 10, '...' ) ) . '</div>';
            }
            if ( $la !== '' ) {
                $out .= '<div><strong>LA:</strong> ' . esc_html( wp_trim_words( $la, 10, '...' ) ) . '</div>';
            }
            if ( $out === '' ) {
                $out = '&mdash;';
            }
            echo $out; // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
    }

    public static function votd_date_filter_impl() {
        global $typenow;
        if ( $typenow !== 'thebible_votd' ) {
            return;
        }
        $map = get_option( 'thebible_votd_by_date', [] );
        if ( ! is_array( $map ) || empty( $map ) ) {
            return;
        }
        // Build distinct list of months (YYYY-MM) from known VOTD dates
        $months = [];
        foreach ( $map as $d => $_entry ) {
            if ( ! is_string( $d ) || $d === '' ) {
                continue;
            }
            if ( preg_match( '/^(\d{4}-\d{2})-\d{2}$/', $d, $m ) ) {
                $months[ $m[1] ] = true;
            }
        }
        if ( empty( $months ) ) {
            return;
        }
        $months   = array_keys( $months );
        sort( $months );
        $selected = isset( $_GET['thebible_votd_month'] ) ? (string) wp_unslash( $_GET['thebible_votd_month'] ) : '';

        // VOTD toolbar (single row): actions select + run + month filter
        $cleanup_url = wp_nonce_url( add_query_arg( [ 'thebible_votd_action' => 'cleanup' ] ), 'thebible_votd_cleanup' );
        $rebuild_cache_url = wp_nonce_url( add_query_arg( [ 'thebible_votd_action' => 'rebuild_cache' ] ), 'thebible_votd_rebuild_cache' );
        $shuffle_all_url = wp_nonce_url( add_query_arg( [ 'thebible_votd_action' => 'shuffle_all' ] ), 'thebible_votd_shuffle_all' );
        $shuffle_all_not_today_url = wp_nonce_url( add_query_arg( [ 'thebible_votd_action' => 'shuffle_all_not_today' ] ), 'thebible_votd_shuffle_all_not_today' );

        echo '<span class="thebible-votd-actions" style="margin-bottom:10px;display:inline-flex;align-items:center;gap:8px;vertical-align:middle;">';

        echo '<label for="thebible_votd_action_select" class="screen-reader-text">' . esc_html__( 'VOTD action', 'thebible' ) . '</label>';
        echo '<select id="thebible_votd_action_select">';
        echo '<option value="">' . esc_html__( 'Actions…', 'thebible' ) . '</option>';
        echo '<option value="' . esc_url( $rebuild_cache_url ) . '">' . esc_html__( 'Rebuild VOTD cache', 'thebible' ) . '</option>';
        echo '<option value="' . esc_url( $cleanup_url ) . '">' . esc_html__( 'Clean up schedule', 'thebible' ) . '</option>';
        echo '<option value="' . esc_url( $shuffle_all_url ) . '">' . esc_html__( 'Shuffle all', 'thebible' ) . '</option>';
        echo '<option value="' . esc_url( $shuffle_all_not_today_url ) . '">' . esc_html__( 'Shuffle (except today)', 'thebible' ) . '</option>';
        echo '</select>';

        echo '<a href="#" class="button" id="thebible_votd_action_run">' . esc_html__( 'Run', 'thebible' ) . '</a>';

        echo '<label for="thebible_votd_month" class="screen-reader-text">' . esc_html__( 'Filter by VOTD month', 'thebible' ) . '</label>';
        echo '<select name="thebible_votd_month" id="thebible_votd_month">';
        echo '<option value="">' . esc_html__( 'All VOTD months', 'thebible' ) . '</option>';
        foreach ( $months as $m ) {
            $label = $m;
            $ts    = strtotime( $m . '-01 00:00:00' );
            if ( $ts ) {
                $label = date_i18n( 'F Y', $ts );
            }
            echo '<option value="' . esc_attr( $m ) . '"' . selected( $selected, $m, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';

        echo '<script>(function(){var s=document.getElementById("thebible_votd_action_select");var b=document.getElementById("thebible_votd_action_run");if(!s||!b)return;function sync(){var v=s.value||"#";b.setAttribute("href",v);b.classList.toggle("button-primary", !!s.value);}\n'
            . 'sync();s.addEventListener("change",sync);b.addEventListener("click",function(e){if(!s.value){e.preventDefault();}});\n'
            . '})();</script>';

        echo '</span>';

        // Don't output the month filter again since it's now in the toolbar above
        return;
    }

    public static function apply_votd_date_filter_impl( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $post_type = $screen && isset( $screen->post_type ) ? $screen->post_type : ( isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '' );
        if ( $post_type !== 'thebible_votd' ) {
            return;
        }

        $month = isset( $_GET['thebible_votd_month'] ) ? sanitize_text_field( wp_unslash( $_GET['thebible_votd_month'] ) ) : '';

        $meta_query = (array) $query->get( 'meta_query' );

        // If a specific VOTD month is chosen, constrain to dates starting with YYYY-MM
        if ( $month !== '' ) {
            $meta_query[] = [
                'key'     => '_thebible_votd_date',
                'value'   => $month . '-',
                'compare' => 'LIKE',
            ];
        }

        // By default, hide VOTDs strictly before today on the index screen, unless a
        // different filter is explicitly requested.
        $show_all = isset( $_GET['thebible_votd_show_all'] ) && $_GET['thebible_votd_show_all'] === '1';
        if ( ! $show_all ) {
            $today = current_time( 'Y-m-d' );
            $meta_query[] = [
                'key'     => '_thebible_votd_date',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'CHAR',
            ];
        }

        if ( ! empty( $meta_query ) ) {
            $query->set( 'meta_query', $meta_query );
        }

        // Determine if the user explicitly requested a different sort
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
        $order   = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) : '';

        // Default sort: always by VOTD date ascending, unless another orderby is set
        if ( $orderby === '' || $orderby === 'votd_date' ) {
            $query->set( 'meta_key', '_thebible_votd_date' );
            $query->set( 'orderby', 'meta_value' );
            $query->set( 'order', ( $order === 'DESC' && $orderby === 'votd_date' ) ? 'DESC' : 'ASC' );
        }
    }

    public static function votd_register_bulk_actions_impl( $bulk_actions ) {
        if ( ! is_array( $bulk_actions ) ) {
            return $bulk_actions;
        }
        $bulk_actions['thebible_votd_shuffle_selected'] = __( 'Shuffle selected VOTDs', 'thebible' );
        $bulk_actions['thebible_votd_shuffle_all']      = __( 'Shuffle all VOTDs', 'thebible' );
        $bulk_actions['thebible_votd_shuffle_all_not_today'] = __( 'Shuffle all VOTDs (except today)', 'thebible' );
        return $bulk_actions;
    }

    public static function votd_handle_bulk_actions_impl( $redirect_to, $doaction, $post_ids ) {
        if ( ! is_array( $post_ids ) ) {
            $post_ids = [];
        }

        $today = current_time( 'Y-m-d' );

        if ( $doaction === 'thebible_votd_shuffle_selected' && ! empty( $post_ids ) ) {
            // Shuffle dates among the selected posts only
            $dates = [];
            foreach ( $post_ids as $pid ) {
                $d = get_post_meta( (int) $pid, '_thebible_votd_date', true );
                if ( ! is_string( $d ) || $d === '' ) {
                    continue;
                }
                $dates[] = $d;
            }
            if ( count( $dates ) > 1 ) {
                shuffle( $dates );
                $i = 0;
                foreach ( $post_ids as $pid ) {
                    $pid = (int) $pid;
                    if ( ! isset( $dates[ $i ] ) ) {
                        break;
                    }
                    $new_date = $dates[ $i++ ];
                    update_post_meta( $pid, '_thebible_votd_date', $new_date );

                    $post_obj = get_post( $pid );
                    if ( $post_obj && $post_obj->post_type === 'thebible_votd' ) {
                        $norm = self::normalize_votd_entry_impl( $post_obj );
                        if ( is_array( $norm ) ) {
                            $title = self::build_votd_post_title_impl( $norm['book_slug'], $norm['chapter'], $norm['vfrom'], $norm['vto'], $new_date );
                            if ( ! is_string( $title ) || $title === '' ) {
                                continue;
                            }

                            wp_update_post( [
                                'ID'         => $pid,
                                'post_title' => $title,
                                'post_name'  => sanitize_title( $title ),
                            ] );
                        }
                    }
                }
                self::rebuild_votd_cache_impl();
            }
            return add_query_arg( 'thebible_votd_shuffled', 'selected', $redirect_to );
        }

        if ( $doaction === 'thebible_votd_shuffle_all' || $doaction === 'thebible_votd_shuffle_all_not_today' ) {
            // Pull all VOTD posts
            $q = new WP_Query( [
                'post_type'      => 'thebible_votd',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
            ] );

            if ( ! empty( $q->posts ) ) {
                $ids   = [];
                $dates = [];
                foreach ( $q->posts as $post ) {
                    $pid = (int) $post->ID;
                    $d   = get_post_meta( $pid, '_thebible_votd_date', true );
                    if ( ! is_string( $d ) || $d === '' ) {
                        continue;
                    }
                    if ( $doaction === 'thebible_votd_shuffle_all_not_today' && $d === $today ) {
                        continue; // keep today's entry fixed
                    }
                    $ids[]   = $pid;
                    $dates[] = $d;
                }

                if ( count( $ids ) > 1 && count( $dates ) === count( $ids ) ) {
                    shuffle( $dates );
                    foreach ( $ids as $idx => $pid ) {
                        $pid      = (int) $pid;
                        $new_date = $dates[ $idx ];
                        update_post_meta( $pid, '_thebible_votd_date', $new_date );

                        $post_obj = get_post( $pid );
                        if ( $post_obj && $post_obj->post_type === 'thebible_votd' ) {
                            $norm = self::normalize_votd_entry_impl( $post_obj );
                            if ( is_array( $norm ) ) {
                                $title = self::build_votd_post_title_impl( $norm['book_slug'], $norm['chapter'], $norm['vfrom'], $norm['vto'], $new_date );
                                if ( ! is_string( $title ) || $title === '' ) {
                                    continue;
                                }

                                wp_update_post( [
                                    'ID'         => $pid,
                                    'post_title' => $title,
                                    'post_name'  => sanitize_title( $title ),
                                ] );
                            }
                        }
                    }
                    self::rebuild_votd_cache_impl();
                }
            }

            if ( $doaction === 'thebible_votd_shuffle_all_not_today' ) {
                return add_query_arg( 'thebible_votd_shuffled', 'all_not_today', $redirect_to );
            }
            return add_query_arg( 'thebible_votd_shuffled', 'all', $redirect_to );
        }

        return $redirect_to;
    }

    public static function handle_votd_condense_request_impl() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'edit-thebible_votd' ) {
            return;
        }
        if ( ! isset( $_GET['thebible_votd_action'] ) ) {
            return;
        }
        $action = (string) $_GET['thebible_votd_action'];
        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        // Handle toolbar actions
        if ( $action === 'cleanup' ) {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'thebible_votd_cleanup' ) ) {
                return;
            }

            // Ensure cache is current
            self::rebuild_votd_cache_impl();
            $all = get_option( 'thebible_votd_all', [] );
            if ( ! is_array( $all ) || empty( $all ) ) {
                $redirect = remove_query_arg( [ 'thebible_votd_action', '_wpnonce' ] );
                $redirect = add_query_arg( [ 'thebible_votd_condensed' => 1, 'moved' => 0, 'gaps' => 0 ], $redirect );
                wp_safe_redirect( $redirect );
                exit;
            }

            $today   = current_time( 'Y-m-d' );
            $entries = [];
            foreach ( $all as $entry ) {
                if ( ! is_array( $entry ) || ! isset( $entry['date'] ) || ! is_string( $entry['date'] ) || $entry['date'] === '' ) {
                    continue;
                }
                if ( $entry['date'] < $today ) {
                    continue; // keep past untouched
                }
                $entries[] = $entry;
            }

            // Randomize entries so that, when multiple VOTDs share a date, the specific
            // post that stays on a given day is effectively chosen at random.
            if ( count( $entries ) > 1 ) {
                shuffle( $entries );
            }

            if ( empty( $entries ) ) {
                $redirect = remove_query_arg( [ 'thebible_votd_action', '_wpnonce' ] );
                $redirect = add_query_arg( [ 'thebible_votd_condensed' => 1, 'moved' => 0, 'gaps' => 0 ], $redirect );
                wp_safe_redirect( $redirect );
                exit;
            }

            // Compute gaps between first and last future dates in the original schedule
            $first_date = $entries[0]['date'];
            $last_date  = $entries[ count( $entries ) - 1 ]['date'];
            $gaps       = 0;
            if ( is_string( $first_date ) && is_string( $last_date ) && $first_date !== '' && $last_date !== '' ) {
                $dt_first = new DateTime( max( $today, $first_date ) );
                $dt_last  = new DateTime( $last_date );
                if ( $dt_last >= $dt_first ) {
                    $span_days = (int) $dt_first->diff( $dt_last )->days + 1;
                    $gaps      = max( 0, $span_days - count( $entries ) );
                }
            }

            // Condense: reassign future entries to consecutive days from today forward
            $cursor = new DateTime( $today );
            $moved  = 0;
            foreach ( $entries as $entry ) {
                if ( ! isset( $entry['post_id'] ) ) {
                    continue;
                }
                $post_id = (int) $entry['post_id'];
                $new_date = $cursor->format( 'Y-m-d' );
                if ( ! isset( $entry['date'] ) || ! is_string( $entry['date'] ) || $entry['date'] !== $new_date ) {
                    // Update stored date meta
                    update_post_meta( $post_id, '_thebible_votd_date', $new_date );

                    // Also update the VOTD post title to reflect the new date, mirroring save_votd_meta()
                    $post_obj = get_post( $post_id );
                    if ( $post_obj && $post_obj->post_type === 'thebible_votd' ) {
                        $norm = self::normalize_votd_entry_impl( $post_obj );
                        if ( is_array( $norm ) ) {
                            $book_key = $norm['book_slug'];
                            $short    = self::resolve_book_for_dataset( $book_key, 'bible' );
                            if ( ! is_string( $short ) || $short === '' ) {
                                $label = ucwords( str_replace( '-', ' ', (string) $book_key ) );
                            } else {
                                $label = self::pretty_label( $short );
                            }
                            $ref   = $label . ' ' . $norm['chapter'] . ':' . ( $norm['vfrom'] === $norm['vto'] ? $norm['vfrom'] : ( $norm['vfrom'] . '-' . $norm['vto'] ) );
                            $title = $ref . ' (' . $new_date . ')';

                            wp_update_post( [
                                'ID'         => $post_id,
                                'post_title' => $title,
                                'post_name'  => sanitize_title( $title ),
                            ] );
                        }
                    }

                    $moved++;
                }
                $cursor->modify( '+1 day' );
            }

            self::rebuild_votd_cache_impl();

            $redirect = remove_query_arg( [ 'thebible_votd_action', '_wpnonce' ] );
            $redirect = add_query_arg( [ 'thebible_votd_condensed' => 1, 'moved' => $moved, 'gaps' => $gaps ], $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }

        // Toolbar shuffles that do not require selection
        if ( $action === 'shuffle_all' || $action === 'shuffle_all_not_today' ) {
            $nonce_action = ( $action === 'shuffle_all' ) ? 'thebible_votd_shuffle_all' : 'thebible_votd_shuffle_all_not_today';
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], $nonce_action ) ) {
                return;
            }

            $redirect = remove_query_arg( [ 'thebible_votd_action', '_wpnonce' ] );
            // Reuse the bulk handler logic with an empty post_ids list; it ignores
            // $post_ids for the "all" shuffles and runs a full query instead.
            $doaction  = ( $action === 'shuffle_all' ) ? 'thebible_votd_shuffle_all' : 'thebible_votd_shuffle_all_not_today';
            $redirect2 = self::votd_handle_bulk_actions_impl( $redirect, $doaction, [] );
            wp_safe_redirect( $redirect2 );
            exit;
        }

        // Rebuild cache action
        if ( $action === 'rebuild_cache' ) {
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'thebible_votd_rebuild_cache' ) ) {
                return;
            }

            self::rebuild_votd_cache_impl();

            $redirect = remove_query_arg( [ 'thebible_votd_action', '_wpnonce' ] );
            $redirect = add_query_arg( [ 'thebible_votd_cache_rebuilt' => 1 ], $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }
    }

    public static function votd_condense_notice_impl() {
        if ( ! is_admin() ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'edit-thebible_votd' ) {
            return;
        }
        $did_cleanup = isset( $_GET['thebible_votd_condensed'] );
        $shuffled    = isset( $_GET['thebible_votd_shuffled'] ) ? (string) $_GET['thebible_votd_shuffled'] : '';
        $cache_rebuilt = isset( $_GET['thebible_votd_cache_rebuilt'] );

        if ( ! $did_cleanup && $shuffled === '' && ! $cache_rebuilt ) {
            return;
        }

        if ( $did_cleanup ) {
            $moved = isset( $_GET['moved'] ) ? (int) $_GET['moved'] : 0;
            $gaps  = isset( $_GET['gaps'] ) ? (int) $_GET['gaps'] : 0;

            if ( $moved === 0 && $gaps === 0 ) {
                echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'VOTD schedule is already clean; no changes were made.', 'thebible' ) . '</p></div>';
            } elseif ( $gaps > 0 ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf( esc_html__( 'Cleaned up Verse-of-the-Day schedule: filled %1$d empty day slots and adjusted %2$d entries from today forward.', 'thebible' ), $gaps, $moved ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Cleaned up Verse-of-the-Day schedule: adjusted %1$d entries from today forward.', 'thebible' ), $moved ) . '</p></div>';
            }
        }

        if ( $shuffled !== '' ) {
            if ( $shuffled === 'selected' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shuffled selected VOTD entries.', 'thebible' ) . '</p></div>';
            } elseif ( $shuffled === 'all_not_today' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shuffled all VOTD entries except today.', 'thebible' ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shuffled all VOTD entries.', 'thebible' ) . '</p></div>';
            }
        }

        if ( $cache_rebuilt ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'VOTD cache rebuilt successfully. Latin verses are now included.', 'thebible' ) . '</p></div>';
        }
    }

    public static function add_votd_meta_box_impl() {
        add_meta_box(
            'thebible_votd_meta',
            __('Verse reference', 'thebible'),
            [__CLASS__, 'render_votd_meta_box'],
            'thebible_votd',
            'normal',
            'default'
        );
    }

    public static function render_votd_meta_box_impl($post) {
        wp_nonce_field('thebible_votd_meta_save', 'thebible_votd_meta_nonce');

        $book  = get_post_meta($post->ID, '_thebible_votd_book', true);
        $ch    = get_post_meta($post->ID, '_thebible_votd_chapter', true);
        $vfrom = get_post_meta($post->ID, '_thebible_votd_vfrom', true);
        $vto   = get_post_meta($post->ID, '_thebible_votd_vto', true);
        $date  = get_post_meta($post->ID, '_thebible_votd_date', true);

        if (!is_string($book))  $book = '';
        if (!is_string($date))  $date = '';
        $ch    = (string) (int) $ch;
        $vfrom = (string) (int) $vfrom;
        $vto   = (string) (int) $vto;

        $canonical_books = self::list_canonical_books();

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
            'chapter'   => (int) $ch,
            'vfrom'     => (int) $vfrom,
            'vto'       => (int) ( (int) $vto > 0 ? $vto : $vfrom ),
            'date'      => $date,
        ];
        $texts = self::extract_votd_texts_for_entry_impl( $entry_for_preview );
        if ( is_array( $texts ) && ( ! empty( $texts['bible'] ) || ! empty( $texts['bibel'] ) || ! empty( $texts['latin'] ) ) ) {
            echo '<h3>' . esc_html__( 'Preview (cached verse text)', 'thebible' ) . '</h3>';
            echo '<p class="description">' . esc_html__( 'These snippets are read from the verse cache for English (Douay), German (Menge), and Latin (Vulgate).', 'thebible' ) . '</p>';
            echo '<div style="padding:.5em 1em;border:1px solid #ccd0d4;background:#f6f7f7;max-width:48em;">';
            if ( ! empty( $texts['bible'] ) && is_string( $texts['bible'] ) ) {
                echo '<p><strong>EN:</strong> ' . esc_html( $texts['bible'] ) . '</p>';
            }
            if ( ! empty( $texts['bibel'] ) && is_string( $texts['bibel'] ) ) {
                echo '<p><strong>DE:</strong> ' . esc_html( $texts['bibel'] ) . '</p>';
            }
            if ( ! empty( $texts['latin'] ) && is_string( $texts['latin'] ) ) {
                echo '<p><strong>LA:</strong> ' . esc_html( $texts['latin'] ) . '</p>';
            }
            echo '</div>';
        }
    }

    public static function save_votd_meta_impl($post_id, $post) {
        if ($post->post_type !== 'thebible_votd') {
            return;
        }
        if (!isset($_POST['thebible_votd_meta_nonce']) || !wp_verify_nonce($_POST['thebible_votd_meta_nonce'], 'thebible_votd_meta_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $book  = isset($_POST['thebible_votd_book']) ? sanitize_text_field(wp_unslash($_POST['thebible_votd_book'])) : '';
        $ch    = isset($_POST['thebible_votd_chapter']) ? (int) $_POST['thebible_votd_chapter'] : 0;
        $vfrom = isset($_POST['thebible_votd_vfrom']) ? (int) $_POST['thebible_votd_vfrom'] : 0;
        $vto   = isset($_POST['thebible_votd_vto']) ? (int) $_POST['thebible_votd_vto'] : 0;
        $date  = isset($_POST['thebible_votd_date']) ? sanitize_text_field(wp_unslash($_POST['thebible_votd_date'])) : '';

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
            // Default to today if no date provided
            $date = current_time('Y-m-d');
            update_post_meta($post_id, '_thebible_votd_date', $date);
        }

        // Auto-generate post title from reference and date
        $entry = self::normalize_votd_entry_impl(get_post($post_id));
        if (is_array($entry)) {
            $title = self::build_votd_post_title_impl($entry['book_slug'], $entry['chapter'], $entry['vfrom'], $entry['vto'], $entry['date']);
            if (!is_string($title) || $title === '') {
                return;
            }

            // Avoid infinite recursion when updating the post inside save_post
            remove_action('save_post', [__CLASS__, 'save_votd_meta'], 10);
            wp_update_post([
                'ID'         => $post_id,
                'post_title' => $title,
                'post_name'  => sanitize_title($title),
            ]);
            add_action('save_post', [__CLASS__, 'save_votd_meta'], 10, 2);
        }

        self::rebuild_votd_cache_impl();
    }

    private static function rebuild_votd_cache_impl() {
        $by_date = [];
        $all = [];

        $q = new WP_Query([
            'post_type'      => 'thebible_votd',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);

        if (!empty($q->posts)) {
            foreach ($q->posts as $post) {
                $entry = self::normalize_votd_entry_impl($post);
                if (!is_array($entry)) {
                    continue;
                }
                $entry['texts'] = self::extract_votd_texts_for_entry_impl($entry);
                $all[] = $entry;
                if (!empty($entry['date'])) {
                    $by_date[$entry['date']] = $entry;
                }
            }
        }

        update_option('thebible_votd_by_date', $by_date, false);
        update_option('thebible_votd_all', $all, false);
    }

    private static function normalize_votd_entry_impl($post) {
        if (!$post || $post->post_type !== 'thebible_votd') return null;

        $book  = get_post_meta($post->ID, '_thebible_votd_book', true);
        $ch    = (int) get_post_meta($post->ID, '_thebible_votd_chapter', true);
        $vfrom = (int) get_post_meta($post->ID, '_thebible_votd_vfrom', true);
        $vto   = (int) get_post_meta($post->ID, '_thebible_votd_vto', true);
        $date  = get_post_meta($post->ID, '_thebible_votd_date', true);

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
            'post_id'   => (int) $post->ID,
            'book_slug' => $book,
            'chapter'   => $ch,
            'vfrom'     => $vfrom,
            'vto'       => $vto,
            'date'      => $date,
        ];
    }

    private static function build_votd_post_title_impl($book_key, $chapter, $vfrom, $vto, $date) {
        $book_key = is_string($book_key) ? $book_key : '';
        $chapter  = (int) $chapter;
        $vfrom    = (int) $vfrom;
        $vto      = (int) $vto;
        $date     = is_string($date) ? $date : '';

        if ($book_key === '' || $chapter <= 0 || $vfrom <= 0) {
            return '';
        }
        if ($vto <= 0 || $vto < $vfrom) {
            $vto = $vfrom;
        }

        $short = self::resolve_book_for_dataset($book_key, 'bible');
        if (!is_string($short) || $short === '') {
            $label = ucwords(str_replace('-', ' ', (string) $book_key));
        } else {
            $label = self::pretty_label($short);
        }

        $ref = $label . ' ' . $chapter . ':' . ($vfrom === $vto ? $vfrom : ($vfrom . '-' . $vto));
        if ($date !== '') {
            return $ref . ' (' . $date . ')';
        }
        return $ref;
    }

    public static function get_votd_for_date_impl($date = null) {
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

    public static function get_votd_random_impl($exclude_ids = []) {
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

    public static function get_votd_random_not_today_impl() {
        $today = self::get_votd_for_date_impl();
        $exclude = [];
        if (is_array($today) && !empty($today['post_id'])) {
            $exclude[] = (int) $today['post_id'];
        }
        return self::get_votd_random_impl($exclude);
    }

    private static function extract_votd_texts_for_entry_impl($entry) {
        if (!is_array($entry)) return [];
        $out = [];
        $plugin_root = plugin_dir_path( __DIR__ );
        $datasets = ['bible', 'bibel', 'latin'];
        foreach ($datasets as $dataset) {
            $short = self::resolve_book_for_dataset($entry['book_slug'], $dataset);
            if (!is_string($short) || $short === '') {
                continue;
            }
            $index_file = $plugin_root . 'data/' . $dataset . '/html/index.csv';
            if (!file_exists($index_file)) {
                continue;
            }

            $rows = file($index_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($rows)) {
                continue;
            }

            $html_file = '';
            foreach ($rows as $line) {
                $cols = str_getcsv($line);
                if (!is_array($cols) || count($cols) < 3) {
                    continue;
                }
                if (trim($cols[1]) === trim($short)) {
                    $html_file = trim($cols[2]);
                    break;
                }
            }
            if (!is_string($html_file) || $html_file === '') {
                continue;
            }

            $html_path = $plugin_root . 'data/' . $dataset . '/html/' . $html_file;
            if (!file_exists($html_path)) {
                continue;
            }

            $html = file_get_contents($html_path);
            if (!is_string($html) || $html === '') {
                continue;
            }

            $book_slug = self::slugify($short);
            $txt = self::extract_verse_text_from_html($html, $book_slug, (int) $entry['chapter'], (int) $entry['vfrom'], (int) $entry['vto']);
            if (is_string($txt) && $txt !== '') {
                $out[$dataset] = $txt;
            }
        }
        return $out;
    }
}
