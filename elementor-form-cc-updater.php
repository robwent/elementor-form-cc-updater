<?php
/**
 * Plugin Name:       Elementor Form Cc Updater
 * Plugin URI:        https://github.com/robwent/elementor-form-cc-updater
 * Description:       Sets a uniform Cc on every Elementor form's email action across the whole site. The Cc address list is editable and saved, so existing forms can be overwritten to match. Run from Tools → Form Cc Updater, or via WP-CLI (wp form-cc).
 * Version:           2.0.0
 * Author:            Robert Went
 * Author URI:        https://robertwent.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.6
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option that stores the current Cc value (a comma-separated email list).
 * Kept in the DB so the page shows what is currently configured.
 */
const EFCC_OPTION = 'efcc_cc_value';

/**
 * Get the currently saved Cc value.
 *
 * @return string Comma-separated email list (may be empty).
 */
function efcc_get_value() {
	return (string) get_option( EFCC_OPTION, '' );
}

/**
 * Normalise a raw Cc string into a clean "a@b.com, c@d.com" list of valid emails.
 *
 * @param string $raw      Raw input (comma and/or newline separated).
 * @param array  $invalid  Filled with any entries that were dropped as invalid.
 * @return string Normalised comma-separated list of valid emails.
 */
function efcc_normalise( $raw, array &$invalid = array() ) {
	$invalid = array();
	$parts   = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
	$valid   = array();

	foreach ( $parts as $part ) {
		$email = sanitize_email( $part );
		if ( $email && is_email( $email ) ) {
			$valid[ strtolower( $email ) ] = $email; // de-dupe, case-insensitive.
		} else {
			$invalid[] = $part;
		}
	}

	return implode( ', ', array_values( $valid ) );
}

/**
 * Recursively walk an Elementor element tree, forcing the Cc on every form widget.
 *
 * @param array  $elements Elements (by reference so changes persist).
 * @param string $cc       The Cc value to apply.
 * @param int    $changed  Running count of form widgets whose Cc changed.
 * @param int    $already  Running count of forms that already had the target Cc.
 */
function efcc_walk( array &$elements, $cc, &$changed, &$already ) {
	foreach ( $elements as &$el ) {
		if ( isset( $el['widgetType'] ) && 'form' === $el['widgetType'] ) {
			if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
				$el['settings'] = array();
			}

			$current = isset( $el['settings']['email_to_cc'] ) ? $el['settings']['email_to_cc'] : '';
			if ( $cc === $current ) {
				$already++;
			} else {
				$el['settings']['email_to_cc'] = $cc;
				$changed++;
			}

			// Mirror onto the second email action, but only if that form enables it.
			$actions = isset( $el['settings']['submit_actions'] ) ? (array) $el['settings']['submit_actions'] : array();
			if ( in_array( 'email2', $actions, true ) ) {
				$el['settings']['email_to_cc_2'] = $cc;
			}
		}

		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			efcc_walk( $el['elements'], $cc, $changed, $already );
		}
	}
	unset( $el );
}

/**
 * Apply (or preview) the Cc across all Elementor documents.
 *
 * @param string $cc      The Cc value to apply.
 * @param bool   $dry_run When true, nothing is written — only counts are returned.
 * @return array{posts_scanned:int,posts_updated:int,forms_changed:int,forms_already:int,errors:int}
 */
function efcc_run( $cc, $dry_run = false ) {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_elementor_data'
		 AND meta_value LIKE '%\"widgetType\":\"form\"%'"
	);

	$stats = array(
		'posts_scanned' => 0,
		'posts_updated' => 0,
		'forms_changed' => 0,
		'forms_already' => 0,
		'errors'        => 0,
	);

	foreach ( $rows as $row ) {
		$stats['posts_scanned']++;

		$data = json_decode( $row->meta_value, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			$stats['errors']++;
			continue;
		}

		$changed = 0;
		$already = 0;
		efcc_walk( $data, $cc, $changed, $already );

		$stats['forms_changed'] += $changed;
		$stats['forms_already'] += $already;

		if ( $changed > 0 && ! $dry_run ) {
			// Store exactly the way Elementor does: JSON, then wp_slash so WP's
			// unslash-on-save round-trips the escaped slashes/quotes correctly.
			$json    = wp_json_encode( $data );
			$updated = update_metadata( 'post', (int) $row->post_id, '_elementor_data', wp_slash( $json ) );
			if ( false === $updated ) {
				$stats['errors']++;
			} else {
				$stats['posts_updated']++;
			}
		}
	}

	// Best-effort: clear Elementor's file/CSS cache if Elementor is active.
	if ( ! $dry_run && $stats['posts_updated'] > 0 && class_exists( '\Elementor\Plugin' ) ) {
		$instance = \Elementor\Plugin::$instance;
		if ( $instance && isset( $instance->files_manager ) ) {
			$instance->files_manager->clear_cache();
		}
	}

	return $stats;
}

/* -------------------------------------------------------------------------
 * Admin page: Tools → Form Cc Updater
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_management_page(
		'Form Cc Updater',
		'Form Cc Updater',
		'manage_options',
		'form-cc-updater',
		'efcc_admin_page'
	);
} );

function efcc_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}

	$result  = null;
	$action  = '';
	$invalid = array();
	$cc      = efcc_get_value();

	if ( isset( $_POST['efcc_action'] ) ) {
		check_admin_referer( 'efcc' );

		$action   = sanitize_text_field( wp_unslash( $_POST['efcc_action'] ) );
		$raw      = isset( $_POST['efcc_cc'] ) ? wp_unslash( $_POST['efcc_cc'] ) : '';
		$cc       = efcc_normalise( $raw, $invalid );

		// Always persist whatever was entered so the field reflects current state.
		// Not autoloaded — it is only ever read on this admin page / WP-CLI run.
		update_option( EFCC_OPTION, $cc, false );

		if ( '' === $cc ) {
			add_settings_error( 'efcc', 'efcc_empty', 'Enter at least one valid email address before previewing or applying.', 'error' );
		} elseif ( 'preview' === $action ) {
			$result = efcc_run( $cc, true );
		} elseif ( 'apply' === $action ) {
			$result = efcc_run( $cc, false );
		}
	}

	echo '<div class="wrap">';
	echo '<h1>Form Cc Updater</h1>';
	echo '<p>Sets the Cc on <strong>every</strong> Elementor form email to the addresses below, overwriting any existing Cc so all forms match. <strong>Preview</strong> writes nothing; <strong>Apply</strong> performs the update.</p>';

	if ( $invalid ) {
		echo '<div class="notice notice-warning"><p>Ignored invalid entries: <code>' . esc_html( implode( ', ', $invalid ) ) . '</code></p></div>';
	}
	settings_errors( 'efcc' );

	echo '<form method="post" style="margin:1em 0;max-width:680px;">';
	wp_nonce_field( 'efcc' );
	echo '<p><label for="efcc_cc"><strong>Cc addresses</strong> (comma separated):</label></p>';
	echo '<textarea id="efcc_cc" name="efcc_cc" rows="3" class="large-text code" placeholder="one@example.com, two@example.com">' . esc_textarea( $cc ) . '</textarea>';
	if ( '' !== $cc ) {
		echo '<p class="description">Currently saved: <code>' . esc_html( $cc ) . '</code></p>';
	}
	echo '<p style="margin-top:1em;">';
	echo '<button class="button button-secondary" name="efcc_action" value="preview">Save &amp; Preview (dry run)</button> ';
	echo '<button class="button button-primary" name="efcc_action" value="apply" onclick="return confirm(\'Overwrite the Cc on all Elementor forms with the addresses shown?\');">Save &amp; Apply changes</button>';
	echo '</p>';
	echo '</form>';

	if ( $result ) {
		$verb = ( 'apply' === $action ) ? 'Applied' : 'Preview';
		echo '<div class="notice notice-' . ( $result['errors'] ? 'warning' : 'success' ) . '"><p><strong>' . esc_html( $verb ) . ':</strong></p><ul style="list-style:disc;margin-left:2em;">';
		echo '<li>Posts scanned: ' . (int) $result['posts_scanned'] . '</li>';
		if ( 'apply' === $action ) {
			echo '<li>Posts updated: ' . (int) $result['posts_updated'] . '</li>';
		}
		echo '<li>Forms ' . ( 'apply' === $action ? 'changed' : 'that would change' ) . ': ' . (int) $result['forms_changed'] . '</li>';
		echo '<li>Forms already correct: ' . (int) $result['forms_already'] . '</li>';
		echo '<li>Errors: ' . (int) $result['errors'] . '</li>';
		echo '</ul></div>';
	}

	echo '</div>';
}

/* -------------------------------------------------------------------------
 * WP-CLI command: wp form-cc [--cc="a@b.com, c@d.com"] [--dry-run]
 *   Without --cc it uses the saved value. With --cc it saves then uses it.
 *   e.g. on Flywheel over SSH:
 *     wp form-cc --cc="s.curti@acpsurveyors.com, andrea@acpsurveyors.com" --dry-run
 *     wp form-cc
 * ---------------------------------------------------------------------- */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'form-cc', function ( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( isset( $assoc_args['cc'] ) ) {
			$invalid = array();
			$cc      = efcc_normalise( $assoc_args['cc'], $invalid );
			update_option( EFCC_OPTION, $cc, false );
			if ( $invalid ) {
				WP_CLI::warning( 'Ignored invalid entries: ' . implode( ', ', $invalid ) );
			}
		} else {
			$cc = efcc_get_value();
		}

		if ( '' === $cc ) {
			WP_CLI::error( 'No Cc value set. Pass --cc="a@b.com, c@d.com" or save one on the admin page first.' );
		}

		$stats = efcc_run( $cc, $dry_run );

		WP_CLI::log( ( $dry_run ? '[DRY RUN] ' : '' ) . 'Cc target: ' . $cc );
		WP_CLI::log( 'Posts scanned:         ' . $stats['posts_scanned'] );
		WP_CLI::log( 'Posts updated:         ' . $stats['posts_updated'] );
		WP_CLI::log( 'Forms changed:         ' . $stats['forms_changed'] );
		WP_CLI::log( 'Forms already correct: ' . $stats['forms_already'] );
		WP_CLI::log( 'Errors:                ' . $stats['errors'] );

		if ( $stats['errors'] ) {
			WP_CLI::warning( 'Completed with errors.' );
		} else {
			WP_CLI::success( $dry_run ? 'Dry run complete (nothing written).' : 'Cc applied to all forms.' );
		}
	} );
}
