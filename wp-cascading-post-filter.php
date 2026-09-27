<?php
/**
 * Plugin Name:       Cascading Post Filter
 * Plugin URI:        https://github.com/Halleluyahsalako/wp-cascading-post-filter
 * Description:       An AJAX post filter with cascading parent and child taxonomy selects, keyword search and pagination. Owns its own query, so it does not break when a page builder updates.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Salako Halleluyah
 * Author URI:        https://halleluyahsalako.github.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cascading-post-filter
 * Domain Path:       /languages
 *
 * @package Cascading_Post_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CPFILTER_VERSION', '1.0.0' );
define( 'CPFILTER_URL', plugin_dir_url( __FILE__ ) );

/**
 * A self contained archive filter.
 *
 * The point of this plugin is that it owns its own query. The common
 * alternative is to stack a filter plugin on top of a page builder's post
 * widget, which means neither one fully owns the result set, and the whole
 * thing breaks when the builder changes its internals or post IDs shift.
 * Here the shortcode renders the controls, the AJAX handler runs WP_Query,
 * and the JavaScript renders the cards. Nothing else is involved.
 */
class Cascading_Post_Filter {

	public function __construct() {
		add_shortcode( 'post_filter', array( $this, 'render' ) );

		add_action( 'wp_ajax_cpfilter_query', array( $this, 'ajax_query' ) );
		add_action( 'wp_ajax_nopriv_cpfilter_query', array( $this, 'ajax_query' ) );
		add_action( 'wp_ajax_cpfilter_children', array( $this, 'ajax_children' ) );
		add_action( 'wp_ajax_nopriv_cpfilter_children', array( $this, 'ajax_children' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		wp_register_style( 'cpfilter', CPFILTER_URL . 'assets/css/post-filter.css', array(), CPFILTER_VERSION );
		wp_register_script( 'cpfilter', CPFILTER_URL . 'assets/js/post-filter.js', array(), CPFILTER_VERSION, true );
	}

	/**
	 * Shortcode attributes are deliberately generous. The original version of
	 * this only ever filtered the blog by category, which meant a second site
	 * needed a second copy of the plugin.
	 */
	private function parse_atts( $atts ) {
		return shortcode_atts(
			array(
				'post_type' => 'post',
				'taxonomy'  => 'category',
				'per_page'  => 12,
				'columns'   => 3,
				'search'    => 'yes',
				'years'     => 'yes',
			),
			$atts,
			'post_filter'
		);
	}

	public function render( $atts ) {
		$atts = $this->parse_atts( $atts );

		$post_type = sanitize_key( $atts['post_type'] );
		$taxonomy  = sanitize_key( $atts['taxonomy'] );

		if ( ! post_type_exists( $post_type ) || ! taxonomy_exists( $taxonomy ) ) {
			return current_user_can( 'edit_posts' )
				? '<p>' . esc_html__( 'Post filter: unknown post type or taxonomy.', 'cascading-post-filter' ) . '</p>'
				: '';
		}

		wp_enqueue_style( 'cpfilter' );
		wp_enqueue_script( 'cpfilter' );
		wp_localize_script(
			'cpfilter',
			'cpfilterVars',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cpfilter' ),
				'i18n'    => array(
					'noResults'  => __( 'Nothing matches those filters.', 'cascading-post-filter' ),
					'error'      => __( 'Could not load posts. Please try again.', 'cascading-post-filter' ),
					'showing'    => __( 'Showing %1$s to %2$s of %3$s', 'cascading-post-filter' ),
					'readMore'   => __( 'Read more', 'cascading-post-filter' ),
					'allChildren' => __( 'All', 'cascading-post-filter' ),
				),
			)
		);

		$parents = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => 0,
				'hide_empty' => true,
			)
		);

		$tax_object = get_taxonomy( $taxonomy );
		$tax_label  = $tax_object ? $tax_object->labels->singular_name : __( 'Category', 'cascading-post-filter' );

		ob_start();
		?>
		<div class="cpfilter"
			data-post-type="<?php echo esc_attr( $post_type ); ?>"
			data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
			data-per-page="<?php echo esc_attr( absint( $atts['per_page'] ) ); ?>"
			data-columns="<?php echo esc_attr( absint( $atts['columns'] ) ); ?>">

			<form class="cpfilter__controls" role="search" onsubmit="return false;">
				<?php if ( 'yes' === $atts['search'] ) : ?>
					<div class="cpfilter__search">
						<label class="screen-reader-text" for="cpfilter-search"><?php esc_html_e( 'Search', 'cascading-post-filter' ); ?></label>
						<input type="search" id="cpfilter-search" class="cpfilter__search-input"
							placeholder="<?php esc_attr_e( 'Search articles, topics, keywords', 'cascading-post-filter' ); ?>">
					</div>
				<?php endif; ?>

				<div class="cpfilter__row">
					<?php if ( 'yes' === $atts['years'] ) : ?>
						<div class="cpfilter__field">
							<label for="cpfilter-year"><?php esc_html_e( 'Year', 'cascading-post-filter' ); ?></label>
							<select id="cpfilter-year" data-filter="year">
								<option value=""><?php esc_html_e( 'All years', 'cascading-post-filter' ); ?></option>
								<?php foreach ( $this->years_with_posts( $post_type ) as $year ) : ?>
									<option value="<?php echo esc_attr( $year ); ?>"><?php echo esc_html( $year ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>

					<?php if ( ! is_wp_error( $parents ) && $parents ) : ?>
						<div class="cpfilter__field">
							<label for="cpfilter-parent"><?php echo esc_html( $tax_label ); ?></label>
							<select id="cpfilter-parent" data-filter="parent">
								<option value="">
									<?php
									/* translators: %s: taxonomy name, for example Category */
									printf( esc_html__( 'All %s', 'cascading-post-filter' ), esc_html( strtolower( $tax_label ) ) );
									?>
								</option>
								<?php foreach ( $parents as $term ) : ?>
									<option value="<?php echo esc_attr( $term->term_id ); ?>">
										<?php echo esc_html( $term->name ); ?> (<?php echo esc_html( $term->count ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="cpfilter__field cpfilter__field--child" hidden>
							<label for="cpfilter-child"><?php esc_html_e( 'Subcategory', 'cascading-post-filter' ); ?></label>
							<select id="cpfilter-child" data-filter="child">
								<option value=""><?php esc_html_e( 'All', 'cascading-post-filter' ); ?></option>
							</select>
						</div>
					<?php endif; ?>

					<button type="button" class="cpfilter__clear"><?php esc_html_e( 'Clear filters', 'cascading-post-filter' ); ?></button>
				</div>
			</form>

			<p class="cpfilter__meta" aria-live="polite"></p>
			<div class="cpfilter__grid" aria-busy="false"></div>
			<nav class="cpfilter__pagination" aria-label="<?php esc_attr_e( 'Pages', 'cascading-post-filter' ); ?>"></nav>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Years that actually have posts, newest first.
	 *
	 * The original hardcoded a range ending at 2020, which silently stopped
	 * being correct the moment the site had older or newer content.
	 */
	private function years_with_posts( $post_type ) {
		$key    = 'cpfilter_years_' . $post_type;
		$years  = get_transient( $key );

		if ( false === $years ) {
			global $wpdb;

			$years = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT YEAR(post_date)
					 FROM {$wpdb->posts}
					 WHERE post_type = %s AND post_status = 'publish'
					 ORDER BY post_date DESC",
					$post_type
				)
			);

			set_transient( $key, $years, DAY_IN_SECONDS );
		}

		return (array) $years;
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Child terms for the cascading select.
	 */
	public function ajax_children() {
		check_ajax_referer( 'cpfilter', 'nonce' );

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : 'category';
		$parent   = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;

		if ( ! taxonomy_exists( $taxonomy ) || ! $parent ) {
			wp_send_json_success( array( 'terms' => array() ) );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'parent'     => $parent,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) ) {
			wp_send_json_success( array( 'terms' => array() ) );
		}

		$out = array();

		foreach ( $terms as $term ) {
			$out[] = array(
				'id'    => $term->term_id,
				'name'  => $term->name,
				'count' => $term->count,
			);
		}

		wp_send_json_success( array( 'terms' => $out ) );
	}

	public function ajax_query() {
		check_ajax_referer( 'cpfilter', 'nonce' );

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$taxonomy  = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : 'category';

		if ( ! post_type_exists( $post_type ) || ! taxonomy_exists( $taxonomy ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown post type or taxonomy.', 'cascading-post-filter' ) ) );
		}

		$search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$year     = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : 0;
		$parent   = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;
		$child    = isset( $_POST['child'] ) ? absint( $_POST['child'] ) : 0;
		$paged    = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? min( 50, max( 1, absint( $_POST['per_page'] ) ) ) : 12;

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
		);

		if ( $search ) {
			$args['s'] = $search;
		}

		if ( $year ) {
			$args['date_query'] = array( array( 'year' => $year ) );
		}

		// The child selection wins when present, since it is the narrower of
		// the two and the parent is implied by it.
		$term_id = $child ?: $parent;

		if ( $term_id ) {
			$args['tax_query'] = array(
				array(
					'taxonomy'         => $taxonomy,
					'field'            => 'term_id',
					'terms'            => $term_id,
					'include_children' => ! $child,
				),
			);
		}

		$query = new WP_Query( apply_filters( 'cpfilter_query_args', $args ) );
		$posts = array();

		foreach ( $query->posts as $post ) {
			$terms = get_the_terms( $post, $taxonomy );

			$posts[] = array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post ),
				'permalink' => get_permalink( $post ),
				'excerpt'   => wp_trim_words( get_the_excerpt( $post ), 24 ),
				'date'      => get_the_date( '', $post ),
				'thumbnail' => get_the_post_thumbnail_url( $post, 'medium_large' ) ?: '',
				'terms'     => ( is_array( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : array(),
			);
		}

		wp_send_json_success(
			array(
				'posts'    => $posts,
				'found'    => (int) $query->found_posts,
				'maxPages' => (int) $query->max_num_pages,
				'page'     => $paged,
				'perPage'  => $per_page,
			)
		);
	}
}

new Cascading_Post_Filter();

// The year dropdown is cached for a day, so clear it when content changes.
add_action( 'save_post', function ( $post_id, $post ) {
	if ( $post instanceof WP_Post ) {
		delete_transient( 'cpfilter_years_' . $post->post_type );
	}
}, 10, 2 );
