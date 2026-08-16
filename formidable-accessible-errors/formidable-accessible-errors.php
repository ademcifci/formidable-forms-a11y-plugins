<?php
/**
 * Plugin Name: Formidable - Accessible Error Summary
 * Description: Accessible error summary for Formidable Forms: focuses the summary, and links each error to the field. Disables default first-error focus & per-field alert roles.
 * Version:     1.3.5
 * Author:      Adem Cifcioglu
 * License:     GPL-2.0+
 * Text Domain: ff-accessible-errors
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class FF_Accessible_Error_Summary {
	const OPTION_KEY = 'ff_accessible_errors';

	private static $errors            = [];
	private static $form_id           = 0;
	private static $assets_registered = false;
	private static $assets_enqueued   = false;
	private static $load_css          = false;

	public static function init() {
		// Capture errors and remember the current form id.
		add_filter( 'frm_validate_entry', [ __CLASS__, 'capture_errors' ], 20, 2 );

		// Replace the invalid error message block with an accessible summary.
		add_filter( 'frm_invalid_error_message', [ __CLASS__, 'render_error_summary' ], 10, 2 );

		// Our JS for focusing summary + jumping to fields. Register early; enqueue when a form renders.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
		add_action( 'frm_enqueue_form_scripts', [ __CLASS__, 'maybe_enqueue_assets' ], 10, 1 );

		// Disable Formidable's default behaviours that interfere with our UX.
		add_filter( 'frm_focus_first_error', '__return_false' );
		add_filter( 'frm_include_alert_role_on_field_errors', '__return_false' );

		// Admin settings.
		add_action( 'admin_menu', [ __CLASS__, 'register_settings_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ __CLASS__, 'add_settings_link' ] );
	}

	public static function capture_errors( $errors, $values ) {
		self::$errors  = is_array( $errors ) ? $errors : [];
		// Formidable includes form_id in $values on validation.
		if ( ! empty( $values['form_id'] ) ) {
			self::$form_id = absint( $values['form_id'] );
		}
		return $errors;
	}

	public static function render_error_summary( $invalid_msg, $args ) {
		$errors  = self::$errors;
		$form_id = self::$form_id;

		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return esc_html__( 'There was a problem with your submission. Errors are marked below.', 'ff-accessible-errors' );
		}

		// If args contains a form object, prefer that id.
		if ( isset( $args['form']->id ) && $args['form']->id ) {
			$form_id = absint( $args['form']->id );
		}

		// Build a lookup of field identifiers -> numeric field IDs for this form.
		$field_map = self::build_field_map( $form_id );

		$settings          = self::get_settings();
		$summary_class     = self::get_summary_class();
		$show_summary_icon = (bool) apply_filters( 'ff_accessible_errors_summary_icon_enabled', ! empty( $settings['summary_icon_enabled'] ) );
		$summary_icon      = $show_summary_icon ? '<span class="ff-error-icon" aria-hidden="true"></span>' : '';

		$heading_id = 'ff-error-heading-' . (int) $form_id;
		$out  = '<div class="' . esc_attr( $summary_class ) . '" data-ff-error-summary="1" aria-labelledby="' . esc_attr( $heading_id ) . '" tabindex="-1">';
		$out .= '<h2 id="' . esc_attr( $heading_id ) . '">' . $summary_icon .
		        esc_html__( 'There were the following problems with your submission:', 'ff-accessible-errors' ) .
		        '</h2>';
		$out .= '<ol class="ff-error-list">';

		foreach ( $errors as $key => $message ) {
			$msg = is_array( $message ) ? implode( ' ', $message ) : (string) $message;
			$msg = trim( $msg );

			$field_id = self::resolve_field_id( $key, $field_map );
			if ( ! empty( $msg ) ) {
				if ( $field_id ) {
					$container_id = 'frm_field_' . $field_id . '_container';
					$out         .= '<li class="ff-error-item"><a class="ff-error-link" href="#' . esc_attr( $container_id ) . '" data-ff-field-id="' . esc_attr( $field_id ) . '">' . esc_html( $msg ) . '</a></li>';
				} else {
					// Non-field/global errors.
					$out .= '<li class="ff-error-item">' . esc_html( $msg ) . '</li>';
				}
			}
		}

		$out .= '</ol></div>';
		return $out;
	}

	/**
	 * Create flexible mappings for the current form:
	 *  - by_id[123] = 123
	 *  - by_prefixed["field123"] = 123
	 *  - by_key["email_address"] = 456   (field key/slug)
	 */
	private static function build_field_map( $form_id ) {
		$map = [ 'by_id' => [], 'by_prefixed' => [], 'by_key' => [] ];

		if ( ! class_exists( 'FrmField' ) || ! $form_id ) {
			return $map;
		}

		$fields = \FrmField::get_all_for_form( $form_id, '', 'include' );
		foreach ( (array) $fields as $f ) {
			$id  = isset( $f->id ) ? (int) $f->id : 0;
			$key = isset( $f->field_key ) ? (string) $f->field_key : '';
			if ( $id ) {
				$map['by_id'][ $id ]       = $id;
				$map['by_prefixed'][ 'field' . $id ]     = $id;         // handles "field123"
				$map['by_prefixed'][ 'frm_field_' . $id ] = $id;     // extra safety
			}
			if ( $key ) {
				$map['by_key'][ $key ] = $id;
			}
		}
		return $map;
	}

	/**
	 * Resolve a field id from a variety of error keys:
	 * - 123                    -> 123
	 * - "field123"             -> 123
	 * - "frm_field_123"        -> 123
	 * - "{field_key/slug}"     -> id
	 * - Anything else          -> null (global error)
	 */
	private static function resolve_field_id( $error_key, $map ) {
		// Numeric key straight to id.
		if ( is_numeric( $error_key ) ) {
			$id = (int) $error_key;
			return $map['by_id'][ $id ] ?? $id;
		}

		$key = (string) $error_key;

		// Exact key match first (field key / slug).
		if ( isset( $map['by_key'][ $key ] ) ) {
			return (int) $map['by_key'][ $key ];
		}

		// Common prefixes.
		if ( isset( $map['by_prefixed'][ $key ] ) ) {
			return (int) $map['by_prefixed'][ $key ];
		}

		// Fallback: extract trailing digits (handles "field123", "x_123").
		if ( preg_match( '/(\d{1,})$/', $key, $m ) ) {
			$id = (int) $m[1];
			if ( isset( $map['by_id'][ $id ] ) ) {
				return $id;
			}
		}

		return null;
	}

	public static function register_assets() {
		if ( self::$assets_registered ) {
			return;
		}

		self::$assets_registered = true;

		wp_register_script(
			'ff-accessible-errors',
			plugins_url( 'assets/js/ff-accessible-errors.js', __FILE__ ),
			[ 'jquery' ],
			'1.3.5',
			true
		);

		$settings = self::get_settings();

		// Options for JS (toggle & aria label). Filters still override saved settings.
		$inline_icon_enabled    = (bool) apply_filters( 'ff_accessible_errors_inline_icon_enabled', ! empty( $settings['inline_icon_enabled'] ) );
		$inline_icon_label      = apply_filters( 'ff_accessible_errors_inline_icon_aria_label', __( 'Error:', 'ff-accessible-errors' ) );
		$success_message_focus = (bool) apply_filters( 'ff_accessible_errors_success_message_focus', ! empty( $settings['success_message_focus'] ) );
		$summary_selector      = self::get_summary_selector( $settings );

		wp_localize_script(
			'ff-accessible-errors',
			'FFAE',
			[
				'inlineIconEnabled'    => (bool) $inline_icon_enabled,
				'inlineIconAriaLabel'  => (string) $inline_icon_label,
				'successMessageFocus'  => (bool) $success_message_focus,
				'errorSummarySelector' => (string) $summary_selector,
			]
		);

		$load_css       = (bool) apply_filters( 'ff_accessible_errors_load_css', ! empty( $settings['load_css'] ) );
		self::$load_css = $load_css;
		if ( self::$load_css ) {
			$style_deps = wp_style_is( 'formidable', 'registered' ) ? [ 'formidable' ] : [];
			wp_register_style(
				'ff-accessible-errors',
				plugins_url( 'assets/css/ff-accessible-errors.css', __FILE__ ),
				$style_deps,
				'1.3.5'
			);
		}
	}

	public static function maybe_enqueue_assets( $params = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( self::$assets_enqueued ) {
			return;
		}

		if ( ! self::$assets_registered ) {
			self::register_assets();
		}

		wp_enqueue_script( 'ff-accessible-errors' );

		if ( self::$load_css ) {
			wp_enqueue_style( 'ff-accessible-errors' );
		}

		self::$assets_enqueued = true;
	}

	public static function register_settings_page() {
		add_options_page(
			__( 'Accessible Error Summary', 'ff-accessible-errors' ),
			__( 'FF Accessible Errors', 'ff-accessible-errors' ),
			'manage_options',
			'formidable-accessible-errors',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	public static function register_settings() {
		register_setting( self::OPTION_KEY, self::OPTION_KEY, [ __CLASS__, 'sanitize_settings' ] );

		add_settings_section(
			'ffae_main',
			__( 'Error Summary Settings', 'ff-accessible-errors' ),
			function () {
				echo '<p>' . esc_html__( 'Configure how validation errors and success messages are displayed for Formidable Forms.', 'ff-accessible-errors' ) . '</p>';
			},
			self::OPTION_KEY
		);

		add_settings_field(
			'error_summary_class',
			__( 'Error summary CSS class', 'ff-accessible-errors' ),
			function () {
				$settings = self::get_settings();
				$value    = isset( $settings['error_summary_class'] ) ? $settings['error_summary_class'] : 'ff-global-errors';
				echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[error_summary_class]" value="' . esc_attr( $value ) . '" placeholder="ff-global-errors">';
				echo '<p class="description">' . esc_html__( 'CSS class(es) for the error summary container. Use your theme class here to reuse existing styles (e.g. global-errors). Include ff-global-errors if you want the plugin stylesheet to apply.', 'ff-accessible-errors' ) . '</p>';
			},
			self::OPTION_KEY,
			'ffae_main'
		);

		add_settings_field(
			'inline_icon_enabled',
			__( 'Inline error icons', 'ff-accessible-errors' ),
			function () {
				$settings = self::get_settings();
				$checked  = ! empty( $settings['inline_icon_enabled'] ) ? 'checked' : '';
				echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[inline_icon_enabled]" value="1" ' . $checked . '> ' . esc_html__( 'Show icons next to per-field error messages', 'ff-accessible-errors' ) . '</label>';
			},
			self::OPTION_KEY,
			'ffae_main'
		);

		add_settings_field(
			'summary_icon_enabled',
			__( 'Summary error icon', 'ff-accessible-errors' ),
			function () {
				$settings = self::get_settings();
				$checked  = ! empty( $settings['summary_icon_enabled'] ) ? 'checked' : '';
				echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[summary_icon_enabled]" value="1" ' . $checked . '> ' . esc_html__( 'Show icon in the error summary heading', 'ff-accessible-errors' ) . '</label>';
			},
			self::OPTION_KEY,
			'ffae_main'
		);

		add_settings_field(
			'success_message_focus',
			__( 'Success message focus', 'ff-accessible-errors' ),
			function () {
				$settings = self::get_settings();
				$checked  = ! empty( $settings['success_message_focus'] ) ? 'checked' : '';
				echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[success_message_focus]" value="1" ' . $checked . '> ' . esc_html__( 'Move keyboard focus to the success message after a valid submission', 'ff-accessible-errors' ) . '</label>';
			},
			self::OPTION_KEY,
			'ffae_main'
		);

		add_settings_field(
			'load_css',
			__( 'Plugin styles', 'ff-accessible-errors' ),
			function () {
				$settings = self::get_settings();
				$checked  = ! empty( $settings['load_css'] ) ? 'checked' : '';
				echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[load_css]" value="1" ' . $checked . '> ' . esc_html__( 'Load included CSS for the error summary and icons', 'ff-accessible-errors' ) . '</label>';
				echo '<p class="description">' . esc_html__( 'Disable this if you prefer to style errors in your theme.', 'ff-accessible-errors' ) . '</p>';
			},
			self::OPTION_KEY,
			'ffae_main'
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Formidable Accessible Error Summary', 'ff-accessible-errors' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::OPTION_KEY );
		do_settings_sections( self::OPTION_KEY );
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	public static function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$current  = get_option( self::OPTION_KEY );
		if ( ! is_array( $current ) ) {
			$current = [];
		}
		$in = is_array( $input ) ? $input : [];

		$out = [
			'error_summary_class'   => self::sanitize_css_classes( $in['error_summary_class'] ?? '' ),
			'inline_icon_enabled'   => empty( $in['inline_icon_enabled'] ) ? 0 : 1,
			'summary_icon_enabled'  => empty( $in['summary_icon_enabled'] ) ? 0 : 1,
			'success_message_focus' => empty( $in['success_message_focus'] ) ? 0 : 1,
			'load_css'              => empty( $in['load_css'] ) ? 0 : 1,
		];

		return wp_parse_args( $out, $defaults );
	}

	public static function add_settings_link( $links ) {
		$url = admin_url( 'options-general.php?page=formidable-accessible-errors' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'ff-accessible-errors' ) . '</a>'
		);
		return $links;
	}

	private static function defaults() {
		return [
			'error_summary_class'   => 'ff-global-errors',
			'inline_icon_enabled'   => 1,
			'summary_icon_enabled'  => 1,
			'success_message_focus' => 1,
			'load_css'              => 1,
		];
	}

	private static function get_settings() {
		$saved = get_option( self::OPTION_KEY );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Sanitize a space-separated list of CSS class names.
	 *
	 * @param string $input Raw class string.
	 * @return string
	 */
	private static function sanitize_css_classes( $input ) {
		$parts = preg_split( '/\s+/', trim( (string) $input ) );
		$valid = [];

		foreach ( (array) $parts as $part ) {
			if ( preg_match( '/^[a-zA-Z_][\w-]*$/', $part ) ) {
				$valid[] = $part;
			}
		}

		return $valid ? implode( ' ', $valid ) : 'ff-global-errors';
	}

	/**
	 * Get the error summary container class string for markup output.
	 *
	 * @return string
	 */
	private static function get_summary_class() {
		$settings = self::get_settings();
		$class    = isset( $settings['error_summary_class'] ) ? (string) $settings['error_summary_class'] : 'ff-global-errors';

		return self::sanitize_css_classes(
			apply_filters( 'ff_accessible_errors_summary_class', $class )
		);
	}

	/**
	 * Build a jQuery-compatible selector for the summary container.
	 *
	 * @param array|null $settings Optional settings array.
	 * @return string
	 */
	private static function get_summary_selector( $settings = null ) {
		if ( ! is_array( $settings ) ) {
			$settings = self::get_settings();
		}

		$class = isset( $settings['error_summary_class'] ) ? (string) $settings['error_summary_class'] : 'ff-global-errors';
		$class = self::sanitize_css_classes(
			apply_filters( 'ff_accessible_errors_summary_class', $class )
		);

		$parts = preg_split( '/\s+/', $class );
		$first = $parts[0] ?? 'ff-global-errors';

		return '.' . $first;
	}
}

FF_Accessible_Error_Summary::init();

register_activation_hook( __FILE__, function () {
	$key      = FF_Accessible_Error_Summary::OPTION_KEY;
	$existing = get_option( $key );
	if ( false === $existing ) {
		add_option(
			$key,
			[
				'error_summary_class'   => 'ff-global-errors',
				'inline_icon_enabled'   => 1,
				'summary_icon_enabled'  => 1,
				'success_message_focus' => 1,
				'load_css'              => 1,
			]
		);
	}
} );
