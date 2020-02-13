<?php
/**
 * Plugin Name: Speleo.se CiviCRM addon
 */


class CiviCRMSpeleoSe {
	public function __construct() {
		// When the plugin has loaded initialize.
		add_action( 'plugins_loaded', [ $this, 'initialize' } );
	}

	/**
	 * Handles the initialization of the plugin. (Seems to be called on every page request)
	 */
	public function initialize() {
		// Setup a filter 'handler' for the 'civi_wp_member_sync_new_username'. 
		// The handler will be on 'this' class and be the function called 'create_wp_username_from_member_sync'
		// The handler will execute with 'priority' 10 and accept 2 arguments.
		add_filter('civi_wp_member_sync_new_username', array( $this, 'create_wp_username_from_member_sync'), 10 , 2);
	}
	
	/**
	 * Handler for the filter 'civi_wp_member_sync_new_username'.
	 * Uses the $civi_contact argument to create a username with the first letter of the first name and the last name.
	 *
	 * @param str $user_name The previously-generated WordPress username
	 * @param array $civi_contact The CiviCRM contact data
	 * @return str $user_name The modified WordPress username
	 */
	function create_wp_username_from_member_sync($user_name, $civi_contact) {
		$contact_user_name = $civi_contact['nick_name'];
		
		// $contact_user_name = sanitize_title( sanitize_user( $contact_user_name ) );
		return $contact_user_name;
	}
	
} // class ends

// init plugin
new CiviCRMSpeleoSe();
