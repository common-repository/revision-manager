<?php
/*
Plugin Name: CF Revision Manager
Plugin URI: http://crowdfavorite.com
Description: Revision management functionality so that plugins can add metadata to revisions as well as restore that metadata from revisions.
Version: 1.0
Author: Crowd Favorite
Author URI: http://crowdfavorite.com 
*/
if (!class_exists('cf_revisions')) { // double-load check

define('CF_REVISIONS_DEBUG', false);

function cfr_register_metadata($postmeta_key, $display_func = '') {
	static $cfr;
	if (empty($cfr)) {
		$cfr = cf_revisions::get_instance();
	} 
	return $cfr->register($postmeta_key, $display_func);
}

class cf_revisions {
	private static $_instance;
	protected $postmeta_keys = array();

	public function __construct() {
		# save & restore
		add_action('save_post', array($this, 'save_post_revision'), 10, 2);
		add_action('wp_restore_post_revision', array($this, 'restore_post_revision'), 10, 2);

		if (is_admin()) {		
			# revision display
			global $pagenow;
			if ($pagenow == 'revision.php') {
				add_filter('_wp_post_revision_fields', array($this, 'post_revision_fields'), 10, 1);
				add_filter('_wp_post_revision_field_postmeta', array($this, 'post_revision_field'), 1, 2);
			}
		}
	}

	public function register($postmeta_key, $display_func = '') {
		if (!in_array($postmeta_key, $this->postmeta_keys, true)) {
			$this->postmeta_keys[] = compact('postmeta_key', 'display_func');
		}
		return true;
	}

	/**
	 * This is a paranoid check. There will be no object to register the 
	 * actions and filters if nobody adds any postmeta to be handled
	 *
	 * @return bool
	 */
	public function have_keys() {
		return (bool) count($this->postmeta_keys);
	}
	
	/**
	 * Return only the keys.
	 */
	public function registered_keys() {
		$keys = array();
		if (count($this->postmeta_keys)) {
			foreach ($this->postmeta_keys as $key) {
				extract($key);
				$keys[] = $postmeta_key;
			}
		}
		return array_unique($keys);
	}

	/**
	 * Save the revision data
	 *
	 * @param int $post_id 
	 * @param object $post 
	 * @return void
	 */
	public function save_post_revision($post_id, $post) {
		if ($post->post_type != 'revision' || !$this->have_keys()) {
			return false;
		}
	
		foreach ($this->postmeta_keys as $postmeta_type) {
			$postmeta_key = $postmeta_type['postmeta_key'];
		
			if ($postmeta_value = get_post_meta($post->post_parent, $postmeta_key, true)) {
				add_metadata('post', $post_id, $postmeta_key, $postmeta_value);
				$this->log('Added postmeta for: '.$postmeta_key.' to revision: '.$post_id.' from post: '.$post->post_parent);
			}
		}
	}

	/**
	 * Revert the revision data
	 *
	 * @param int $post_id 
	 * @param int $revision_id 
	 * @return void
	 */
	public function restore_post_revision($post_id, $revision_id) {
		if (!$this->have_keys()) {
			return false;
		}
	
		foreach ($this->postmeta_keys as $postmeta_type) {
			$postmeta_key = $postmeta_type['postmeta_key'];
		
			if ($postmeta_value = get_metadata('post', $revision_id, $postmeta_key, true)) {
				if (get_metadata('post', $post_id, $postmeta_key, true)) {
					$this->log('Updating postmeta: '.$postmeta_key.' for post: '.$post_id.' from revision: '.$revision_id);
					update_metadata('post', $post_id, $postmeta_key, $postmeta_value);
				}
				else {
					$this->log('Adding postmeta: '.$postmeta_key.' for post: '.$post_id);
					add_metadata('post', $post_id, $postmeta_key, $postmeta_value, true);
				}
				$this->log('Restored post_id: '.$post_id.' metadata from: '.$postmeta_key);
			}
		}
	}

	public function post_revision_fields($fields) {
		$fields['postmeta'] = 'Post Meta';
		return $fields;
	}

	public function post_revision_field($field_id, $field) {
		if ($field != 'postmeta' || !$this->have_keys()) {
			return;
		}
	
		remove_filter('_wp_post_revision_field_postmeta', 'htmlspecialchars', 10, 2);
			
		$html = '<ul style="white-space: normal; margin-left: 1.5em; list-style: disc outside;">';
		foreach ($this->postmeta_keys as $postmeta_type) {
			$postmeta_key = $postmeta_type['postmeta_key'];
			$postmeta = maybe_unserialize(get_metadata('post', intval($_GET['revision']), $postmeta_key, true));

			if (!empty($postmeta)) {
				if (!empty($postmeta_type['display_func']) && function_exists($postmeta_type['display_func'])) {
					$postmeta_html = $postmeta_type['display_func']($postmeta);
				}
				else {
					$postmeta_rendered = (is_array($postmeta) || is_object($postmeta) ? print_r($postmeta, true) : $postmeta);
					$postmeta_html = apply_filters('_wp_post_revision_field_postmeta_display', htmlspecialchars($postmeta_rendered), $postmeta_key, $postmeta);
				}
			}
			else {
				$postmeta_html = '*empty postmeta value*';
			}
		
			$html .= '
				<li>
					<h3><a href="#postmeta-'.$postmeta_key.'" onclick="jQuery(\'#postmeta-'.$postmeta_key.'\').slideToggle(); return false;">'.$postmeta_key.'</a></h3>
					<div id="postmeta-'.$postmeta_key.'" style="display: none;">'.$postmeta_html.'</div>
				</li>
				';
		}
		$html .= '</ul>';
	
		return $html;
	}

	/**
	 * Singleton
	 *
	 * @return object
	 */
	public function get_instance() {
		if (!(self::$_instance instanceof cf_revisions)) {
			self::$_instance = new cf_revisions;
		}
		return self::$_instance;
	}

	protected function log($message) {
		if (CF_REVISIONS_DEBUG) {
			error_log($message);
		}
	}
	
	static function meta_keys() {
		global $wpdb;
		return $wpdb->get_col("
			SELECT DISTINCT `meta_key`
			FROM $wpdb->postmeta
			ORDER BY `meta_key`
		");
	}
}

// admin form

load_plugin_textdomain('revision_manager');

class cf_revisions_admin {
	static function admin_menu() {
		add_options_page(
			__('Revision Manager', 'revision_manager'),
			__('Revision Manager', 'revision_manager'),
			'manage_options',
			basename(__FILE__),
			array('cf_revisions_admin', 'admin_form')
		);
	}

	static function admin_form() {
		$required_keys = cf_revisions_admin::required_keys();
		$keys = array_diff(cf_revisions::meta_keys(), cf_revisions_admin::excluded_keys(), $required_keys);
?>
<div class="wrap">
	<h2><?php _e('CF Revision Manager', 'revision-manager'); ?></h2>
<?php
		if (!count($keys)) {
			echo '<p>'.__('No custom fields found.', 'revision-manager').'</p>';
		}
		else {
			echo '<form id="cfr_revision_manager_form" name="cfr_revision_manager_form" action="'.admin_url('options-general.php').'" method="post">
				<p>'.__('A plugin or theme has specified that the following custom fields need to included in revisions.', 'revision-manager').'</p>';
			if (count($required_keys)) {
				echo '<div>
				<ul id="cfr_revision_manager_keys_required">';
				foreach ($required_keys as $key) {
					$checked = $key;
					$disabled = $key;
					$id = 'cf_revision_manager_key_'.esc_attr($key);
					echo '<li>
						<input type="checkbox" name="revision_manager_keys[]" id="'.$id.'" value="'.esc_attr($key).'" '.checked($key, $checked, false).' '.disabled($key, $disabled, false).' /> 
						<label for="'.$id.'">'.esc_html($key).'</label>
					</li>';
				}
				echo '</ul>
				</div>';
			}
			echo '<p class="clearfix">'.__('Below is a list of selectable custom fields for this site. Choose the ones you would like to have included in your revisions.', 'revision-manager').'</p>
				<div>
				<ul id="cfr_revision_manager_keys">';
			foreach ($keys as $key) {
				$checked = (in_array($key, cf_revisions_admin::selected_keys()) ? $key : '');
				$disabled = '';
				$id = 'cf_revision_manager_key_'.esc_attr($key);
				echo '<li>
					<input type="checkbox" name="revision_manager_keys[]" id="'.$id.'" value="'.esc_attr($key).'" '.checked($key, $checked, false).' '.disabled($key, $disabled, false).' /> 
					<label for="'.$id.'">'.esc_html($key).'</label>
				</li>';
			}
			echo '</ul>
				</div>
				<p class="submit">
				<input type="submit" name="submit_button" class="button-primary" value="'.__('Save').'" />
				</p>
				<input type="hidden" name="cf_action" value="cfr_save_keys" class="hidden" style="display: none;" />
				'.wp_nonce_field('cfr_save_keys', '_wpnonce', true, false).wp_referer_field(false).' 
			</form>';
		}
?>
</div>
<?php
	}
	
	static function required_keys() {
		global $CFR_KEYS_REQUIRED; // note, this is set in the register_meta() method
		return $CFR_KEYS_REQUIRED;
	}

	static function excluded_keys() {
		return apply_filters(
			'cf_revision_manager_excluded_keys',
			array(
				'_edit_last',
				'_edit_lock',
			)
		);
	}

	static function selected_keys() {
		$selected = get_option('cf_revision_manager_meta_keys');
		if (empty($selected)) {
			$selected = array();
		}
		return $selected;
	}

	static function register_meta() {
		if (function_exists('cfr_register_metadata')) {
cfr_register_metadata('foo');
			global $CFR_KEYS_REQUIRED;
			$cfr = cf_revisions::get_instance();
			$CFR_KEYS_REQUIRED = $cfr->registered_keys();
			$keys = cf_revisions_admin::selected_keys();
			if (count($keys)) {
				foreach ($keys as $key) {
					cfr_register_metadata($key);
				}
			}
		}
	}
	
	static function request_handler() {
		if (isset($_POST['cf_action'])) {
			switch ($_POST['cf_action']) {
				case 'cfr_save_keys':
					if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'cfr_save_keys')) {
						wp_die('Oops, please try again.');
					}
					$keys = (isset($_POST['revision_manager_keys']) && is_array($_POST['revision_manager_keys'])) ? $_POST['revision_manager_keys'] : array();
					cf_revisions_admin::save_settings($keys);
					wp_redirect(admin_url('options-general.php?page='.basename(__FILE__)).'&cf_admin_notice=cfr-1');
					break;
			}
		}
	}
	
	static function save_settings($keys) {
		update_option('cf_revision_manager_meta_keys', (array) $keys);
	}
	
	static function admin_notices() {
		$notice = '';
		$class = 'updated';
		if (isset($_GET['cf_admin_notice'])) {
			switch ($_GET['cf_admin_notice']) {
				case 'cfr-1':
					$notice = 'Selected meta keys to be versioned have been updated.';
					break;
			}
		}
		if (!empty($notice)) {
			echo '<div id="message" class="'.$class.'"><p>'.$notice.'</p></div>
';
		}
	}
	
	static function admin_head() {
		if (!empty($_GET['page']) && $_GET['page'] == basename(__FILE__)) {
?>
<style type="text/css">
#cfr_revision_manager_form .cf-col {
	float: left;
	overflow: hidden;
	width: 33%;
}
#cfr_revision_manager_form .submit {
	clear: both;
}
#cfr_revision_manager_form .clearfix {
	clear: both;
}
</style>
<script type="text/javascript">
/**
 * ColumnizeLists
 * @version 0.1
 * @requires jQuery
 * Copyright 2010, Crowd Favorite
 *
 * Break lists into chunks so you can build top-to-bottom-left-to-right columned lists
 * Usage: $('ul.my-columnizer-class').columnizeLists({});
 */
;(function($) {

	$.fn.columnizeLists = function(args) {
		// Merge our default args and the user's args
		var args = $.extend({}, $.fn.columnizeLists.args, args);

		// Loop through every target
		this.each(function(){
			var $this = $(this);
			
			var cont = $this.parent();
			var items = $this.find('li');
			var items_count = items.size();
			
			if (args.preserveOriginalClass) {
				var originalClass = ' class="' + $this.attr('class') + '"';
			} else {
				var originalClass = '';
			};

			// If we have the column preference, figure out how many rows we should have, then do rows
			if (args.pref == 'cols') {
				var rem = items_count % args.cols;
				args.rows = Math.floor(items_count / args.cols);
				rem ? extra = 1 : extra = 0;
			}

			// Put a wrapper around our new divs we're creating
			cont.append('<div class="' + args.divWrapperClass + '"></div>');
			// Find classes as compound class selectors
			var div_wrapper = cont.find('.' + args.divWrapperClass.replace(' ', '.'));

			// Loop through each list item
			var i = 0;
			var col_num = 0;
			items.each(function() {
				// fancy-pants math to see if we should append an extra row till we have no remainder 
				(extra && col_num <= rem) ? row_count = args.rows + extra : row_count = args.rows;
				if (i % row_count == 0) {
					col_num++;
					i = 0;
					var colClasses = args.colClass.replace(' ', '-');
					cur_col = colClasses + '-' + col_num.toString();
					div_wrapper.append('<div class="' + args.colClass + ' ' + cur_col + '"><ul' + originalClass + '></ul></div>');
				}

				$(this).appendTo(div_wrapper.find('.' + cur_col + ' ul'));
				i++;
			});

			// Now add the container class, and remove the initial ul
			if (args.containerClass) {
				cont.addClass(args.containerClass)
			};
			cont.children("ul").remove();
		});
		
	};
	
	/**
	 * Default settings
	 */
	$.fn.columnizeLists.args = {
		pref: 'cols',
		rows: 10,
		cols: 4,
		containerClass: 'clearfix',
		colClass: 'cf-col',
		divWrapperClass: 'div-wrapper',
		preserveOriginalClass: false
	}
})(jQuery);
jQuery(function($) {
	$('#cfr_revision_manager_keys, #cfr_revision_manager_keys_required').columnizeLists({
		'cols': 3
	});
});
</script>
<?php
		}
	}
	
}
add_action('admin_menu', array('cf_revisions_admin', 'admin_menu'));
add_action('init', array('cf_revisions_admin', 'register_meta'), 999);
add_action('admin_init', array('cf_revisions_admin', 'request_handler'));
add_action('admin_notices', array('cf_revisions_admin', 'admin_notices'));
add_action('admin_head', array('cf_revisions_admin', 'admin_head'));

} // end double-load check

?>