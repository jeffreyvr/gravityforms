<?php

namespace Pronamic\WordPress\Pay\Extensions\GravityForms;

/**
 * Title: WordPress pay extension Gravity Forms lead properties
 * Description:
 * Copyright: Copyright (c) 2005 - 2018
 * Company: Pronamic
 *
 * @author  Remco Tolsma
 * @version 2.0.0
 * @since   1.0.0
 */
class LeadProperties {
	/**
	 * Lead propery payment status
	 *
	 * @var string
	 */
	const PAYMENT_STATUS = 'payment_status';

	/**
	 * Lead propery payment amount
	 *
	 * @var string
	 */
	const PAYMENT_AMOUNT = 'payment_amount';

	/**
	 * Lead propery payment date
	 *
	 * @var string
	 */
	const PAYMENT_DATE = 'payment_date';

	/**
	 * Lead propery transaction ID
	 *
	 * @var string
	 */
	const TRANSACTION_ID = 'transaction_id';

	/**
	 * Lead propery transaction type
	 *
	 * @var string
	 */
	const TRANSACTION_TYPE = 'transaction_type';

	/**
	 * Lead property created by
	 *
	 * @var string
	 */
	const CREATED_BY = 'created_by';
}
