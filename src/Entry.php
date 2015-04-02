<?php

/**
 * Title: WordPress pay extension Gravity Forms entry
 * Description:
 * Copyright: Copyright (c) 2005 - 2015
 * Company: Pronamic
 * @author Remco Tolsma
 * @version 1.0.0
 */
class Pronamic_WP_Pay_Extensions_GravityForms_Entry {
	/**
	 * Check if the specified entry payment is approved
	 *
	 * @param array $entry
	 * @return boolean true if payment is approvied, false otherwise
	 */
	public static function is_payment_approved( array $entry ) {
		$approved = false;

		if ( isset( $entry[ Pronamic_WP_Pay_Extensions_GravityForms_LeadProperties::PAYMENT_STATUS ] ) ) {
			$payment_status = $entry[ Pronamic_WP_Pay_Extensions_GravityForms_LeadProperties::PAYMENT_STATUS ];

			$approved = in_array( $payment_status, array(
				Pronamic_WP_Pay_Extensions_GravityForms_PaymentStatuses::APPROVED,
				Pronamic_WP_Pay_Extensions_GravityForms_PaymentStatuses::PAID,
			) );
		}

		return $approved;
	}
}
