<?php
/**
 * Plugin Name: AIB
 * Description: Your own anonymous image board
 * Version: 4.0
 * Author: Aimbox
 * Author URI: http://aimbox.com/
 * License: GPLv2
 */
if (!defined('ABSPATH')) {
	exit;
}// using wordpress class naming conventions (Pretty_Class_Name)

class Anonymous_Image_Board {

	//private $shortcode = 'aib';
	private $post_type = 'aib_thread';
	private $taxonomy = 'aib_board';
	private $cookie = 'aib';
	private $admin_page_title = 'Configure Anonymous Image Board Plugin';
	private $admin_menu_title = 'Settings';
	private $required_capabilities = 'manage_options';
	private $admin_menu_slug = 'aib-settings-page';
	public $options;
	private $option_group = 'anonymous-image-board';
	private $option_name = 'aib-settings';
	private $settings_section_id = 'aib-settings';
	private $settings_section_title = '';
	private $page_id_setting_key = 'page_id';
	private $op_setting_key = 'op';
	private $random_setting_key = 'random';
	private $webm_setting_key = 'webm';
	private $recaptcha_open_setting_key = 'recaptcha_open_setting_key';
	private $recaptcha_secret_setting_key = 'recaptcha_secret_setting_key';
	private $aib_submit = 'aib-submit';
	private $aib_reset_templates = 'aib-reset-templates';
	private $flush_rewrites_transient = 'aib-flush-rewrites-transient';
	private $default_slug = 'aib';
	private $deletion_context = array();
	private $deletion_context_transient_key = 'aib-deletion-context-transient';
	
	public function __construct() {
		$this->load_options();
		add_action('init', array($this, 'initialize'));
		// activation hook needs to be set here, not on 'init' action
		// this however needs to be confirmed and verified by looking into official documentation
		register_activation_hook(__FILE__, array($this, 'activate_plugin'));
		register_deactivation_hook (__FILE__, array($this, 'deactivate_plugin'));
	}
		// TODO: Check whether it's being used and useful in general
	public function __get($name) {
		return $this->$name;
	}
	
	public function initialize() {
		// should be removed in the future, decided to not use shortcodes
		//add_shortcode($this->shortcode, array($this, 'expand_shortcode'));
		
		add_action('wp', array($this, 'redirect_to_thread_root'));
		
		add_filter('login_redirect',  array($this, 'moderator_login_redirect'), 10, 3 );
	
		
		// should be removed in the future, decided to not show board list on this page
		//add_filter('the_content', array($this, 'filter_main_page_content'));
		
		$this->register_aib_post_type();
		$this->register_aib_taxonomy();
		$this->register_aib_moderator_role();
		
		add_filter('post_type_link', array($this, 'set_aib_post_permalink'), 10, 4);
		add_filter('term_link', array($this, 'set_aib_board_permalink'), 10, 3);
		
		// we need to add rewrite rules on every load, but do not call expensive flush_rewrite_rules
		// these rules are automatically flushed when user is visiting Settings -> Permalinks
		// or we can call flush_rewrite_rules when specific events occur like plugin activation or settings change
		$this->add_rewrite_rules();
		if (get_transient($this->flush_rewrites_transient)) {
			flush_rewrite_rules();
			set_transient($this->flush_rewrites_transient, false);
		}
		
		add_action("update_option_{$this->option_name}", array($this, 'settings_updated'), 10, 2);
		
		// TODO: remove these lines and corresponding functions on plugin release
		// we need this just for debugging files inside template directory, not theme
		//add_filter('single_template', array($this, 'replace_single_template'));
		//add_filter('archive_template', array($this, 'replace_archive_template'));
		
		if (is_admin()) {
			add_action('admin_init', array($this, 'register_settings'));
			add_action('admin_menu', array($this, 'register_settings_page'));
			add_filter("bulk_actions-edit-{$this->post_type}", array($this, 'remove_unwanted_bulk_actions'));
			add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
			add_action('page_row_actions', array($this, 'page_row_actions'), 10, 2);
		}
		
		add_action('wp_enqueue_scripts', array($this, 'add_scripts'));
		
		add_filter('query_vars', array($this, 'add_query_vars'));
		
		add_filter('wp_title', array($this, 'change_meta_title'), 10, 3);
		
		add_action('trashed_post', array($this, 'trashed_post'));
		add_action('before_delete_post', array($this, 'before_delete_post'));
		
		add_filter('bulk_post_updated_messages', array($this, 'change_default_messages'), 10, 2);
		
		add_action('wp_ajax_delete_aib_post', array($this, 'ajax_delete_aib_post'));
 		add_action('wp_ajax_nopriv_delete_aib_post', array($this, 'ajax_delete_aib_post'));
		
		add_action('wp_ajax_draft_aib_post', array($this, 'ajax_draft_aib_post'));
		
		$this->set_impersonation_cookie();
		
		$this->maybe_process_post_data();
	}
	
	public function moderator_login_redirect( $url, $request, $user ){
		if( $user && is_object( $user ) && is_a( $user, 'WP_User' ) ) {
			if( $user->has_cap( 'aib_moderator' ) ) {
				$url = home_url();
			}
		}
		return $url;
	}
	
	public function redirect_to_thread_root($wp) {
		if (is_singular($this->post_type)) {
			$post = get_queried_object();
			if ($post->post_parent) {
				$location = get_permalink($post->post_parent);
				
				header("HTTP/1.1 301 Moved Permanently");
				header("Location: $location");
				exit();
			}
		}
	}
	
	public function activate_plugin() {
		global $wpdb;
		set_transient($this->flush_rewrites_transient, true);
		
		$mappings = $this->get_template_files_mapping();
		foreach ($mappings as $source => $target) {
			if (!file_exists($target)) {
				copy($source, $target);
			}
		}
		//for files md5
		$row = $wpdb->get_results(  "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
		WHERE table_name = '{$wpdb->posts}' AND column_name = 'md5_hash'"  );

		if(empty($row)){
		   $wpdb->query("ALTER TABLE {$wpdb->posts} ADD md5_hash VARCHAR(64) NOT NULL");
		   $wpdb->query("CREATE UNIQUE INDEX md5_hash ON {$wpdb->posts} (md5_hash)");
		}
	}
	
	public function deactivate_plugin(){
		global $wpdb;
		$wpdb->query("ALTER TABLE ".$wpdb->prefix."posts DROP COLUMN md5_hash");
	}
	
	private function get_template_files_mapping() {
		$target_dir = get_template_directory();
		$source_dir = plugin_dir_path(__FILE__) . 'templates';
		return array(
			"{$source_dir}/{$this->post_type}.sample.php" => "{$target_dir}/single-{$this->post_type}.php",
			"{$source_dir}/{$this->taxonomy}.sample.php" => "{$target_dir}/taxonomy-{$this->taxonomy}.php",
			"{$source_dir}/aib.sample.css" => "{$target_dir}/aib.css",
		);
	}
	
	public function validate() {
		$title = isset($_POST['aib-subject']) ? trim($_POST['aib-subject']) : '';
		$content = isset($_POST['aib-comment']) ? trim($_POST['aib-comment']) : '';
		
		$random = isset($_POST['aib-random']) ? trim($_POST['aib-random']) : '';
		$file = isset($_FILES['aib-attachment']) ? $_FILES['aib-attachment']['name'] : '';
		
		
		
		return $title || $content || $file || $random || !isset($_POST[$this->aib_submit]);
	}
	
	private function maybe_process_post_data() {
		global $wpdb;
		if (isset($_POST[$this->aib_submit]) && $this->validate_captcha()) {
			if ($this->validate()) {
				// TODO: introduce input name variables like for submit
				$post = array(
					'post_type'    => $this->post_type,
					'post_title'   => trim($_POST['aib-subject']), // wordpress sanitizes it for us
					'post_content' => trim($_POST['aib-comment']), // same as above
					'post_parent'  => intval($_POST['aib-parent']),
					'post_status'  => 'publish'
				);
				$post_id = wp_insert_post($post);
				if ($post_id) {
					add_post_meta($post_id, 'aib-impersonation-cookie', $_COOKIE[$this->cookie]);
				}
				$term_id = isset($_POST['aib-board']) ? intval($_POST['aib-board']) : 0;
				if ($term_id) {
					wp_set_post_terms($post_id, $term_id, $this->taxonomy);
				}
				require_once(ABSPATH . "wp-admin" . '/includes/image.php');
				require_once(ABSPATH . "wp-admin" . '/includes/file.php');
				require_once(ABSPATH . "wp-admin" . '/includes/media.php');
				$attachment_id = false;
				$allowed_types = array('image/gif', 'image/jpeg', 'image/png');
				
				
				//Image attachment
				
				if ($_FILES['aib-attachment']['error'] == UPLOAD_ERR_OK && in_array($_FILES['aib-attachment']['type'], $allowed_types)) {
					
					$attachment_id = $this->get_existing_file_or_upload($post_id);
					if($attachment_id){
						add_post_meta($post_id, '_thumbnail_id', $attachment_id);
						set_post_thumbnail($post_id, $attachment_id);
					}
					
				}
				
				//Video	attachment
				
				if ($_FILES['aib-attachment']['error'] == UPLOAD_ERR_OK 
				&& $_FILES['aib-attachment']['type'] == 'video/webm' 
				&& $this->webm_video_allowed()) {
					
					$video_id = media_handle_upload('aib-attachment', $post_id);
					add_post_meta($post_id, '_video_id', $video_id);
					
				}
				
			
				if(!$attachment_id && $random = $_POST['aib-random']){
				
					// If no image posted, but Random chosen, get random image
					$possible_randoms = $this->get_randoms();
					if(in_array($random, $possible_randoms)){
							$wpdb->show_errors();
							$sql = $wpdb->prepare("SELECT ID from $wpdb->posts WHERE post_type='attachment' AND post_excerpt=%s ORDER BY RAND() LIMIT 1", $random);
							$attachment = $wpdb->get_row($sql);
						
							$attachment_id = $attachment->ID;
							
							add_post_meta($post_id, '_thumbnail_id', $attachment_id);
							add_post_meta($post_id, '_random', $random);
							set_post_thumbnail($post_id, $attachment_id);
							
					}
					
				}
				
			
				$location = $post['post_parent'] ? "{$_SERVER['REQUEST_URI']}#aib{$post_id}" : get_permalink($post_id);
				header("HTTP/1.1 303 See Other");
				header("Location: $location");
				die();
			}
		}
		
		if (isset($_POST[$this->aib_reset_templates])) {
			$mappings = $this->get_template_files_mapping();
			foreach ($mappings as $source => $target) {
				copy($source, $target);
			}
			
			set_transient('aib-templates-reset', true);
			$location = add_query_arg('aib-templates-reset', 'true');
			header("HTTP/1.1 303 See Other");
			header("Location: $location");
			die();
		}
	}
	
	
	public function get_existing_file_or_upload($post_id){
		global $wpdb;
		$hash = md5_file($_FILES['aib-attachment']['tmp_name']);
		if(!$hash) return false;
		$query = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND md5_hash = %s";
		$id = $wpdb->get_var($wpdb->prepare($query, $hash));
		if(!$id){
			$id = media_handle_upload('aib-attachment', $post_id);
			$query = "UPDATE {$wpdb->posts} SET `md5_hash` = %s WHERE `ID` = %d";
			$res = $wpdb->query($wpdb->prepare($query, $hash, $id));
			if(!$res) return false;
		}
		return $id;
	}
	
	private function register_aib_post_type() {
		$labels = array(
			'name'          => __('AIB Posts'),
			'singular_name' => __('AIB Post'),
			'menu_name'     => __('AIB'),
			'all_items'     => __('All Posts')
		);
		register_post_type(
			$this->post_type,
			array(
				'labels'       => $labels,
				'public'       => true,
				'hierarchical' => true,
				'supports'     => array('title', 'editor', 'custom-fields', 'thumbnail'),
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-editor-textcolor',
				'query_var'    => false,
				'rewrite'      => false,
				'capabilities' => array(
					'edit_post' => 'edit_aib_thread',
					'edit_posts' => 'edit_aib_threads',
					'edit_others_posts' => 'edit_others_aib_threads',
					'publish_posts' => 'publish_aib_threads',
					'read_post' => 'read_aib_thread',
					'read_private_posts' => 'read_private_aib_threads',
					'delete_post' => 'delete_aib_thread'
				),
			)
		);
	}
	
	private function register_aib_taxonomy() {
		$labels = array(
			'name'              => __('AIB Boards'),
			'singular_name'     => __('AIB Board'),
			'search_items'      => __('Search AIB Boards'),
			'all_items'         => __('All AIB Boards'),
			'parent_item'       => __('Parent AIB Board'),
			'parent_item_colon' => __('Parent AIB Board:'),
			'edit_item'         => __('Edit AIB Board'),
			'update_item'       => __('Update AIB Board'),
			'add_new_item'      => __('Add New AIB Board'),
			'new_item_name'     => __('New AIB Board Name'),
			'menu_name'         => __('Boards'),
		);
		$args = array(
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => false,
			'rewrite'           => false,
		);
		register_taxonomy($this->taxonomy, $this->post_type, $args);
	}
	
	public function register_aib_moderator_role(){
		$admins = get_role( 'administrator' );

		$admins->add_cap( 'edit_aib_thread' );  
		$admins->add_cap( 'edit_aib_threads' );  
		$admins->add_cap( 'edit_others_aib_threads' ); 
		$admins->add_cap( 'publish_aib_threads' ); 
		$admins->add_cap( 'read_aib_thread' ); 
		$admins->add_cap( 'read_private_aib_threads' ); 
		$admins->add_cap( 'delete_aib_thread' );
		
		$capabilities =  array(
			'read_aib_thread'   => true, 
			'edit_aib_thread'   => false,
			'edit_aib_threads'   => false,
			'edit_others_aib_threads' => false,
			'publish_aib_threads' => false,
			'read_private_aib_threads' => false,
			'delete_aib_thread' => false, 
		);
				
		add_role( 'aib_moderator', 'AIB moderator', $capabilities );
	}
	
	public function set_aib_post_permalink($permalink, $post, $leavename, $sample) {
		global $wp_rewrite;
		if ($wp_rewrite->using_permalinks()) {
			if ($post->post_type == $this->post_type) {
				$permalink = user_trailingslashit(
					implode(
						'/',
						array(
							get_home_url(),
							$this->get_page_slug(),
							$post->ID
						)
					)
				);
			}
		}
		return $permalink;
	}
	
	public function set_aib_board_permalink($termlink, $term, $taxonomy) {
		global $wp_rewrite;
		if ($wp_rewrite->using_permalinks()) {
			if ($term->taxonomy == $this->taxonomy) {
				$termlink = user_trailingslashit(
					implode(
						'/',
						array(
							get_home_url(),
							$this->get_page_slug(),
							$term->slug
						)
					)
				);
			}
		}
		return $termlink;
	}
	
	public function settings_updated($old_value, $value) {
		$this->load_options();
		if ($value[$this->page_id_setting_key] != $old_value[$this->page_id_setting_key]) {
			$this->add_rewrite_rules();
			flush_rewrite_rules();
		}
	}
	
	private function add_rewrite_rules() {
		$slug = $this->get_page_slug();
		
		$regex = "$slug/([1-9][0-9]*)/?$";
		add_rewrite_rule($regex, 'index.php?post_type=' . $this->post_type . '&p=$matches[1]', 'top');
		
		$regex = "$slug/([1-9][0-9]*)/page/([1-9][0-9]*)/?$";
		add_rewrite_rule($regex, 'index.php?post_type=' . $this->post_type . '&p=$matches[1]&aib-page=$matches[2]', 'top');
		
		$regex = "$slug/(?![1-9][0-9]*/?$)([^/]+)/?$";
		add_rewrite_rule($regex, 'index.php?taxonomy=' . $this->taxonomy . '&term=$matches[1]', 'top');
		
		$regex = "$slug/(?![1-9][0-9]*/[1-9][0-9]*/?$)([^/]+)/page/([1-9][0-9]*)/?$";
		add_rewrite_rule($regex, 'index.php?taxonomy=' . $this->taxonomy . '&term=$matches[1]&aib-page=$matches[2]', 'top');
	}
	
	// should be removed in release version, we need this just for debugging proper file
	/*public function replace_single_template($template) {
		if (is_singular($this->post_type)) {
			$template = dirname(__FILE__) . "/templates/single-{$this->post_type}.sample.php";
		}
		return $template;
	}*/
	// should be removed in release version, we need this just for debugging proper file
	/*public function replace_archive_template($template) {
		if (is_tax($this->taxonomy)) {
			$template = dirname(__FILE__) . "/templates/taxonomy-{$this->taxonomy}.sample.php";
		}
		return $template;
	}*/
	
	public function register_settings() {
		register_setting(
			$this->option_group,
			$this->option_name,
			array($this, 'sanitize_settings')
		);
		
		add_settings_section(
			$this->settings_section_id,
			$this->settings_section_title,
			array($this, 'render_settings_section_header'),
			$this->admin_menu_slug
		);
		
		add_settings_field(
			$this->page_id_setting_key,
			__('AIB Page ID'),
			array($this, 'render_page_id_setting'),
			$this->admin_menu_slug,
			$this->settings_section_id
		);
		
		add_settings_field(
			$this->op_setting_key,
			__('Op String'),
			array($this, 'render_op_setting'),
			$this->admin_menu_slug,
			$this->settings_section_id
		);
		
		add_settings_field(
			$this->random_setting_key,
			__('Randoms'),
			array($this, 'render_random_setting'),
			$this->admin_menu_slug,
			$this->settings_section_id
		);
		
		add_settings_field(
			$this->webm_setting_key,
			__('Enable WebM support'),
			array($this, 'render_webm_setting'),
			$this->admin_menu_slug,
			$this->settings_section_id
		);
		
		
		add_settings_field(
			$this->recaptcha_open_setting_key,
			__('reCAPTCHA Open Key'),
			array($this, 'render_recaptcha_open_setting'),
			$this->admin_menu_slug,
			$this->settings_section_id
		);
		
		add_settings_field(
			$this->recaptcha_secret_setting_key,
			__('reCAPTCHA Secret Key'),
			array($this, 'render_recaptcha_secret_setting'),
			$this->admin_menu_slug,
			$this->settings_section_id
		);
	}
	
	public function register_settings_page() {
		add_submenu_page(
			"edit.php?post_type={$this->post_type}",
			$this->admin_page_title,
			$this->admin_menu_title,
			$this->required_capabilities,
			$this->admin_menu_slug,
			array($this, 'render_admin_settings_page')
		);
	}
	
	public function templates_reset_message() {
		if (isset($_GET['aib-templates-reset']) && get_transient('aib-templates-reset')) {
			set_transient('aib-templates-reset', false);
			add_settings_error('aib', 'aib-templates-reset', __('Templates reset'), 'updated');
		}
	}
	
	public function render_admin_settings_page() {
		$this->templates_reset_message();
		settings_errors();
		?>
		<div class="wrap">
			<h2><?php _e('Anonymous Image Board'); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields($this->option_group); ?>
				<?php do_settings_sections($this->admin_menu_slug); ?>
				<table class="form-table">
				<tbody><tr><th scope="row"><?php _e('Thumbnail Size'); ?></th>
				<td><a href="<?php echo admin_url('options-media.php'); ?>">
				<? echo get_option( 'thumbnail_size_w' ).' x '.get_option( 'thumbnail_size_h' )?></a>
				</td></tr>
				</tbody></table>
				<?php submit_button(); ?>
			</form>
			<form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
				<p>
					<?php submit_button('Reset Templates', 'delete', $this->aib_reset_templates, false); ?>
				</p>
				<p style="font-size: 12px;">
				</p>
			</form>
			
		</div>
		<?php
	}
	
	public function sanitize_settings($input) {
		return $input;
	}
	
	public function render_settings_section_header() {
		// just leaving this empty because we have only one section
	}
	
	private function render_input($id, $params = array()) {
		$name = $this->option_name . "[$id]";
		$value = isset($this->options[$id]) ? esc_attr($this->options[$id]) : '';
		$type = isset($params['type']) ? $params['type'] : 'text';
		$width = isset($params['width']) ? $params['width'] : '';
		$style = $width !='' ? "style='width: {$width};' " : '';
		
		if($params['type'] == 'textarea'){
		?>
		<textarea id="<?php echo $id; ?>" <?php echo $style; ?> name="<?php echo $name; ?>" ><?php echo $value; ?></textarea>
		<?
		}
		elseif($params['type'] == 'checkbox'){
		$checked = ($value == 1) ? 'checked="checked"' : "";
		?>
		<input type="<?php echo $type; ?>" id="<?php echo $id; ?>" <?php echo $checked; ?> name="<?php echo $name; ?>" value="1" <?php echo $style; ?>/> 
		<?php
		}else{
		?>
		<input type="<?php echo $type; ?>" id="<?php echo $id; ?>" name="<?php echo $name; ?>" value="<?php echo $value; ?>" <?php echo $style; ?>/>
		<?php
		}
		if ($params['note']) {
			$this->render_note($params['note']);
		}
	}
	
	private function render_note($note) {
		?>
		<p style="font-size: 12px;"><?php echo $note; ?></p>
		<?php
	}
	
	public function render_page_id_setting() {
		$this->render_input($this->page_id_setting_key, array('note' => ''));
	}
	
	public function render_op_setting() {
		$this->render_input($this->op_setting_key, array('note' => ''));
	}
	
	public function render_random_setting() {
		$this->render_input($this->random_setting_key, array('note' => __('Comma separated list'), 'type'=>'text', 'width'=>'80%'));
	}
	
	public function render_webm_setting() {
		$this->render_input($this->webm_setting_key, array('type'=>'checkbox'));
	}
	
	public function render_recaptcha_open_setting() {
		$this->render_input($this->recaptcha_open_setting_key, array('note' => __('Register and get keys here: https://www.google.com/recaptcha/'), 'type'=>'text', 'width'=>'80%'));
	}
	
	public function render_recaptcha_secret_setting() {
		$this->render_input($this->recaptcha_secret_setting_key, array( 'type'=>'text', 'width'=>'80%'));
	}
	
	private function set_impersonation_cookie() {
		if (!isset($_COOKIE[$this->cookie])) {
			setcookie(
				$this->cookie,
				// TODO: review cookie data
				hash_hmac('sha512', time(), wp_salt()),
				time() + 60 * 60 * 24 * 365,
				'/'
			);
		}
	}
	
	private function load_options() {
		$this->options = get_option($this->option_name);
		$this->apply_defaults($this->options);
	}
	
	private function apply_defaults(&$options) {
		/*
		$defaults = array(
			$this->post_template_setting_key => $this->get_default_post_template()
		);
		// TODO: replace with foreach
		$key = $this->post_template_setting_key;
		if (empty($options[$key])) {
			$options[$key] = $defaults[$key];
		}
		*/
	}
	
	public function add_scripts() {
		if (is_tax($this->taxonomy) || is_singular($this->post_type)) {
			wp_register_style('aib', get_template_directory_uri() . '/aib.css', array(), '1.3.7');
			wp_enqueue_style('aib');
			
			wp_register_script('aib-ajax', plugins_url('js/ajax.js', __FILE__), array('jquery'), '1.0.2');
			wp_enqueue_script('aib-ajax');
			
			wp_localize_script('aib-ajax', 'AibAjax', array(
				'ajaxUrl'      => admin_url('admin-ajax.php'),
				'nonce'        => wp_create_nonce('aib-ajax-nonce'),
				'confirmation' => __('Are you sure?')
			));
			
			wp_register_script('prettyphoto', plugins_url('prettyphoto/js/jquery.prettyPhoto.js', __FILE__), array('jquery'));
			wp_enqueue_script('prettyphoto');
			
			wp_register_style('prettyphoto', plugins_url('prettyphoto/css/prettyPhoto.css', __FILE__));
			wp_enqueue_style('prettyphoto');
			
			
			wp_register_script('recaptcha', 'https://www.google.com/recaptcha/api.js?hl=en');
			wp_enqueue_script('recaptcha');
			
			wp_enqueue_script('jquery-color');
		}
	}
	
	public function term_to_board_link($term) {
		$href = get_term_link($term);
		$title = $term->name;
		$text = $term->slug;
		return "<a href=\"$href\" title=\"$title\">$text</a>";
	}
	
	private function get_home_link() {
		$page_id = $this->options[$this->page_id_setting_key];
		$href = get_page_link($page_id);
		$title = get_the_title($page_id);
		$text = __('home');
		return "<a href=\"$href\" title=\"$title\">$text</a>";
	}
	
	public function aibize_link($link) {
		return "[&nbsp;$link&nbsp;]";
	}
	
	public function render_top_navigation() {
		$args = array(
			'orderby' => 'slug',
			'hide_empty' => false
		);
		$terms = get_terms($this->taxonomy, $args);
		$links = array_map(array($this, 'term_to_board_link'), $terms);
		array_unshift($links, $this->get_home_link());
		$links = array_map(array($this, 'aibize_link'), $links);
		?>
		<div class="aib-navigation">
			<?php echo implode(' ', $links); ?>
		</div>
		<?php
	}
	
	public function add_query_vars($vars) {
		$vars[] = 'aib-page';
		return $vars;
	}
	
	public function change_meta_title($title, $sep, $seplocation) {
		if (is_singular($this->post_type)) {
			$post = get_queried_object();
			$post_title = $post->post_title ? $post->post_title : "thread #{$post->ID}";
			$title = "$post_title - aib";
		}
		
		if (is_tax($this->taxonomy)) {
			$term = get_queried_object();
			$title = "{$term->name} - aib";
		}
		return $title;
	}
	
	
	public function trashed_post($post_id) {
		if (get_post_type($post_id) == $this->post_type) {
			remove_action('before_delete_post', array($this, 'before_delete_post'));
			// delete replies before thread because wp_delete_post changes post_parent for child posts
			$this->delete_children($post_id);
			$this->delete_post($post_id);
			$this->store_deletion_context();
			add_action('before_delete_post', array($this, 'before_delete_post'));
		}
	}
	
	public function before_delete_post($post_id) {
		if (get_post_type($post_id) == $this->post_type) {
			remove_action('before_delete_post', array($this, 'before_delete_post'));
			$this->delete_children($post_id);
			// we don't delete post here, because it will be deleted further by wordpress
			$this->add_to_deletion_context($post_id);
			$this->store_deletion_context();
			add_action('before_delete_post', array($this, 'before_delete_post'));
		}
	}
	
	private function add_to_deletion_context($post_id) {
		$post = get_post($post_id);
		if ($post) {
			if ($post->post_type == $this->post_type) {
				$key = $post->post_parent == 0 ? 'threads' : 'replies';
			}
		}
		
		if (isset($key)) {
			$this->deletion_context[$key][] = $post_id;
		}
	}
	
	private function delete_post($post_id) {
		$this->add_to_deletion_context($post_id);
		wp_delete_post($post_id, true);
	}
	
	private function delete_children($post_id) {
		$args = array(
			'numberposts' => -1,
			'post_type'   => $this->post_type,
			'post_parent' => $post_id
		);
		$children = get_posts($args);
		foreach ($children as $child) {
			$this->deletion_context['replies'][] = $child->ID;
			wp_delete_post($child->ID, true);
		}
	}
	
	private function store_deletion_context() {
		set_transient($this->deletion_context_transient_key, $this->deletion_context);
	}
	
	public function validate_captcha() {
		$result = true;
		$value = $_POST['g-recaptcha-response'];
		$ip = $_SERVER['REMOTE_ADDR'];
		$secret = $this->options['recaptcha_secret_setting_key'];
		
		$ch = curl_init("https://www.google.com/recaptcha/api/siteverify");
	
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, array('secret'=>$secret, 'response'=>$value, 'remoteip'=>$ip));
		
		$response = curl_exec($ch);
		curl_close($ch);
		
		if(!$response) return false; //Yeah, I'll probably burn in hell for this one
		
		$result = json_decode($response);
		return $result->success;
	}
	
	public function get_op() {
		$default = 'OP';
		if (array_key_exists($this->op_setting_key, $this->options)) {
			$op = $this->options[$this->op_setting_key];
			return $op ? $op : $default;
		} else {
			return $default;
		}
	}
	
	public function get_randoms() {
		$default = array();
		if (array_key_exists($this->random_setting_key, $this->options)) {
			$random = $this->options[$this->random_setting_key];
			if($random){
				$result = array();
				$arr = explode(',',$random);
				foreach($arr as $a){
					$result[] = trim($a);
				}
				return $result;
			}
			else return $default;
		} else {
			return $default;
		}
	}
	
	public function webm_video_allowed() {
		$default = false;
		if (array_key_exists($this->webm_setting_key, $this->options)) {
			$allowed = $this->options[$this->webm_setting_key];
			return $allowed ? $allowed : $default;
		} else {
			return $default;
		}
	}
	
	public function get_webm_icon_url(){
		return plugin_dir_url( __FILE__ )."img/webm-icon.png";
	}
	
	private function get_page_slug() {
		$page_id = intval($this->options[$this->page_id_setting_key]);
		if ($page_id) {
			$post = get_post($page_id);
			if ($post) {
				if ($post->post_name) {
					return $post->post_name;
				}
			}
		}
		return $this->default_slug;
	}
	
	public function get_reply_count($post_id) {
		global $wpdb;
		
		$cache_key = "{$this->post_type}_{$post_id}_reply_count";
		$count = wp_cache_get($cache_key, 'counts');
		if ($count !== false) {
			return $count;
		}
		
		$query = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d";
		$count = $wpdb->get_var($wpdb->prepare($query, $this->post_type, $post_id));
		
		wp_cache_set($cache_key, $count, 'counts');
		
		return $count;
	}
	
	public function change_default_messages($bulk_messages, $bulk_counts) {
		$deletion_context = get_transient($this->deletion_context_transient_key);
		if ($deletion_context !== false) {
			$threads = isset($deletion_context['threads']) ? count($deletion_context['threads']) : 0;
			if ($threads) {
				$parts[] = sprintf(_n('%s thread', '%s threads', $threads), $threads);
			}
			
			$replies = isset($deletion_context['replies']) ? count($deletion_context['replies']) : 0;
			if ($replies) {
				$parts[] = sprintf(_n('%s reply', '%s replies', $replies), $replies);
			}
			
			if ($parts) {
				$message = implode(sprintf(' %s ', __('with')), $parts) . ' ' . __('permanently deleted');
				$bulk_messages[$this->post_type] = array(
					'trashed' => $message,
					'deleted' => $message
				);
			}
			delete_transient($this->deletion_context_transient_key);
		}
		return $bulk_messages;
	}
	
	public function remove_unwanted_bulk_actions($actions) {
		unset($actions['trash']);
		// we don't allow aib threads go to trash
		//unset($actions['untrash']);
		//unset($actions['delete']);
		return $actions;
	}
	
	public function admin_enqueue_scripts($hook_suffix) {
		if ($hook_suffix == 'edit.php') {
			$post_type = isset($_GET['post_type']) ? $_GET['post_type'] : false;
			$trashed = isset($_GET['trashed']) ? $_GET['trashed'] : false;
			
			if ($post_type == $this->post_type && $trashed) {
				wp_register_style('aib-admin', plugins_url('admin/anonymous-image-board.css', __FILE__));
				wp_enqueue_style('aib-admin');
			}
		}
	}
	
	public function page_row_actions($actions, $post) {
		if ($post->post_type == $this->post_type) {
			if (isset($actions['trash'])) {
				$actions['trash'] = preg_replace('#(<a.*>)(.*)(</a>)#', sprintf('$1%s$3', __('Delete Permanently')), $actions['trash']);
			}
		}
		return $actions;
	}
	
	public function ajax_delete_aib_post() {
		header( "Content-Type: application/json" );
		
		if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'aib-ajax-nonce')) {
			die(json_encode(array(
				'success' => false,
				'message' => __('Invalid nonce')
			)));
		}
		
		$post_id = intval($_REQUEST['post_id']);
		if ($post_id == 0) {
			die(json_encode(array(
				'success' => false,
				'message' => __('Incorrect AIB post ID')
			)));
		}
		
		if (wp_trash_post($post_id)) {
			die(json_encode(array(
				'success' => true,
				'message' => __("AIB post #{$post_id} successfully deleted"),
				'id'  => $post_id
			)));
		} else {
			die(json_encode(array(
				'success' => false,
				'message' => __("Error deleting AIB post #{$post_id}")
			)));
		}
	}
	
	public function ajax_draft_aib_post() {
		header( "Content-Type: application/json" );
		
		$user = wp_get_current_user(); 
		if(!$user->has_cap( 'aib_moderator')){
			die(json_encode(array(
				'success' => false,
				'message' => __('You have no permission')
			)));
		}
		
		if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'aib-ajax-nonce')) {
			die(json_encode(array(
				'success' => false,
				'message' => __('Invalid nonce')
			)));
		}
		
		$post_id = intval($_REQUEST['post_id']);
		if ($post_id == 0) {
			die(json_encode(array(
				'success' => false,
				'message' => __('Incorrect AIB post ID')
			)));
		}
		
		$result = wp_update_post(array(
					'ID'=>$post_id,
					'post_status'=>'draft',
				));
		if ($result) {
			
			$args = array(
				'numberposts' => -1,
				'post_type'   => $this->post_type,
				'post_parent' => $post_id
			);
			
			$children = get_posts($args);
			foreach ($children as $child) {
				wp_update_post(array(
					'ID'=>$child->ID,
					'post_status'=>'draft',
				));
			}
			
			die(json_encode(array(
				'success' => true,
				'message' => __("AIB post #{$post_id} successfully deleted"),
				'id'  => $post_id
			)));
		} else {
			die(json_encode(array(
				'success' => false,
				'message' => __("Error deleting AIB post #{$post_id}")
			)));
		}
	}
	
	public function show_moderator_delete_link($post_id){
		$user = wp_get_current_user(); 
		if($user->has_cap( 'aib_moderator')){
			echo '<a href="#" data-id="'.$post_id.'" class="aib-moderator-delete-link"><i class="icon icon-remove"></i> Delete</a>';
		}
	}
	
	public function get_current_person() {
		if (isset($_COOKIE[$this->cookie])) {
			return $_COOKIE[$this->cookie];
		} else {
			return false;
		}
	}
	
	public function get_threads_sql(){
		global $wpdb;
		return "SELECT p.*,
			(SELECT child.post_date FROM $wpdb->posts child 
				WHERE child.post_parent = p.ID OR child.ID = p.ID
				AND child.post_type=%s
				ORDER BY child.post_date DESC LIMIT 0,1) latest_post
			FROM $wpdb->posts p
			INNER JOIN $wpdb->term_relationships tr ON tr.object_id = p.ID
			INNER JOIN $wpdb->term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN $wpdb->terms terms ON tt.term_id = terms.term_id
			WHERE p.post_type = %s
			AND p.post_status = 'publish' 
			AND tt.taxonomy = %s 
			AND terms.slug = %s
		    ORDER BY latest_post DESC, p.post_date DESC LIMIT %d, 50";
	}
}
$GLOBALS['aib'] = new Anonymous_Image_Board();
