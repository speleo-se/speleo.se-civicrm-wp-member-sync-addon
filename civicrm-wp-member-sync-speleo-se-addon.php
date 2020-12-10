<?php

/**
 * Plugin Name: Speleo.se CiviCRM addon
 * Description: Sveriges Speleologförbund med medlemskap.
 * 
 * Inspired by: https://github.com/christianwach/civicrm-wp-member-sync/issues/16#issuecomment-276852524
 * 
 */


// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

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
	     * skapa medlemsnummer och använd detta till användarnamn:
	     */
		add_filter('civi_wp_member_sync_new_username', [$this, 'civi_wp_member_sync_new_username'], 10 , 2);

		/**
		 * Sätta flagga att vi gör en syn all!
		 */
	    add_action('civi_wp_member_sync_pre_sync_all', [$this, 'civi_wp_member_sync_pre_sync_all'], 10 , 0);
		/**
		 * Ta bort flagga sync all.
		 */
	    add_action('civi_wp_member_sync_after_sync_all', [$this, 'civi_wp_member_sync_after_sync_all'], 10 , 0);

		
	    /**
	     * Broadcast that we've inserted a user.
	     *
	     * This action fires before the hooks are re-added which can be useful
	     * if callbacks perform actions that in some way update the WordPress
	     * User, for example sending emails via `wp_new_user_notification()`.
	     *
	     * @see wp_new_user_notification()
	     *
	     * @param array $civi_contact The CiviCRM contact object.
	     * @param int $user_id The numeric ID of the WordPress user.
	     */
	    add_action('civi_wp_member_sync_post_insert_user', [$this, 'civi_wp_member_sync_post_insert_user'], 10, 4);

	    
		/**
		 * Broadcast the membership update.
		 * @see Civi_WP_Member_Sync_Members
		 */
	    add_action('civi_wp_member_sync_membership_updated', [$this, 'civi_wp_member_sync_membership_updated'], 10, 4);
	    
	    /**
	     * Filters the contents of the new user notification email sent to the new user.
	     *
	     * @since 4.9.0
	     *
	     * @param array   $wp_new_user_notification_email {
	     *     Used to build wp_mail().
	     *
	     *     @type string $to      The intended recipient - New user email address.
	     *     @type string $subject The subject of the email.
	     *     @type string $message The body of the email.
	     *     @type string $headers The headers of the email.
	     * }
	     * @param WP_User $user     User object for new user.
	     * @param string  $blogname The site title.
	     */
	    add_filter('wp_new_user_notification_email', [$this, 'wp_new_user_notification_email'], 10 , 2);
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
		// Username för WordPress är medlemsnumret.
		// Om användaren inte fått något medlemsnummer så skapar vi det, genom att välja det högsta och lägga till ett.
		if (!$civi_contact['external_identifier']) {
			// Skapa ett nytt medlemsnummer!
			
			// Hämta högst som finns hittills:
			$contact = \Civi\Api4\Contact::get()
			->setSelect([
					'external_identifier',
			])
			// Om det av någon anledning blir så att det finns någon som har satts till medlemsnummer 9999999 eller liknande.
			// Så går det att lösa det med att lägga in filer här:
			// ->addWhere('external_identifier', '<', 9999999)
			->addOrderBy('external_identifier', 'DESC')
			->setLimit(1)
			->setCheckPermissions(FALSE)
			->execute()
			->first();
			
			// Nytt medlemsnummer, ett mer än det högsta som finns innan.
			$next_external_identifier = strval($contact['external_identifier'] + 1);
			
			$civi_contact = \Civi\Api4\Contact::update()
			->addWhere('id', '=', $civi_contact['id'])
			->addValue('external_identifier', $next_external_identifier)
			->setLimit(1)
			->setCheckPermissions(FALSE)
			->execute()
			->first();
			
			$this->addActivity(
					$civi_contact['id']
					, sprintf("Nytt medlemsnummer: %s", $next_external_identifier)
					, self::ACTIVITYTYPE_NEWMEMBERSHIPNR
					);
			
		}
		$contact_user_name = $civi_contact['external_identifier'];
		
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
	 * @see Civi_WP_Member_Sync_Members
	 * Broadcast that we've inserted a user.
	 * @see wp_new_user_notification()
	 *
	 * This action fires before the hooks are re-added which can be useful
	 * if callbacks perform actions that in some way update the WordPress
	 * User, for example sending emails via `wp_new_user_notification()`.
	 *
	 * @param array $civi_contact The CiviCRM contact object.
	 * @param int $user_id The numeric ID of the WordPress user.
	 *
	 */
	public function civi_wp_member_sync_post_insert_user($civi_contact, $user_id) {
		// Skicka ut ett välkomstmail till användaren:
		// TODO ändra till user eller both:
		// Aktivera vid release.
		$notify = 'admin';
		wp_new_user_notification($user_id, null, $notify);
		
		$wpUser = get_user_by('id', $user_id);
		$this->addActivity(
				$civi_contact['id']
				, sprintf("Mail skickat till '%s' för användare '%s'.", $wpUser->user_email, $wpUser->user_login)
				, self::ACTIVITYTYPE_WORDPRESS
				, 'Notify: '.$notify.'<br>En Wordpress-användare har skapats. <br><pre>'.htmlentities(print_r($wpUser, true)).'</pre>'
				);
		
	}
	
	/**
	 * Member Sync: Broadcast the membership update.
	 * 
	 * @param string $op The type of operation.
	 * @param WP_User $user The WordPress user object.
	 * @param object $objectRef The CiviCRM membership being updated.
	 * @param object $previous_membership The previous CiviCRM membership if this is a renewal.
	 */
	public function civi_wp_member_sync_membership_updated($op, $wpUser, $membership, $previous_membership ) {
		$this->addActivity(
				$membership->contact_id
				, sprintf("Wordpress-användare '%s' uppdaterad.", $wpUser->user_login)
				, self::ACTIVITYTYPE_SYNCUSER
				, 'Operation: '.$op.'<br>'
						.'<br>En Wordpress-användare har ändrat medelemsstatus. <br>'
						.'<pre>'.htmlentities(print_r($wpUser, true)).'</pre>'
						.'Medlemskap: '
						.'<pre>'.htmlentities(print_r($membership, true)).'</pre>'
				);
	}
	
	/**
     * Filters the contents of the new user notification email sent to the new user.
     *
     * @since 4.9.0
     *
     * @param array   $wp_new_user_notification_email {
     *     Used to build wp_mail().
     *
     *     @type string $to      The intended recipient - New user email address.
     *     @type string $subject The subject of the email.
     *     @type string $message The body of the email.
     *     @type string $headers The headers of the email.
     * }
     * @param WP_User $user     User object for new user.
     * @param string  $blogname The site title.
     */
	public function wp_new_user_notification_email( $wp_new_user_notification_email, $user, $blogname )  {
		// Ska vi lägga till mer info i välkomstmailen?
		
		return $wp_new_user_notification_email;
	}
	
	
	private const ACTIVITYTYPE_WORDPRESS = 'ActivityTypeWordpress';
	private const ACTIVITYTYPE_SYNCUSER = 'ActivityTypeSyncUser';
	private const ACTIVITYTYPE_NEWMEMBERSHIPNR = 'ActivityTypeNewMembershipNr';
	protected function addActivity($contactId, $subject, $type, $details = '') {
			\Civi\Api4\Activity::create(false)
			->addValue('source_contact_id', $contactId)
			->addValue('activity_type_id', $this->getActivityTypeId($type))
			->addValue('subject', $subject)
			->addValue('details',
					($this->ongoing_sync_all ? '<strong>Sync all batch.</strong>' : '')
					. $details)
			->addValue('activity_date_time', date("Y-m-d H:i:s"))
			->addValue('status_id', 2) // Slutförd
			->addValue('priority_id', 0) // Normal
			->setCheckPermissions(FALSE)
			->execute();
	}
	protected function getActivityTypeId($type) {
		switch ($type) {
			case self::ACTIVITYTYPE_WORDPRESS:
				return $this->getOrCreateActivityTypeId(
						'Välkomstmail från Wordpress'
						, 'Ett välkomstmail för inloggning till Wordpress skickas ut när användare skapas.'
						, 'fa-wordpress'
						);
			case self::ACTIVITYTYPE_SYNCUSER:
				return $this->getOrCreateActivityTypeId(
						'Syncning av användare'
						, 'Syncningshändelser mellan CiviCRM-kontakter och Wordpress-användare för inloggning på hemsidan.'
						, 'fa-refresh'
						);
			case self::ACTIVITYTYPE_NEWMEMBERSHIPNR:
				return $this->getOrCreateActivityTypeId(
						'Skapat medlemsnummer'
						, 'Vid synkning av Wordpress-användare skapas nytt medlemsnummer.'
						, 'fa-user-plus'
						);
		}
	}
	protected $cachedActivityTypeId = [];
	/**
     * Returns ActvityType with matching label. If none existing, creates a new!
     * Cachad. 
     * @param string $label
     * @param string $description
     * @return int
     */
    protected function getOrCreateActivityTypeId($label, $description = null, $icon = null) {
        if (!isset($this->cachedActivityTypeId[$label])) {
            $this->cachedActivityTypeId[$label] =
                    \Civi\Api4\OptionValue::get()
                            ->setSelect(['value'])
                            ->addWhere('option_group.name', '=', 'activity_type')
                            ->addWhere('label', '=', $label)
                            ->setLimit(1)
                            ->setCheckPermissions(FALSE)
                            ->execute()
                            ->first()['value']
                    // ::get()...->first() ger null om den inte finns, skapa och returnera då en ny aktivitetstyp: 
                    ?? \Civi\Api4\OptionGroup::get()
                            ->setSelect(['id'])
                            ->addWhere('name', '=', 'activity_type')
                            ->setLimit(1)
                            ->setChain([
                                'create_option_value' => [
                                    'OptionValue',
                                    'create',
                                    [
                                        'values' => [
                                            'option_group_id' => '$id', //id from OptionGroup::get()
                                            'label'           => $label,
                                            'is_active'       => 1,
                                            'description'     => $description,
                                            'icon'            => $icon
                                        ]
                                    ],
                                    0 // Samma som: ->first()
                                ],
                            ])
                            ->setCheckPermissions(FALSE)
                            ->execute()
                            ->first()['create_option_value']['value'];
        }
        return $this->cachedActivityTypeId[$label];
    }
} // class ends

// init plugin
new CiviCRMSpeleoSe();

// plugin activation
//register_activation_hook( __FILE__, array( $flying_gators, 'activate' ) );

