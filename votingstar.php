<?php
/**
 * VotingStar
 *
 * @package     VotingStarPackage
 * @author      Stephan J. Müller
 * @copyright   2017 Stephan J. Müller
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: VotingStar
 * Plugin URI:  https://github.com/stepmuel/votingstar
 * Description: Adds named counters to WordPress.
 * Version:     1.0.0
 * Author:      Stephan Müller
 * Author URI:  http://heap.ch
 * Text Domain: voting-star
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

defined( 'ABSPATH' ) or die( 'nope' );

define( 'VOTINGSTAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VOTINGSTAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

add_action( 'init', 'votingstar_init' );
add_action( 'admin_init', 'votingstar_handle_admin_actions' );
add_action( 'template_redirect', 'votingstar_handle_input' );

function votingstar_init() {
	add_shortcode( 'votingstar', 'votingstar_shortcode_handler' );
	wp_enqueue_script( 'votingstar', VOTINGSTAR_PLUGIN_URL . 'js/votingstar.js', array( 'jquery' ) );
	$data = array(
		'icon_full' => votingstar_icon_src( true ),
		'icon_empty' => votingstar_icon_src( false ),
		'url' => add_query_arg( array( 'votingstar' => 'json' ) ),
	);
	wp_localize_script( 'votingstar', 'votingstar', $data );
	add_action( 'admin_menu', 'votingstar_create_menu' );
}

function votingstar_create_menu() {
	// Create new top-level menu.
	add_options_page( 'Settings Admin', 'VotingStar', 'administrator', 'votingstar', 'votingstar_admin_menu' );
	// Call register settings function.
	add_action( 'admin_init', function () {
		register_setting( 'votingstar-settings-group', 'votingstar_hidden', 'intval' );
	});
}

function votingstar_admin_menu() {
	$csv_link = home_url( '/' ) . '?votingstar=csv';
	$backup_link = home_url( '/' ) . '?votingstar=backup';
	$hidden = get_option( 'votingstar_hidden' ) === '1';
	$action_form_url = wp_nonce_url( get_admin_url( get_current_blog_id(), '/options-general.php?page=votingstar' ) );
	$show_actions = current_user_can( 'manage_options' );
	?>
	<div class="wrap">
	<h1>VotingStar</h1>
	<p>Download <a href="<?php echo esc_url( $csv_link ); ?>">csv file</a> with number of votes per id.</p>
	<?php if ( $show_actions ): ?>
	<p>Download <a href="<?php echo esc_url( $backup_link ); ?>">backup file</a> including all user votes.</p>
	<?php endif ?>
	<hr />
	<h2>Settings</h2>
	<form method="post" action="options.php">
		<?php settings_fields( 'votingstar-settings-group' ); ?>
		<?php do_settings_sections( 'votingstar-settings-group' ); ?>
		<table class="form-table">
			<tr valign="top">
			<th scope="row">Hide</th>
			<td><input type="checkbox" id="votingstar_hidden" name="votingstar_hidden" value="1"<?php echo $hidden ? ' checked' : ''; ?> /> <label for="votingstar_hidden">Don't show stars or number of votes.</label></td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php if ( $show_actions ): ?>
	<hr />
	<h2>Restore</h2>
	<form id="pb-import-form" action="<?php echo $action_form_url ?>" enctype="multipart/form-data" method="post">
		<input type="hidden" name="votingstar_restore" value="true" id="votingstar_action">
		<p>
			Delete all existing votes and restore from a backup file. Leave the file upload field empty to just delete all votes.
		</p>
		<p><input type="file" name="votingstar_restore" id="votingstar_restore"></p>
		<?php submit_button( 'Reset' , 'primary', 'restore' ); ?>
		<script>
			document.getElementById('restore').addEventListener('click', function(event) {
				var confirmed = confirm('This action will delete all existing votes. Consider downloading a backup first.');
				if (!confirmed) {
					event.preventDefault();
					event.target.blur();
				}
			});
		</script>
	</form>
	<?php endif ?>
	</div>
	<?php
}

function votingstar_handle_admin_actions() {
	$redirect_url = get_admin_url( get_current_blog_id(), '/options-general.php?page=votingstar' );
	if ( ! isset( $_POST['votingstar_restore'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$file = $_FILES['votingstar_restore'];
	if ( '' === $file['tmp_name'] ) {
		votingstar_reset();
	} else {
		$import = @json_decode( file_get_contents( $file['tmp_name'] ) );
		if ( null !== $import ) {
			votingstar_reset( $import );
		}
	}
	// TODO: Add success/failure to url
	wp_redirect( $redirect_url );
	exit;
}

function votingstar_icon_src( $voted ) {
	$img_dir = VOTINGSTAR_PLUGIN_URL . 'img';
	$filled = $voted ? 'on' : 'off';
	$src = "$img_dir/star-$filled.svg";
	return $src;
}

function votingstar_enabled() {
	global $blog_id;
	static $enabled = null;
	if ( null === $enabled ) {
		$enabled = current_user_can_for_blog( $blog_id, 'read' );
		$hidden = get_option( 'votingstar_hidden' ) === '1';
		if ( $hidden ) {
			$enabled = false;
		}
	}
	return $enabled;
}

function votingstar_shortcode_handler( $atts ) {
	// Alternate icons from https://github.com/WordPress/dashicons (GPLv2).
	// <span class="dashicons dashicons-star-filled"></span>
	// <span class="dashicons dashicons-star-empty"></span>
	if ( ! votingstar_enabled() ) {
		return '';
	}
	
	$id = isset( $atts['id'] ) ? $atts['id'] : 'empty';
	$user = get_current_user_id();
	$votes = votingstar_votes( $user );
	
	$voted = in_array( $id, $votes->voted, true );
	$n = isset( $votes->votes->$id ) ? $votes->votes->$id : 0;
	$arg = array(
		'votingstar' => 'redirect',
		'action' => $voted ? 'unvote' : 'vote',
		'key' => $id,
	);
	$href = add_query_arg( $arg );
	$src = votingstar_icon_src( $voted );
	$icon = "<img src=\"$src\" class=\"wp-smiley\" />";
	$link = "<a href=\"$href\" onclick=\"votingstar.vote(this.parentNode, event);\">$icon</a>";
	return "<span data-key=\"$id\" data-voted=\"$voted\" class=\"votingstar\">$link<span>$n</span></span>";
}

function votingstar_handle_input() {
	global $blog_id;
	$vs  = @$_GET['votingstar'];
	$action  = @$_GET['action'];
	$key  = @$_GET['key'];
	if ( null === $vs ) {
		return;
	}
	if ( 'csv' === $vs ) {
		header( 'Content-Disposition: inline; filename="votes.csv"' );
		header( 'Content-type: text/plain' );
		$data = votingstar_votes( $user );
		foreach ( $data->votes as $k => $v ) {
			echo "$k\t$v\n";
		}
		exit;
	}
	if ( 'backup' === $vs && current_user_can( 'manage_options' ) ) {
		header( 'Content-Disposition: inline; filename="votingstar_backup.json"' );
		header( 'Content-type: application/json' );
		$data = votingstar_fetch_backup();
		echo json_encode( $data );
		exit;
	}
	if ( ! votingstar_enabled() ) {
		return;
	}
	$user = get_current_user_id();
	if ( null !== $key ) {
		$vote = $action !== 'unvote';
		votingstar_vote( $key, $user, $vote );
	}
	if ( 'redirect' === $vs ) {
		$url = get_permalink();
		wp_safe_redirect( $url );
	} else {
		header( 'Content-type: application/json' );
		$data = votingstar_votes( $user );
		echo json_encode( $data );
	}
	exit;
}

function votingstar_vote( $key, $user, $vote ) {
	global $wpdb;
	votingstar_update_tables();
	$table_name = $wpdb->prefix . 'votingstar_votes';
	$data = array(
		'ref' => $key,
		'user' => $user,
	);
	$votes = votingstar_votes( $user );
	$voted = in_array( $key, $votes->voted, true );
	if ( $vote && ! $voted ) {
		// $wpdb->show_errors();
		$r = $wpdb->insert( $table_name, $data );
		votingstar_votes( false ); // Flush cache.
	}
	if ( ! $vote && $voted ) {
		$wpdb->delete( $table_name, $data );
		votingstar_votes( false ); // Flush cache.
	}
}

function votingstar_votes( $user = null ) {
	static $cache = null;
	// Set $user = false to delete cache.
	if ( false === $user ) {
		$cache = null;
		return null;
	}
	if ( null === $cache ) {
		$cache = (object) array();
		$cache->votes = votingstar_fetch_votes();
		if ( null !== $user ) {
			$votes = votingstar_fetch_votes( $user );
			$cache->voted = array_keys( get_object_vars( $votes ) );
		}
	}
	return $cache;
}

function votingstar_fetch_votes( $user = null ) {
	global $wpdb;
	if ( ! votingstar_has_table() ) {
		return (object) array();
	}
	$table_name = $wpdb->prefix . 'votingstar_votes';
	if ( null === $user ) {
		$query = "SELECT ref, COUNT(*) as n FROM $table_name GROUP BY ref";
	} else {
		$query = $wpdb->prepare( "SELECT ref, COUNT(*) as n FROM $table_name WHERE user = %s GROUP BY ref", $user );
	}
	$results = $wpdb->get_results( $query );
	$votes = (object) array();
	foreach ( $results as $row ) {
		$key = $row->ref;
		$votes->$key = $row->n;
	}
	return $votes;
}

function votingstar_fetch_backup() {
	global $wpdb;
	if ( ! votingstar_has_table() ) {
		return (object) array();
	}
	$table_name = $wpdb->prefix . 'votingstar_votes';
	$query = "SELECT * FROM $table_name";
	$results = $wpdb->get_results( $query );
	$votes = (object) array();
	foreach ( $results as $row ) {
		$key = $row->ref;
		if ( ! isset( $votes->$key ) ) {
			$votes->$key = array();
		}
		array_push( $votes->$key, (int) $row->user );
	}
	return $votes;
}

function votingstar_reset( $data = null ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'votingstar_votes';
	if ( votingstar_has_table() ) {
		// Make sure table is empty in case DROP doesn't work.
		$wpdb->query( "DELETE FROM $table_name" );
	}
	$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
	delete_option( 'votingstar_db_version' );
	if ( null === $data ) {
		return;
	}
	votingstar_update_tables();
	foreach ( $data as $ref => $users ) {
		$data = array( 'ref' => $ref );
		$users = array_unique( $users );
		foreach ( $users as $user ) {
			$data['user'] = (int) $user;
			$wpdb->insert( $table_name, $data );
		}
	}
}

function votingstar_has_table() {
	$version = get_option( 'votingstar_db_version' );
	return false !== $version;
}

function votingstar_update_tables() {
	global $wpdb;
	$current = '0.0.2';
	$version = get_option( 'votingstar_db_version' );
	if ( $version !== $current ) {
		// DEBUG: Doesn't actually update the table when changing keys.
		// Update table.
		$table_name = $wpdb->prefix . 'votingstar_votes';
		$sql = "CREATE TABLE $table_name (
			ref tinytext NOT NULL,
			user bigint(20) unsigned NOT NULL
		);";
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
		update_option( 'votingstar_db_version', $current );
	}
}
