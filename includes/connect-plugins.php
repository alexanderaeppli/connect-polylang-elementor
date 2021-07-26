<?php
namespace ConnectPolylangElementor;

defined( 'ABSPATH' ) || exit;


class ConnectPlugins {

	use \ConnectPolylangElementor\Util\Singleton;

	/**
	 * Current template ID.
	 *
	 * @var int|null
	 */
	private $template_id = null;

	/**
	 * __construct
	 *
	 * @return void
	 */
	private function __construct() {

		// Auto add post types for translation.
		add_filter( 'pll_get_post_types', array( $this, 'add_polylang_post_types' ), 10, 2 );

		// Front template loading.
		add_filter( 'elementor/theme/get_location_templates/template_id', array( $this, 'template_id_translation' ) );
		add_filter( 'elementor/theme/get_location_templates/condition_sub_id', array( $this, 'condition_sub_id_translation' ), 10, 2 );

		// Fix home_url() for site-url Dynamic Tag and Search Form widget.
		add_filter( 'pll_home_url_white_list', array( $this, 'elementor_home_url_white_list' ) );
		add_filter( 'home_url', array( $this, 'home_url_language_dir_slash' ), 11, 2 );

		if ( is_admin() ) {

			// All langs for template conditions & global widgets.
			add_action( 'parse_query', array( $this, 'query_all_languages' ), 1 );

			// Empty template conditions on translations.
			add_filter( 'get_post_metadata', array( $this, 'elementor_conditions_empty_on_translations' ), 10, 3 );
			add_filter( 'pre_update_option_elementor_pro_theme_builder_conditions', array( $this, 'theme_builder_conditions_remove_empty' ) );

			// Update template conditions on language terms change.
			add_action( 'set_object_terms', array( $this, 'update_conditions_on_term_change' ), 10, 4 );

			// Global widgets hide language column.
			add_action( 'manage_elementor_library_posts_custom_column', array( $this, 'hide_language_column_pre' ), 9, 2 );
			add_action( 'manage_elementor_library_posts_custom_column', array( $this, 'hide_language_column_pos' ), 11, 2 );

			// Don't add "_elementor_css" meta.
			add_filter( 'update_post_metadata', array( $this, 'prevent_elementor_css_meta' ), 10, 3 );

		}

	}

	/**
	 * Enable Elementor-specific post types automatically for Polylang translation
	 *
	 * @link   https://polylang.pro/doc/filter-reference/
	 *
	 * @since  2.0.0
	 *
	 * @param array $types The list of post type names for which Polylang manages language and translations
	 * @param bool  $is_settings  True when displaying the list in Polylang settings
	 * @return array The list of post type names for which Polylang manages language and translations
	 */
	function add_polylang_post_types( $types, $is_settings ) {

		$relevant_types = apply_filters(
			'cpel/filter/polylang/post_types',
			array(
				'elementor_library',   // Elementor
				'e-landing-page',      // Elementor Landing pages
				'oceanwp_library',     // OceanWP Library
				'astra-advanced-hook', // Astra Custom Layouts (Astra Pro)
				'gp_elements',         // GeneratePress Elements (GP Premium)
				'jet-theme-core',      // JetThemeCore (Kava Pro/ CrocoBlock)
				'jet-engine',          // JetEngine Listing Item (CrocoBlock)
				'customify_hook',      // Customify (Customify Pro)
				'wpbf_hooks',          // Page Builder Framework Sections (WPBF Premium)
				'ae_global_templates', // AnyWhere Elementor plugin
			)
		);

		return array_merge( $types, array_combine( $relevant_types, $relevant_types ) );

	}

	/**
	 * Query all languages if conditions meets
	 *
	 *   Note: Needs to be priority 1, since Polylang uses the action parse_query
	 *         which is fired before 'pre_get_posts'.
	 *
	 * @link  https://github.com/polylang/polylang/issues/152#issuecomment-320602328
	 * @link  https://github.com/pojome/elementor/issues/4839
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Query $query
	 */
	function query_all_languages( $query ) {

		$global_widget_meta_query = array(
			'key'   => '_elementor_template_type',
			'value' => 'widget',
		);

		$is_elementor_conditions = isset( $query->query_vars['meta_key'] )
			&& '_elementor_conditions' === $query->query_vars['meta_key'];

		$is_global_widget = isset( $query->query_vars['post_type'], $query->query_vars['meta_query'] )
			&& 'elementor_library' === $query->query_vars['post_type']
			&& in_array( $global_widget_meta_query, $query->query_vars['meta_query'] );

		if ( $is_elementor_conditions || $is_global_widget ) {
			$query->set( 'lang', '' );
		}

	}

	/**
	 * Return empty conditions on secondary translations
	 *
	 * @since  2.0.0
	 *
	 * @param  mixed  $null
	 * @param  int    $post_id
	 * @param  string $meta_key
	 * @return mixed null or empty array
	 */
	function elementor_conditions_empty_on_translations( $null, $post_id, $meta_key ) {

		if ( '_elementor_conditions' === $meta_key ) {

			return cpel_is_translation( $post_id ) ? array( array() ) : $null;

		}

		return $null;

	}

	/**
	 * Clear empty conditions before save 'elementor_pro_theme_builder_conditions' option
	 *
	 * @since  2.0.0
	 *
	 * @param  array $value array of theme builder conditions
	 * @return array  filtered array
	 */
	function theme_builder_conditions_remove_empty( $value ) {

		foreach ( $value as $location => $items ) {
			$value[ $location ] = array_filter( $items );
		}

		return array_filter( $value );

	}

	/**
	 * Change Elementor template with their translation for the current lanaguage (if exists).
	 *
	 * @link   https://github.com/pojome/elementor/issues/4839
	 *
	 * @since  2.0.0
	 *
	 * @uses   pll_get_post()
	 *
	 * @param  int $post_id ID of the current post
	 * @return string Based translation, the translation ID, or the original Post ID
	 */
	function template_id_translation( $post_id ) {

		$post_id           = pll_get_post( $post_id ) ?: $post_id;
		$this->template_id = $post_id; // Save for check sub_id

		return $post_id;

	}

	/**
	 * Filter Elementor sub_conditions system
	 *
	 * If is translated template that is based on term or post
	 *   return the translation ID of term or post.
	 *
	 * @since  2.0.0
	 *
	 * @uses   pll_get_post()
	 * @uses   pll_get_term()
	 *
	 * @param  int   $sub_id ID of the object in subcondition
	 * @param  array $parsed_condition condition parts
	 * @return int original sub ID or translated ID
	 */
	function condition_sub_id_translation( $sub_id, $parsed_condition ) {

		if ( $sub_id && cpel_is_translation( $this->template_id ) ) {

			if ( in_array( $parsed_condition['sub_name'], get_post_types() ) ) {

				$sub_id = pll_get_post( $sub_id ) ?: $sub_id;

			} else {

				$sub_id = pll_get_term( $sub_id ) ?: $sub_id;

			}
		}

		return $sub_id;

	}

	/**
	 * Update Elementor conditions
	 *
	 * On change post_translations terms on Elementor Library trigger conditions regenerate.
	 *
	 * @since  2.0.0
	 *
	 * @param  mixed $post_id
	 * @param  mixed $terms
	 * @param  mixed $tt_ids
	 * @param  mixed $taxonomy
	 * @return void
	 */
	function update_conditions_on_term_change( $post_id, $terms, $tt_ids, $taxonomy ) {

		if ( cpel_is_elementor_pro_active() && 'post_translations' === $taxonomy && 'elementor_library' === get_post_type( $post_id ) ) {

			\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache()->regenerate();

		}

	}

	/**
	 * Hide language column info pre
	 *
	 * Wrap language info for Global Widgets with a hidden div (open)
	 *
	 * @since  2.0.0
	 *
	 * @param  string $column
	 * @param  int    $post_id
	 * @return void
	 */
	function hide_language_column_pre( $column, $post_id ) {

		if ( false === strpos( $column, 'language_' ) || 'widget' !== get_post_meta( $post_id, '_elementor_template_type', true ) ) {
			return;
		}

		echo '<span aria-hidden="true">—</span><div class="hidden" aria-hidden="true">';

	}

	/**
	 * Hide language column info pos
	 *
	 * Wrap language info for Global Widgets with a hidden div (close)
	 *
	 * @since  2.0.0
	 *
	 * @param  string $column
	 * @param  int    $post_id
	 * @return void
	 */
	function hide_language_column_pos( $column, $post_id ) {

		if ( false === strpos( $column, 'language_' ) || 'widget' !== get_post_meta( $post_id, '_elementor_template_type', true ) ) {
			return;
		}

		echo '</div>';

	}

	/**
	 * Don't copy '_elementor_css' meta on Polylang add new translation
	 *
	 * Without this meta Elementor generates the css for the new post.
	 *
	 * @since 2.0.0
	 *
	 * @param  mixed  $null
	 * @param  int    $post_id
	 * @param  string $meta_key
	 * @return mixed null or false
	 */
	public function prevent_elementor_css_meta( $null, $post_id, $meta_key ) {

		global $pagenow;

		return '_elementor_css' === $meta_key && 'post-new.php' === $pagenow
			&& isset( $_GET['from_post'], $_GET['new_lang'] ) ? false : $null;

	}

	/**
	 * Whitelist Elementor Pro home_url()
	 *
	 * Polylang add home_url() to whitelist for Elementor Pro
	 *   "Search Form" widget and "Site Url" dynamic tag.
	 *
	 * @since  2.0.0
	 *
	 * @param  array $white_list
	 * @return array
	 */
	function elementor_home_url_white_list( $white_list ) {

		$white_list[] = array( 'file' => 'search-form.php' );
		$white_list[] = array( 'file' => 'site-url.php' );

		return $white_list;

	}

	/**
	 * Language subdir add trailing slash
	 *
	 * @since  2.0.0
	 *
	 * @param  string $url
	 * @param  string $path
	 * @return string
	 */
	function home_url_language_dir_slash( $url, $path ) {

		return empty( $path ) && 1 === PLL()->options['force_lang'] ? trailingslashit( $url ) : $url;

	}

}
