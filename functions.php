<?php
/**
 * Slightly theme functions and definitions
 *
 * @package slightly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets up theme defaults and registers support for various WordPress features.
 */
add_action( 'after_setup_theme', 'slightly_setup' );

function slightly_setup() {
	load_theme_textdomain( 'slightly-blocked', get_template_directory() . '/languages' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array(
		'search-form',
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
		'style',
		'script',
	) );
	add_theme_support( 'custom-logo' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'style.css' );

	register_nav_menus( array(
		'main-menu'   => __( 'Main Menu', 'slightly-blocked' ),
		'footer-menu' => __( 'Footer Menu', 'slightly-blocked' ),
	) );
}

/**
 * Enqueue scripts and styles.
 */
add_action( 'wp_enqueue_scripts', 'slightly_scripts' );

function slightly_scripts() {
	wp_enqueue_style( 'slightly-style', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
}

/**
 * Register user meta for color scheme preference.
 */
add_action( 'init', 'slightly_register_user_meta' );

function slightly_register_user_meta() {
	$sanitize = fn( $value ) => in_array( $value, [ 'light', 'dark' ], true ) ? $value : '';

	register_meta( 'user', 'slightly-color-scheme', array(
		'label'             => __( 'Color Scheme', 'slightly-blocked' ),
		'description'       => __( 'Stores the preferred color scheme for the site.', 'slightly-blocked' ),
		'default'           => '',
		'sanitize_callback' => $sanitize,
		'show_in_rest'      => true,
		'single'            => true,
		'type'              => 'string',
	) );
}

/**
 * Get the current color scheme preference.
 */
function slightly_get_color_scheme() {
	$key           = 'slightly-color-scheme';
	$valid_schemes = array( 'light', 'dark' );

	if ( is_user_logged_in() ) {
		$scheme = get_user_meta( get_current_user_id(), $key, true );

		if ( $scheme && in_array( $scheme, $valid_schemes, true ) ) {
			return $scheme;
		}
	}

	if ( isset( $_COOKIE[ $key ] ) ) {
		$scheme = sanitize_key( wp_unslash( $_COOKIE[ $key ] ) );

		if ( $scheme && in_array( $scheme, $valid_schemes, true ) ) {
			return $scheme;
		}
	}

	return 'light dark';
}

/**
 * Check if the current color scheme is dark.
 */
function slightly_is_dark_scheme() {
	$scheme = slightly_get_color_scheme();

	return match ( $scheme ) {
		'dark'  => true,
		'light' => false,
		default => null,
	};
}

/**
 * Add interactivity support to the Button block.
 */
add_filter( 'block_type_metadata_settings', 'slightly_block_type_metadata_settings' );

function slightly_block_type_metadata_settings( array $settings ) {
	if ( 'core/button' === $settings['name'] ) {
		$settings['supports']['interactivity'] = true;
	}

	return $settings;
}

/**
 * Filter the Button block to add dark mode toggle functionality.
 */
add_filter( 'render_block_core/button', 'slightly_render_button', 10, 2 );

function slightly_render_button( string $content, array $block ) {
	$processor = new WP_HTML_Tag_Processor( $content );

	// Determine if this is a light/dark toggle button.
	if (
		! $processor->next_tag( array( 'class_name' => 'toggle-color-scheme' ) )
		|| ! $processor->next_tag( 'button' )
	) {
		return $processor->get_updated_html();
	}

	// Add interactivity directives to the <button>.
	$attr = array(
		'data-wp-interactive'           => 'slightly/color-scheme',
		'data-wp-on--click'             => 'actions.toggle',
		'data-wp-init'                  => 'callbacks.init',
		'data-wp-watch'                 => 'callbacks.updateScheme',
		'data-wp-bind--aria-pressed'    => 'state.isDark',
		'data-wp-class--is-dark-scheme' => 'state.isDark',
	);

	foreach ( $attr as $name => $value ) {
		$processor->set_attribute( $name, $value );
	}

	// Set the initial interactivity state.
	wp_interactivity_state( 'slightly/color-scheme', array(
		'colorScheme'  => slightly_get_color_scheme(),
		'isDark'       => slightly_is_dark_scheme(),
		'userId'       => get_current_user_id(),
		'name'         => 'slightly-color-scheme',
		'cookiePath'   => COOKIEPATH,
		'cookieDomain' => COOKIE_DOMAIN,
	) );

	// Enqueue scripts.
	if ( is_user_logged_in() ) {
		wp_enqueue_script( 'wp-api-fetch' );
	}

	$script_path = get_theme_file_path( 'public/js/color-scheme.asset.php' );
	if ( file_exists( $script_path ) ) {
		$script = include $script_path;

		wp_enqueue_script_module(
			'slightly-color-scheme',
			get_theme_file_uri( 'public/js/color-scheme.js' ),
			$script['dependencies'],
			$script['version']
		);
	}

	return $processor->get_updated_html();
}

/**
 * Enqueue editor assets.
 */
add_action( 'enqueue_block_editor_assets', 'slightly_editor_assets' );

function slightly_editor_assets() {
	$script_path = get_theme_file_path( 'public/js/editor.asset.php' );
	if ( ! file_exists( $script_path ) ) {
		return;
	}

	$script = include $script_path;

	wp_enqueue_script(
		'slightly-editor',
		get_theme_file_uri( 'public/js/editor.js' ),
		$script['dependencies'],
		$script['version'],
		true
	);
}

/**
 * Restrict REST API endpoints for guests (security).
 */
add_filter( 'rest_authentication_errors', 'slightly_restrict_rest_endpoints' );

function slightly_restrict_rest_endpoints( $errors ) {
	if ( is_wp_error( $errors ) ) {
		return $errors;
	}

	if ( ! is_user_logged_in() ) {
		$restricted_endpoints = array(
			'/wp/v2/media',
			'/wp/v2/users',
		);

		$request_uri = $_SERVER['REQUEST_URI'];
		$prefix      = '/' . rest_get_url_prefix();

		foreach ( $restricted_endpoints as $endpoint ) {
			if ( strpos( $request_uri, $prefix . $endpoint ) === 0 ) {
				return new WP_Error( 'rest_not_logged_in', 'You must be logged in to access this endpoint.', array( 'status' => 401 ) );
			}
		}
	}

	return $errors;
}
