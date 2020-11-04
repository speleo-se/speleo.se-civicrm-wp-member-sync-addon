<?php

/**
 * Plugin Name: Speleo.se CiviCRM Conifg
 * Description: Konfigurerar CiviCRM för Sveriges Speleologförbund. Medlemskap.
 *
 *
 * Läs: https://developer.wordpress.org/plugins/plugin-basics/header-requirements/
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class CiviCRMSpeleoSeConfig {
	private $sektioner = [];

	public function configureCiviCRM() {
		// Set default organisation:
		$org_ssf = \Civi\Api4\Contact::update()
				->addWhere('id', '=', 1)
				->addValue('organization_name', 'Sveriges Speleologförbund')
				->addValue('legal_name',        'Sveriges Speleologförbund SSF' )
				->addValue('nick_name',         'SSF' )
				->setLimit(1)
				->setCheckPermissions(FALSE)
				->execute()
				->first();

		foreach ( [ 
				'Dyksektionen',
				'Gruvsektionen',
				'Vertikala sektionen'
				] as $sektion ) {
			$this->sektioner [$sektion] = \Civi\Api4\Contact::get()
					->addWhere('contact_type', '=', 'Organization')
					->addWhere ( 'organization_name', 'LIKE', '%' . $sektion . '%' )
					->setLimit(1)
					->setCheckPermissions(FALSE)
					->execute()
					->first();
			if ($this->sektioner [$sektion] === null) {
				$this->sektioner [$sektion] = \Civi\Api4\Contact::create()
						->addValue('organization_name', 'SSF - '.$sektion)
						->addValue('legal_name',        'Sveriges Speleologförbund - '.$sektion)
						->addValue('nick_name',         $sektion)
						->setLimit(1)
						->setCheckPermissions(FALSE)
						->execute()
						->first();
			}
		}
		$calenderYearMemebership = [ 
				'duration_unit' => "year",
				'duration_interval' => 1,
				'period_type' => "fixed",
				"fixed_period_start_day" => "0101",
				'fixed_period_rollover_day' => "1201" // När blir registrerat medlemskap för nästa år?
		];
		
		//SSF-standard-medlemskap:
		$this->createMembershipType ( [ 
				'name' => "Vuxen",
				'description' => "Medlemskap under kalenderåret för person över 26 år.",
				'minimum_fee' => "300"
				] + $calenderYearMemebership );
		$this->createMembershipType ( [ 
				'name' => "Student",
				'description' => "Medlemskap under kalenderåret för person under 26 år.",
				'minimum_fee' => "150"
		] + $calenderYearMemebership );
		$this->createMembershipType ( [ 
				'name' => "Familj",
				'description' => "Medlemskap under kalenderåret för person i samma hushåll.",
				'minimum_fee' => "350"
		] + $calenderYearMemebership );
		
		// Hedersmedlem
		$this->createMembershipType ( [ 
				'name' => "Hedersmedlem",
				'description' => "Hedersmedlemskap livet ut.",
				'minimum_fee' => "0",
				"visibility" => "Admin",
	
				'duration_unit' => "lifetime",
				'duration_interval' => 1,
				'period_type' => "rolling"
		] );

		// Sektioner
		foreach ( [
				'Dyksektionen' => 45,
				'Gruvsektionen' => 15,
				'Vertikala sektionen' => 15.
				] as $sektion => $medlemsavgift ) {
			$this->sektioner[$sektion] = \Civi\Api4\Contact::get()
			->addWhere('contact_type', '=', 'Organization')
			->addWhere ( 'organization_name', 'LIKE', '%' . $sektion . '%' )
			->setLimit(1)
			->setCheckPermissions(FALSE)
			->execute()
			->first();
			if ($this->sektioner [$sektion] === null) {
				$this->sektioner [$sektion] = \Civi\Api4\Contact::create()
				->addValue('organization_name', 'SSF - '.$sektion)
				->addValue('legal_name',        'Sveriges Speleologförbund - '.$sektion)
				->addValue('nick_name',         $sektion)
				->setLimit(1)
				->setCheckPermissions(FALSE)
				->execute()
				->first();
			}
			$this->createMembershipType ( [
					'name' => $sektion,
					'description' => "Medlem i ".$sektion.".",
					'minimum_fee' => $medlemsavgift,
					'member_of_contact_id' => $this->sektioner[$sektion]['id'],
					] + $calenderYearMemebership );
			
		}
	}
	private function createMembershipType(Array $membershipTypeOptions) {
		$result = civicrm_api3('MembershipType', 'get', [
				'sequential' => 1,
				'name' => $membershipTypeOptions['name'],
				]);
		if (!$result['values']) {
			$mergedMembershipTypeOptions = 
					$membershipTypeOptions
					+ [
							'member_of_contact_id' => 1, //SSF
							'is_active' => 1,
							"visibility" => "Public",
							'minimum_fee' => "0",
							"financial_type_id" => "2", // ("Member Dues))
					];
			$result = civicrm_api3('MembershipType', 'create', $mergedMembershipTypeOptions);
		}
	}
}

// plugin activation
register_activation_hook( __FILE__, [ new CiviCRMSpeleoSeConfig(), 'configureCiviCRM' ] );
