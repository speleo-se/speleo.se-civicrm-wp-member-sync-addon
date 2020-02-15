<?php
use Civi\Api4\OptionValue;

/**
 * Plugin Name: Speleo.se CiviCRM addon
 */


class CiviCRMSpeleoSe {
	public function __construct() {
		// When the plugin has loaded initialize.
		add_action( 'plugins_loaded', [$this, 'initialize' ] );
	}

	/**
	 * Handles the initialization of the plugin. (Seems to be called on every page request)
	 */
	public function initialize() {
		// Setup a filter 'handler' for the 'civi_wp_member_sync_new_username'. 
		// The handler will be on 'this' class and be the function called 'create_wp_username_from_member_sync'
		// The handler will execute with 'priority' 10 and accept 2 arguments.

	    /**
	     * Handler for the filter 'civi_wp_member_sync_new_username'.
	     * Uses the $civi_contact argument to create a username with the first letter of the first name and the last name.
	     * @see Civi_WP_Member_Sync_Users
	     */
	    add_filter('civi_wp_member_sync_new_username', [$this, 'civi_wp_member_sync_new_username'], 10 , 2);

	    
	    
		/**
		 * Let other plugins know that we're about to sync all users.
		 * @see Civi_WP_Member_Sync_Admin
		 */
	    add_action('civi_wp_member_sync_pre_sync_all', [$this, 'civi_wp_member_sync_pre_sync_all'], 10 , 0);
		/**
		 * Let other plugins know that we've synced all users.
         * @see Civi_WP_Member_Sync_Admin 
		 */
	    add_action('civi_wp_member_sync_after_sync_all', [$this, 'civi_wp_member_sync_after_sync_all'], 10 , 0);

		
		
		/**
		 * Let other plugins know that we've inserted a user.
		 * @see Civi_WP_Member_Sync_Users
		 */
	    add_action('civi_wp_member_sync_after_insert_user', [$this, 'civi_wp_member_sync_after_insert_user'], 10, 2);
		
		/**
		 * Broadcast the membership update.
		 * @see Civi_WP_Member_Sync_Members
		 */
	    add_action('civi_wp_member_sync_membership_updated', [$this, 'civi_wp_member_sync_membership_updated'], 10, 4);
		
	}
	/**
	 * Handler for the filter 'civi_wp_member_sync_new_username'.
	 * Uses the $civi_contact argument to create a username with the first letter of the first name and the last name.
	 *
	 * @param string $user_name The previously-generated WordPress username
	 * @param array $civi_contact The CiviCRM contact data
	 * @return string $user_name The modified WordPress username
	 */
	public function civi_wp_member_sync_new_username($user_name, $civi_contact) {
		$contact_user_name = $civi_contact['nick_name'];
		
		// $contact_user_name = sanitize_title( sanitize_user( $contact_user_name ) );
		return $contact_user_name;
	}

	
	
	
	private $ongoing_sync_all = false;
	public function civi_wp_member_sync_pre_sync_all() {
	    $this->ongoing_sync_all = true;
	}
	public function civi_wp_member_sync_after_sync_all() {
	    $this->ongoing_sync_all = false;
	}
	
	
	/**
	 * Member Sync: Let other plugins know that we've inserted a user.
	 * Create an activity on the user. 
	 * @param array $civi_contact
	 * @param int $user_id
	 */
	public function civi_wp_member_sync_after_insert_user($civi_contact, $user_id ) {
	    $wpUser = get_user_by('id', $user_id);
	    // Oklart om man kan göra såhär. Massa logik i WP_User...
	    $wpUser->data->user_pass = '*****';
	    \Civi\Api4\Activity::create()
        	    ->addValue('source_contact_id', $civi_contact['id'])
        	    ->addValue('activity_type_id', $this->getActivityTypeIdSyncUser())
        	    ->addValue('subject', "Wordpress-användare '".$wpUser->user_login."' skapad")
        	    ->addValue('details', ($this->ongoing_sync_all ? '<strong>Sync all!</strong>' : '').'<pr>En Wordpress-användare har skapats. <br><pre>'.htmlentities(print_r($wpUser, true)).'</pre>')
        	    ->addValue('activity_date_time', date("Y-m-d H:i:s"))
        	    ->addValue('status_id', 2) // Slutförd
        	    ->addValue('priority_id', 2) // Normal
        	    ->setCheckPermissions(FALSE)
        	    ->execute();
    }

    
    /**
     * Member Sync: Broadcast the membership update.
     * @param string $op The type of operation.
     * @param WP_User $user The WordPress user object.
     * @param object $objectRef The CiviCRM membership being updated.
     * @param object $previous_membership The previous CiviCRM membership if this is a renewal.
     */
    public function civi_wp_member_sync_membership_updated($op, $wpUser, $membership, $previous_membership ) {
        // Oklart om man kan göra såhär. Massa logik i WP_User...
        $wpUser->data->user_pass = '*****';
        \Civi\Api4\Activity::create()
        ->addValue('source_contact_id', $membership->contact_id)
        ->addValue('activity_type_id', $this->getActivityTypeIdSyncUser())
        ->addValue('subject', "Wordpress-användare '".$wpUser->user_login."' uppdaterad")
        ->addValue('details',
            ($this->ongoing_sync_all ? '<strong>Sync all!</strong>' : '')
            .'Operation: '.$op.'<br>'
            .'<pr>En Wordpress-användare har ändrat medelemsstatus. <br>'
            .'<pre>'.htmlentities(print_r($wpUser, true)).'</pre>'
            .'Medlemskap: '
            .'<pre>'.htmlentities(print_r($membership, true)).'</pre>'
            )
        ->addValue('activity_date_time', date("Y-m-d H:i:s"))
        ->addValue('status_id', 2) // Slutförd
        ->addValue('priority_id', 0) // Normal
        ->setCheckPermissions(FALSE)
        ->execute();
    }

    private function getActivityTypeIdSyncUser() {
        return $this->getActivityTypeId(
            'Syncning av användare'
            , 'Syncningshändelser mellan CiviCRM-kontakter och Wordpress-användare för inloggning på hemsidan.'
            , 'fa-refresh'
            );
    }
    
    private $cachedActivityTypeId = [];
    /**
     * 
     * @param string $label
     * @param string $description
     * @return int
     */
    private function getActivityTypeId($label, $description = null, $icon = null) {
        if (!isset($this->cachedActivityTypeId[$label])) {
            $optionValue =
                    \Civi\Api4\OptionValue::get()
                            ->setSelect(['value'])
                            ->addWhere('label', '=', $label)
                            ->setLimit(1)
                            ->setCheckPermissions(FALSE)
                            ->execute()
                            ->first()
                    ?? \Civi\Api4\OptionValue::create()
                            ->addValue('option_group_id', 2)
                            ->addValue('label', $label)
                            ->addValue('is_active', 1)
                            ->addValue('description', $description)
                            ->addValue('icon', $icon)
                            ->setCheckPermissions(FALSE)
                            ->execute()
                            ->first();
            $this->cachedActivityTypeId[$label] = $optionValue['value'];
        }
        
        return $this->cachedActivityTypeId[$label];
    }
} // class ends

// init plugin
new CiviCRMSpeleoSe();
