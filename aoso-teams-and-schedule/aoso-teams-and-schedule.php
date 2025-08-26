<?php
/**
 * Plugin Name:  AOSO Teams and Schedule
 * Description:  Custom CPTs + ACF-powered builder for teams and matchday schedules with shortcode rendering.
 * Version:      0.3.0
 * Author:       AOSO
 * License:      GPL-2.0-or-later
 *
 * Requirements:
 * - Classic theme OK.
 * - Advanced Custom Fields PRO must be active. We register field groups with acf_add_local_field_group().
 *
 * Overview:
 * - CPT "Team" (slug: team) with taxonomy "Tier" (Rec, Flex, Comp) and ACF fields for colors and logo.
 * - CPT "Schedule" (slug: schedule) with ACF Repeater for all matchdays.
 * - Option for "No match this week" with a custom message.
 * - Single schedule pages and shortcode now render the entire schedule.
 * - Shortcode: [aoso_schedule slug="fall-2025-adult"]
 * - Clean, semantic HTML with sensible classes for styling.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AOSO_Teams_Schedule {

    /** Plugin constants (paths/urls). */
    private const VERSION   = '0.3.0';
    private const SLUG      = 'aoso-teams-and-schedule';

    public static function init() {
        static $inst = null;
        if ( null === $inst ) {
            $inst = new self();
        }
        return $inst;
    }

    private function __construct() {
        // Register content types.
        add_action( 'init', [ $this, 'register_team_cpt' ] );
        add_action( 'init', [ $this, 'register_schedule_cpt' ] );
        add_action( 'init', [ $this, 'register_tier_tax' ] );

        // Ensure default Tier terms exist.
        add_action( 'init', [ $this, 'maybe_seed_tier_terms' ] );

        // Flush rewrites on activation/deactivation when slugs change.
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        // Assets (your SCSS compiles to the CSS path shown below).
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // ACF field groups (requires ACF Pro).
        add_action( 'acf/init', [ $this, 'register_acf_groups' ] );

        // Prefill new Schedule builder with 3 fields × 2 times.
        add_filter( 'acf/load_value/name=aoso_matchdays', [ $this, 'prefill_matchdays' ], 10, 3 );

        // Shortcode for schedule rendering.
        add_shortcode( 'aoso_schedule', [ $this, 'shortcode_schedule' ] );

        // Replace single Schedule content with our rendered markup by default.
        add_filter( 'the_content', [ $this, 'maybe_inject_schedule_into_content' ] );
    }

    /*--------------------------------------------------------------------------
     * Activation/Deactivation
     *------------------------------------------------------------------------*/
    public function activate() {
        $this->register_team_cpt();
        $this->register_schedule_cpt();
        $this->register_tier_tax();
        $this->maybe_seed_tier_terms();
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    /*--------------------------------------------------------------------------
     * CPT: Team & Taxonomy: Tier
     * (These sections are unchanged)
     *------------------------------------------------------------------------*/
    public function register_team_cpt() {
        $labels = [
            'name'               => _x( 'Teams', 'post type general name', 'aoso' ),
            'singular_name'      => _x( 'Team', 'post type singular name', 'aoso' ),
            'menu_name'          => _x( 'Teams', 'admin menu', 'aoso' ),
            'name_admin_bar'     => _x( 'Team', 'add new on admin bar', 'aoso' ),
            'add_new'            => __( 'Add New', 'aoso' ),
            'add_new_item'       => __( 'Add New Team', 'aoso' ),
            'new_item'           => __( 'New Team', 'aoso' ),
            'edit_item'          => __( 'Edit Team', 'aoso' ),
            'view_item'          => __( 'View Team', 'aoso' ),
            'all_items'          => __( 'All Teams', 'aoso' ),
            'search_items'       => __( 'Search Teams', 'aoso' ),
            'parent_item_colon'  => __( 'Parent Teams:', 'aoso' ),
            'not_found'          => __( 'No teams found.', 'aoso' ),
            'not_found_in_trash' => __( 'No teams found in Trash.', 'aoso' ),
        ];
        register_post_type( 'aoso_team', [ 'labels' => $labels, 'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-groups', 'supports' => [ 'title', 'thumbnail' ], 'has_archive' => false, 'rewrite' => [ 'slug' => 'team', 'with_front' => false ], ] );
    }
    public function register_tier_tax() {
        $labels = [ 'name' => _x( 'Tiers', 'taxonomy general name', 'aoso' ), 'singular_name' => _x( 'Tier', 'taxonomy singular name', 'aoso' ), 'search_items' => __( 'Search Tiers', 'aoso' ), 'all_items' => __( 'All Tiers', 'aoso' ), 'edit_item' => __( 'Edit Tier', 'aoso' ), 'update_item' => __( 'Update Tier', 'aoso' ), 'add_new_item' => __( 'Add New Tier', 'aoso' ), 'new_item_name' => __( 'New Tier Name', 'aoso' ), 'menu_name' => __( 'Tiers', 'aoso' ), ];
        register_taxonomy( 'aoso_tier', [ 'aoso_team' ], [ 'labels' => $labels, 'hierarchical' => false, 'show_in_rest' => true, 'show_admin_column' => true, 'rewrite' => [ 'slug' => 'tier', 'with_front' => false ], ] );
    }
    public function maybe_seed_tier_terms() {
        $defaults = [ 'Rec', 'Flex', 'Comp' ];
        foreach ( $defaults as $term ) { if ( ! term_exists( $term, 'aoso_tier' ) ) { wp_insert_term( $term, 'aoso_tier' ); } }
    }

    /*--------------------------------------------------------------------------
     * CPT: Schedule
     * (Unchanged)
     *------------------------------------------------------------------------*/
    public function register_schedule_cpt() {
        $labels = [ 'name' => _x( 'Schedules', 'post type general name', 'aoso' ), 'singular_name' => _x( 'Schedule', 'post type singular name', 'aoso' ), 'menu_name' => _x( 'Schedules', 'admin menu', 'aoso' ), 'name_admin_bar' => _x( 'Schedule', 'add new on admin bar', 'aoso' ), 'add_new' => __( 'Add New', 'aoso' ), 'add_new_item' => __( 'Add New Schedule', 'aoso' ), 'new_item' => __( 'New Schedule', 'aoso' ), 'edit_item' => __( 'Edit Schedule', 'aoso' ), 'view_item' => __( 'View Schedule', 'aoso' ), 'all_items' => __( 'All Schedules', 'aoso' ), 'search_items' => __( 'Search Schedules', 'aoso' ), 'not_found' => __( 'No schedules found.', 'aoso' ), ];
        register_post_type( 'aoso_schedule', [ 'labels' => $labels, 'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-calendar-alt', 'supports' => [ 'title' ], 'has_archive' => false, 'rewrite' => [ 'slug' => 'schedule', 'with_front' => false ], ] );
    }

    /*--------------------------------------------------------------------------
     * Assets & ACF Fields
     *------------------------------------------------------------------------*/
    public function enqueue_assets() {
        $css_path = plugin_dir_url( __FILE__ ) . 'assets/css/aoso-teams-schedule.min.css';
        $css_file = plugin_dir_path( __FILE__ ) . 'assets/css/aoso-teams-schedule.min.css';
        $css_mtime = file_exists( $css_file ) ? filemtime( $css_file ) : self::VERSION;
        wp_register_style( 'aoso-teams-schedule', $css_path, [], $css_mtime, 'all' );
    }

    public function register_acf_groups() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) { return; }
        acf_add_local_field_group( [ 'key' => 'group_aoso_team_fields', 'title' => 'Team Details', 'fields' => [ [ 'key' => 'field_aoso_team_bg', 'label' => 'Background Color', 'name' => 'aoso_team_bg', 'type' => 'color_picker', 'return_format' => 'string', ], [ 'key' => 'field_aoso_team_text', 'label' => 'Text Color', 'name' => 'aoso_team_text', 'type' => 'color_picker', 'return_format' => 'string', ], [ 'key' => 'field_aoso_team_logo', 'label' => 'Logo (SVG/PNG)', 'name' => 'aoso_team_logo', 'type' => 'image', 'return_format' => 'array', 'library' => 'all', 'mime_types' => 'svg,png', 'preview_size' => 'medium', ], ], 'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'aoso_team', ], ], ], ] );
        acf_add_local_field_group( [ 'key' => 'group_aoso_schedule_fields', 'title' => 'Schedule Builder', 'fields' => [ [ 'key' => 'field_aoso_matchdays', 'label' => 'Matchdays', 'name' => 'aoso_matchdays', 'type' => 'repeater', 'layout' => 'row', 'button_label' => 'Add Matchday', 'sub_fields' => [ [ 'key' => 'field_aoso_no_match', 'label' => 'No match this week', 'name' => 'no_match', 'type' => 'true_false', 'ui' => 1, 'ui_on_text' => 'Yes', 'ui_off_text' => 'No', ], [ 'key' => 'field_aoso_no_match_message', 'label' => 'Custom Message', 'name' => 'no_match_message', 'type' => 'text', 'placeholder' => 'No matches scheduled this week.', 'conditional_logic' => [ [ [ 'field' => 'field_aoso_no_match', 'operator' => '==', 'value' => '1', ], ], ], ], [ 'key' => 'field_aoso_match_date', 'label' => 'Matchday Date', 'name' => 'match_date', 'type' => 'date_picker', 'display_format' => 'F j, Y', 'return_format' => 'Ymd', 'first_day' => 0, 'required' => 0, ], [ 'key' => 'field_aoso_md_fields', 'label' => 'Fields', 'name' => 'fields', 'type' => 'repeater', 'layout' => 'row', 'min' => 3, 'max' => 3, 'button_label' => 'Add Field', 'conditional_logic' => [ [ [ 'field' => 'field_aoso_no_match', 'operator' => '!=', 'value' => '1', ], ], ], 'sub_fields' => [ [ 'key' => 'field_aoso_md_field_name', 'label' => 'Field Name', 'name' => 'field_name', 'type' => 'text', ], [ 'key' => 'field_aoso_md_field_bg', 'label' => 'Field BG Color', 'name' => 'field_bg', 'type' => 'color_picker', 'return_format' => 'string', ], [ 'key' => 'field_aoso_md_times', 'label' => 'Times', 'name' => 'times', 'type' => 'repeater', 'layout' => 'table', 'button_label' => 'Add Time', 'sub_fields' => [ [ 'key' => 'field_aoso_md_time_label', 'label' => 'Time', 'name' => 'time_label', 'type' => 'text', 'placeholder' => '9:00', ], [ 'key' => 'field_aoso_md_home_team', 'label' => 'Home Team', 'name' => 'home_team', 'type' => 'post_object', 'post_type' => [ 'aoso_team' ], 'return_format' => 'id', 'ui' => 1, 'allow_null' => 0, ], [ 'key' => 'field_aoso_md_away_team', 'label' => 'Away Team', 'name' => 'away_team', 'type' => 'post_object', 'post_type' => [ 'aoso_team' ], 'return_format' => 'id', 'ui' => 1, 'allow_null' => 0, ], ], ], ], ], ], ], ], 'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'aoso_schedule', ], ], ], ] );
    }
    public function prefill_matchdays( $value, $post_id, $field ) {
        if ( ! empty( $value ) ) { return $value; }
        $field_defaults = [ [ 'name' => 'Field 1', 'bg' => '#f4cccc' ], [ 'name' => 'Field 2', 'bg' => '#d9d2e9' ], [ 'name' => 'Field 3', 'bg' => '#cfe1f3' ], ];
        $fields = [];
        foreach ( $field_defaults as $def ) { $fields[] = [ 'field_name' => $def['name'], 'field_bg' => $def['bg'], 'times' => [ [ 'time_label' => '9:00', 'home_team' => '', 'away_team' => '' ], [ 'time_label' => '10:30', 'home_team' => '', 'away_team' => '' ], ], ]; }
        return [ [ 'no_match' => 0, 'no_match_message' => '', 'match_date' => '', 'fields' => $fields, ], ];
    }
    private function human_date_from_ymd( $ymd ) {
        if ( ! $ymd ) { return ''; }
        return date_i18n( 'F j, Y', strtotime( $ymd ) );
    }

    /*--------------------------------------------------------------------------
     * Rendering
     *------------------------------------------------------------------------*/

    /**
     * Shortcode handler: [aoso_schedule slug="fall-2025-adult" hide_old="false"]
     */
    public function shortcode_schedule( $atts ) {
        $atts = shortcode_atts( [
            'slug'     => '',
            'hide_old' => 'false',
        ], $atts, 'aoso_schedule' );

        $post = $this->resolve_schedule_post( $atts['slug'] );
        if ( ! $post ) {
            return '';
        }

        wp_enqueue_style( 'aoso-teams-schedule' );

        $all_matchdays = get_field( 'aoso_matchdays', $post->ID );
        if ( empty( $all_matchdays ) ) {
            return '';
        }

        $hide_old = filter_var( $atts['hide_old'], FILTER_VALIDATE_BOOLEAN );

        return $this->render_full_schedule_html( $post->ID, $all_matchdays, $hide_old );
    }

    /**
     * If we’re on a single aoso_schedule view, replace content with the rendered schedule.
     */
    public function maybe_inject_schedule_into_content( $content ) {
        if ( is_singular( 'aoso_schedule' ) && in_the_loop() && is_main_query() ) {
            wp_enqueue_style( 'aoso-teams-schedule' );
            $all_matchdays = get_field( 'aoso_matchdays', get_the_ID() );
            if ( ! empty( $all_matchdays ) ) {
                return $this->render_full_schedule_html( get_the_ID(), $all_matchdays );
            }
        }
        return $content;
    }

    /**
     * Resolve a schedule post either by slug or by newest publish date.
     */
    private function resolve_schedule_post( $slug ) {
        if ( $slug ) {
            $by_slug = get_page_by_path( sanitize_title( $slug ), OBJECT, 'aoso_schedule' );
            if ( $by_slug instanceof WP_Post ) {
                return $by_slug;
            }
        }

        $q = new WP_Query( [ 'post_type' => 'aoso_schedule', 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC', ] );
        return $q->have_posts() ? $q->posts[0] : null;
    }

    /**
     * Build the wrapper and loop through all matchdays.
     *
     * @param int   $schedule_id
     * @param array $all_matchdays
     * @param bool  $hide_old Whether to hide past matchdays and append link.
     */
    private function render_full_schedule_html( $schedule_id, $all_matchdays, $hide_old = false ) {
        $today = current_time( 'Ymd' );
        $shown_matchdays = [];
        $had_hidden = false;

        foreach ( $all_matchdays as $matchday ) {
            $date = $matchday['match_date'] ?? '';
            if ( $hide_old && $date && $date < $today ) {
                $had_hidden = true;
                continue;
            }
            $shown_matchdays[] = $matchday;
        }

        if ( empty( $shown_matchdays ) ) {
            return ''; // nothing to render
        }

        ob_start();
        ?>
        <section class="aoso-schedule" aria-labelledby="aoso-schedule-title-<?php echo esc_attr( $schedule_id ); ?>">
            <header class="aoso-schedule__header">
                <h2 id="aoso-schedule-title-<?php echo esc_attr( $schedule_id ); ?>" class="aoso-schedule__title">
                    <?php echo esc_html( get_the_title( $schedule_id ) ); ?>
                </h2>
            </header>

            <div class="aoso-schedule__matchdays-wrapper">
                <?php foreach ( $shown_matchdays as $index => $matchday ) : ?>
                    <?php echo $this->render_single_matchday_block( $matchday, $index + 1 ); ?>
                <?php endforeach; ?>
            </div>

            <?php if ( $hide_old && $had_hidden ) : ?>
                <div class="aoso-schedule__view-full">
                    <a href="<?php echo esc_url( get_permalink( $schedule_id ) ); ?>" class="in-page-button">View Past Matches</a>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the HTML for a single matchday block (a single row from the repeater).
     */
    private function render_single_matchday_block( $matchday, $week_number ) {
        $date_raw = $matchday['match_date'] ?? '';
        $date_out = $this->human_date_from_ymd( $date_raw );
        ob_start();
        ?>
        <div class="aoso-matchday" id="aoso-week-<?php echo esc_attr( $week_number ); ?>">
            <header class="aoso-matchday__header">
                <?php if ( $date_out ) :
                    $date_text = esc_html( $date_out );
                endif; ?>
                <h3 class="aoso-matchday__title"><span class="aoso-matchday__title-date"><?php echo $date_text; ?></span> <span class="aoso-matchday__title-label">(Matchday #<?php echo esc_html( $week_number ); ?>)</span></h3>
            </header>

            <?php
            if ( ! empty( $matchday['no_match'] ) ) :
                // Render the "No Match" notice.
                $message = ! empty( $matchday['no_match_message'] )
                    ? $matchday['no_match_message']
                    : 'No matches scheduled for this week.';
                ?>
                <div class="aoso-schedule__notice" role="alert">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
                <?php
            else :
                // Render the schedule grid for the week.
                $fields = $matchday['fields'] ?? [];
                if ( ! empty( $fields ) && is_array( $fields ) ) :
                ?>
                <div class="aoso-schedule__grid" role="table" aria-label="Schedule for Week <?php echo esc_attr( $week_number ); ?>">
                    <div class="aoso-schedule__grid-head" role="rowgroup">
                        <div class="aoso-schedule__cell aoso-schedule__cell--head aoso-schedule__cell--time" role="columnheader">Time</div>
                        <?php foreach ( $fields as $i => $field_block ) : ?>
                            <?php
                            $field_name = isset( $field_block['field_name'] ) && $field_block['field_name'] !== '' ? $field_block['field_name'] : 'Field ' . ( $i + 1 );
                            $field_bg   = isset( $field_block['field_bg'] ) ? $field_block['field_bg'] : '';
                            ?>
                            <div class="aoso-schedule__field-head" role="columnheader" style="<?php echo $field_bg ? 'background-color:' . esc_attr( $field_bg ) . ';' : ''; ?>">
                                <div class="aoso-schedule__field-head-inner">
                                    <span class="aoso-schedule__field-name"><?php echo esc_html( $field_name ); ?></span>
                                    <span class="aoso-schedule__hoa-wrap" aria-hidden="true">
                                        <span class="aoso-schedule__hoa aoso-schedule__hoa--home">Home</span>
                                        <span class="aoso-schedule__hoa aoso-schedule__hoa--vs">vs</span>
                                        <span class="aoso-schedule__hoa aoso-schedule__hoa--away">Away</span>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="aoso-schedule__grid-body" role="rowgroup">
                        <?php
                        // Normalize time labels across fields.
                        $time_labels = [];
                        foreach ( $fields as $field_block ) {
                            if ( ! empty( $field_block['times'] ) && is_array( $field_block['times'] ) ) {
                                foreach ( $field_block['times'] as $time_row ) {
                                    $t = trim( (string) ( $time_row['time_label'] ?? '' ) );
                                    if ( $t !== '' ) { $time_labels[ $t ] = true; }
                                }
                            }
                        }
                        $time_labels = array_keys( $time_labels );

                        foreach ( $time_labels as $tlabel ) : ?>
                            <div class="aoso-schedule__row" role="row">
                                <div class="aoso-schedule__cell aoso-schedule__cell--time-key" role="rowheader"><?php echo esc_html( $tlabel ); ?></div>
                                <?php foreach ( $fields as $field_block ) : ?>
                                    <?php
                                    $match = null;
                                    if ( ! empty( $field_block['times'] ) && is_array( $field_block['times'] ) ) {
                                        foreach ( $field_block['times'] as $time_row ) {
                                            if ( trim( (string) ( $time_row['time_label'] ?? '' ) ) === $tlabel ) {
                                                $match = $time_row;
                                                break;
                                            }
                                        }
                                    }
                                    $home_id = $match['home_team'] ?? 0;
                                    $away_id = $match['away_team'] ?? 0;
                                    echo $this->render_match_cell( $home_id, $away_id );
                                    ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
                endif; // end if !empty($fields)
            endif; // end if no_match
            ?>
            <hr/>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render one cell containing the Home vs Away teams for a given field/time.
     */
    private function render_match_cell( $home_id, $away_id ) {
        $home = $this->format_team_bits( $home_id );
        $away = $this->format_team_bits( $away_id );
        ob_start();
        ?>
        <div class="aoso-schedule__cell aoso-schedule__cell--match" role="cell">
            <div class="aoso-match">
                <div class="aoso-team aoso-team--home" style="<?php echo esc_attr( $home['style'] ); ?>">
                    <?php echo $home['html']; ?>
                </div>
                <div class="aoso-vs" aria-hidden="true">vs</div>
                <div class="aoso-team aoso-team--away" style="<?php echo esc_attr( $away['style'] ); ?>">
                    <?php echo $away['html']; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Prepare team small badge (logo + name) and inline colors if available.
     */
    private function format_team_bits( $team_id ) {
        if ( ! $team_id ) { return [ 'html' => '<span class="aoso-team__name aoso-team__name--tbd">—</span>', 'style' => '', ]; }
        $title = get_the_title( $team_id );
        $bg    = (string) get_field( 'aoso_team_bg', $team_id );
        $text  = (string) get_field( 'aoso_team_text', $team_id );
        $logo  = get_field( 'aoso_team_logo', $team_id );
        $style = [];
        if ( $bg )   { $style[] = 'background-color:' . sanitize_hex_color( $bg ); }
        if ( $text ) { $style[] = 'color:' . sanitize_hex_color( $text ); }
        $logo_html = '';
        if ( is_array( $logo ) && ! empty( $logo['url'] ) ) {
            $logo_html = sprintf( '<img class="aoso-team__logo" src="%s" alt="%s" loading="lazy" decoding="async" />', esc_url( $logo['url'] ), esc_attr( $title ) );
        }
        $name_html = '<span class="aoso-team__name">' . esc_html( $title ) . '</span>';
        return [ 'html' => $logo_html . $name_html, 'style' => implode( ';', $style ), ];
    }
}

AOSO_Teams_Schedule::init();
