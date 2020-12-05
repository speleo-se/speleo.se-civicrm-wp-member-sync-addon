<?php

/**
 * Plugin Name: Speleo.se CiviCRM Conifg
 * Description: Konfigurerar CiviCRM för Sveriges Speleologförbund med medlemskap. Körs när pluginen aktiveras. Kan sedan inaktiveras.
 *
 *
 * Läs: https://developer.wordpress.org/plugins/plugin-basics/header-requirements/
 */

// If this file is called directly, abort.
if (! defined ( 'WPINC' )) {
	die ();
}
class CiviCRMSpeleoSeConfig {
	protected $sektioner = [ ];
	protected $org_ssf;
	public function configureCiviCRM() {
		// Set default organisation:
		$this->org_ssf = \Civi\Api4\Contact::update ( false )
				->addWhere ( 'id', '=', 1 )
				->addValue ( 'organization_name', 'Sveriges Speleologförbund' )
				->addValue ( 'legal_name', 'Sveriges Speleologförbund SSF' )
				->addValue ( 'nick_name', 'SSF' )
				->execute ()
				->first ();
		
		$calenderYearMemebership = [ 
				'duration_unit' => "year",
				'duration_interval' => 1,
				'period_type' => "fixed",
				"fixed_period_start_day" => "0101",
				'fixed_period_rollover_day' => "1201" // När blir registrerat medlemskap för nästa år?
		];

		// SSF familjemedlemskapsrealtion
		$familjemedlemskapsrealtion = \Civi\Api4\RelationshipType::get ( false )
				->addWhere ( 'name_a_b', '=', 'huvudfamiljemedlem' )
				->setLimit ( 1 )
				->execute ()
				->first ()
				?? \Civi\Api4\RelationshipType::create ( true )
					->addValue ( 'name_a_b', 'huvudfamiljemedlem' )
					->addValue ( 'label_a_', 'familjemedlemskap betalas av' )
					->addValue ( 'name_b_a', 'familjemedlem' )
					->addValue ( 'label_b_a', 'betalar familjemedlemskap till' )
					->addValue ( 'description', 'SSF familjemedlemskapsrelation' )
					->addValue ( 'contact_type_a', 'Individual' )
					->addValue ( 'contact_type_b', 'Individual' )
					->addValue ( 'is_active', 1 )
					->execute ()
					->first ();

		// SSF-standard-medlemskap:
		$this->createMembershipType ( [ 
				'name' => "Vuxen",
				'description' => "Medlemskap under kalenderåret för person från 26 år.",
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
				'minimum_fee' => "350",

				'relationship_type_id' => $familjemedlemskapsrealtion ['id'],
				'relationship_direction' => 'b_a'
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
				'Gruvsektionen' => 15.,
				'Vertikala sektionen' => 15.
		] as $sektion => $medlemsavgift ) {
			$this->sektioner [$sektion] = \Civi\Api4\Contact::get ( FALSE )
					->addWhere ( 'contact_type', '=', 'Organization' )
					->addWhere ( 'organization_name', 'LIKE', '%' . $sektion . '%' )
					->setLimit ( 1 )
					->execute ()
					->first ()
					?? \Civi\Api4\Contact::create ( false )
						->addValue ( 'organization_name', 'SSF - ' . $sektion )
						->addValue ( 'legal_name', 'Sveriges Speleologförbund - ' . $sektion )
						->addValue ( 'nick_name', $sektion )
						->execute ()
						->first ();
			$this->createMembershipType ( [ 
					'name' => $sektion,
					'description' => "Medlem i " . $sektion . ".",
					'minimum_fee' => $medlemsavgift,
					'member_of_contact_id' => $this->sektioner [$sektion] ['id']
			] + $calenderYearMemebership );
		}
		// ########################################
		// ### Dölj data som SSF inte använder: ###
		// ########################################

		// Dölj relationer som inte börjar på SFF i description:
		$relationshipTypes = \Civi\Api4\RelationshipType::update ( false )
				->addWhere ( 'description', 'NOT LIKE', 'SSF%' )
				->addValue ( 'is_active', FALSE )
				->execute ();
		
		// Allmäna inställningar:
		foreach ( [
						'defaultCurrency' => 'SEK',
				] as $key => $value ) {
			\Civi\Api4\Setting::set(false)
					->addValue($key, $value)
					->execute();
		}
	}
	protected function createMembershipType(Array $membershipTypeOptions) {
		$result = \Civi\Api4\MembershipType::get ( FALSE )
				->addWhere ( 'name', '=', $membershipTypeOptions ['name'] )
				->setLimit ( 1 )
				->execute ()
				->first ();

		// $result = civicrm_api3('MembershipType', 'get', [
		// 'sequential' => 1,
		// 'name' => $membershipTypeOptions['name'],
		// ]);
		// if (!$result['values']) {
		if (! $result) {
			$mergedMembershipTypeOptions = $membershipTypeOptions + [ 
					'member_of_contact_id' => 1, // SSF
					'is_active' => 1,
					"visibility" => "Public",
					'minimum_fee' => 0,
					"financial_type_id" => 2 // ("Member Dues))
			];

			// $result = civicrm_api3('MembershipType', 'create', $mergedMembershipTypeOptions);
			$query = \Civi\Api4\MembershipType::create ( false );
			foreach ( $mergedMembershipTypeOptions as $fieldName => $mergedMembershipTypeOption ) {
				$query->addValue ( $fieldName, $mergedMembershipTypeOption );
			}
			$query->execute ()
					->first ();
		}
	}
}

// plugin activation
register_activation_hook ( __FILE__, [ 
		new CiviCRMSpeleoSeConfig (),
		'configureCiviCRM'
] );
