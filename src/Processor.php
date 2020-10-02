<?php
/**
 * Processor
 *
 * @author    Pronamic <info@pronamic.eu>
 * @copyright 2005-2020 Pronamic
 * @license   GPL-3.0-or-later
 * @package   Pronamic\WordPress\Pay\Extensions\GravityForms
 */

namespace Pronamic\WordPress\Pay\Extensions\GravityForms;

use GFCommon;
use Pronamic\WordPress\Money\Currency;
use Pronamic\WordPress\Money\TaxedMoney;
use Pronamic\WordPress\Pay\AbstractGatewayIntegration;
use Pronamic\WordPress\Pay\Address;
use Pronamic\WordPress\Pay\Banks\BankAccountDetails;
use Pronamic\WordPress\Pay\ContactName;
use Pronamic\WordPress\Pay\Customer;
use Pronamic\WordPress\Pay\Plugin;
use Pronamic\WordPress\Pay\Core\PaymentMethods;
use Pronamic\WordPress\Pay\Payments\Payment;
use Pronamic\WordPress\Pay\Payments\PaymentLines;
use Pronamic\WordPress\Pay\Payments\PaymentLineType;
use Pronamic\WordPress\Pay\Subscriptions\ProratingRule;
use Pronamic\WordPress\Pay\Subscriptions\Subscription;
use Pronamic\WordPress\Pay\Subscriptions\SubscriptionPhase;
use Pronamic\WordPress\Pay\Subscriptions\SubscriptionPhaseBuilder;

/**
 * Title: WordPress pay extension Gravity Forms processor
 * Description:
 * Copyright: 2005-2020 Pronamic
 * Company: Pronamic
 *
 * @author  Remco Tolsma
 * @version 2.4.1
 * @since   1.0.0
 */
class Processor {
	/**
	 * The Pronamic Pay Gravity Forms extension
	 *
	 * @var Extension
	 */
	private $extension;

	/**
	 * The Gravity Forms form
	 *
	 * @var array
	 */
	private $form;

	/**
	 * The Gravity Forms form ID
	 *
	 * @var null|string
	 */
	private $form_id;

	/**
	 * Payment feed
	 *
	 * @var null|PayFeed
	 */
	private $feed;

	/**
	 * Gateway
	 *
	 * @var null|AbstractGatewayIntegration
	 */
	private $gateway;

	/**
	 * Payment
	 *
	 * @var null|Payment
	 */
	private $payment;

	/**
	 * Error
	 *
	 * @var null|\Exception
	 */
	private $error;

	/**
	 * Constructs and initalize an Gravity Forms payment form processor
	 *
	 * @param array     $form      Gravity Forms form.
	 * @param Extension $extension Extension.
	 */
	public function __construct( array $form, Extension $extension ) {
		$this->extension = $extension;
		$this->form      = $form;
		$this->form_id   = isset( $form['id'] ) ? $form['id'] : null;

		// Determine payment feed for processing.
		$feeds = FeedsDB::get_active_feeds_by_form_id( $this->form_id );

		$entry = array();

		foreach ( $feeds as $feed ) {
			$gf_feed = $extension->addon->get_feed( $feed->id );

			if ( false === $gf_feed ) {
				continue;
			}

			if ( ! $extension->addon->is_feed_condition_met( $gf_feed, $form, $entry ) ) {
				continue;
			}

			$this->feed = $feed;

			$this->add_hooks();

			break;
		}
	}

	/**
	 * Add hooks.
	 */
	private function add_hooks() {
		/*
		 * Handle submission.
		 */

		// Lead.
		add_action( 'gform_entry_post_save', array( $this, 'entry_post_save' ), 10, 2 );

		// Delay (@see GFFormDisplay::handle_submission > GFCommon::send_form_submission_notifications).
		add_filter( 'gform_disable_admin_notification_' . $this->form_id, array( $this, 'maybe_delay_admin_notification' ), 10, 3 );
		add_filter( 'gform_disable_user_notification_' . $this->form_id, array( $this, 'maybe_delay_user_notification' ), 10, 3 );
		add_filter( 'gform_disable_post_creation_' . $this->form_id, array( $this, 'maybe_delay_post_creation' ), 10, 3 );
		add_filter( 'gform_disable_notification_' . $this->form_id, array( $this, 'maybe_delay_notification' ), 10, 4 );

		// Confirmation (@see GFFormDisplay::handle_confirmation).
		// @link http://www.gravityhelp.com/documentation/page/Gform_confirmation.
		add_filter( 'gform_confirmation_' . $this->form_id, array( $this, 'confirmation' ), 10, 4 );

		/*
		 * After submission.
		 */
		add_action( 'gform_after_submission_' . $this->form_id, array( $this, 'after_submission' ), 10, 2 );

		add_filter( 'gform_is_delayed_pre_process_feed_' . $this->form_id, array( $this, 'maybe_delay_feed' ), 10, 4 );
		add_filter( 'gravityflow_is_delayed_pre_process_workflow', array( $this, 'maybe_delay_workflow' ), 10, 3 );
	}

	/**
	 * Check if we are processing the passed in form.
	 *
	 * @param array $form Gravity Forms form.
	 *
	 * @return bool True if the passed in form is processed, false otherwise.
	 */
	public function is_processing( $form ) {
		$is_form = false;

		if ( isset( $form['id'] ) ) {
			$is_form = ( absint( $this->form_id ) === absint( $form['id'] ) );
		}

		$is_processing = ( null !== $this->feed ) && $is_form;

		return $is_processing;
	}

	/**
	 * Pre submission.
	 *
	 * @param array $form Gravity Forms form.
	 * @return void
	 */
	public function pre_submission( $form ) {
		if ( ! $this->is_processing( $form ) ) {
			return;
		}

		/*
		 * Delay actions.
		 *
		 * The Add-Ons mainly use the 'gform_after_submission' to export entries, to delay this we have to remove these
		 * actions before this filter executes.
		 *
		 * @link https://github.com/wp-premium/gravityforms/blob/1.8.16/form_display.php#L101-L103.
		 * @link https://github.com/wp-premium/gravityforms/blob/1.8.16/form_display.php#L111-L113.
		 */

		foreach ( $this->feed->delay_actions as $slug => $data ) {
			$delayed_payment_integration = ( isset( $data['delayed_payment_integration'] ) && true === $data['delayed_payment_integration'] );

			if ( isset( $data['addon'] ) && ! $delayed_payment_integration ) {
				remove_filter( 'gform_entry_post_save', array( $data['addon'], 'maybe_process_feed' ), 10 );
			}

			if ( isset( $data['delay_callback'] ) ) {
				call_user_func( $data['delay_callback'] );
			}
		}
	}

	/**
	 * Entry post save.
	 *
	 * @param array $lead Gravity Forms lead/entry.
	 * @param array $form Gravity Forms form.
	 *
	 * @return array
	 */
	public function entry_post_save( $lead, $form ) {
		if ( ! $this->is_processing( $form ) ) {
			return $lead;
		}

		// Check for payment ID.
		$payment_id = gform_get_meta( $lead['id'], 'pronamic_payment_id' );

		if ( ! empty( $payment_id ) ) {
			return $lead;
		}

		// Gateway.
		$this->gateway = Plugin::get_gateway( $this->feed->config_id );

		if ( ! $this->gateway ) {
			return $lead;
		}

		// Payment data.
		$data = new PaymentData( $form, $lead, $this->feed );

		// Payment.
		$payment = new Payment();

		$payment->title = sprintf(
			/* translators: %s: payment data title */
			__( 'Payment for %s', 'pronamic_ideal' ),
			$data->get_description()
		);

		$payment->config_id   = $this->feed->config_id;
		$payment->order_id    = $data->get_order_id();
		$payment->description = $data->get_description();
		$payment->method      = $data->get_payment_method();
		$payment->issuer      = $data->get_issuer_id();

		// Currency.
		$currency = Currency::get_instance( $data->get_currency_alphabetic_code() );

		// Source.
		$payment->set_source( 'gravityformsideal' );
		$payment->set_source_id( $lead['id'] );

		// Credit Card.
		$payment->set_credit_card( $data->get_credit_card() );

		// Name.
		$name = new ContactName();

		$name->set_prefix( $data->get_field_value( 'prefix_name' ) );
		$name->set_first_name( $data->get_field_value( 'first_name' ) );
		$name->set_middle_name( $data->get_field_value( 'middle_name' ) );
		$name->set_last_name( $data->get_field_value( 'last_name' ) );
		$name->set_suffix( $data->get_field_value( 'suffix_name' ) );

		// Customer.
		$customer = new Customer();

		$customer->set_name( $name );
		$customer->set_phone( $data->get_field_value( 'telephone_number' ) );
		$customer->set_email( $data->get_field_value( 'email' ) );

		$payment->set_customer( $customer );

		// Company Name.
		$company_name = $data->get_field_value( 'company_name' );

		$customer->set_company_name( $company_name );

		// VAT Number.
		$customer->set_vat_number( $data->get_field_value( 'vat_number' ) );

		// Address.
		$address = new Address();

		$country_name = $data->get_field_value( 'country' );
		$country_code = GFCommon::get_country_code( $country_name );

		if ( empty( $country_code ) ) {
			$country_code = null;
		}

		$address->set_name( $name );
		$address->set_company_name( $company_name );
		$address->set_line_1( $data->get_field_value( 'address1' ) );
		$address->set_line_2( $data->get_field_value( 'address2' ) );
		$address->set_postal_code( $data->get_field_value( 'zip' ) );
		$address->set_city( $data->get_field_value( 'city' ) );
		$address->set_region( $data->get_field_value( 'state' ) );
		$address->set_country_code( $country_code );
		$address->set_country_name( $country_name );
		$address->set_email( $data->get_field_value( 'email' ) );
		$address->set_phone( $data->get_field_value( 'telephone_number' ) );

		$payment->set_billing_address( $address );
		$payment->set_shipping_address( $address );

		// Consumer bank details.
		$consumer_bank_details = new BankAccountDetails();

		$consumer_bank_details->set_name( $data->get_field_value( 'consumer_bank_details_name' ) );
		$consumer_bank_details->set_iban( $data->get_field_value( 'consumer_bank_details_iban' ) );

		$payment->set_consumer_bank_details( $consumer_bank_details );

		// Lines.
		$payment->lines = new PaymentLines();

		$subscription_lines = new PaymentLines();

		if ( GravityForms::SUBSCRIPTION_AMOUNT_TOTAL === $this->feed->subscription_amount_type ) {
			$subscription_lines = $payment->lines;
		}

		$product_fields = GFCommon::get_product_fields( $form, $lead );

		if ( is_array( $product_fields ) ) {
			// Products.
			if ( array_key_exists( 'products', $product_fields ) && is_array( $product_fields['products'] ) ) {
				$products = $product_fields['products'];

				foreach ( $products as $key => $product ) {
					$key = \strval( $key );

					$product_lines = array();

					$line = $payment->lines->new_line();

					$product_lines[] = $line;

					$line->set_id( $key );

					if ( array_key_exists( 'name', $product ) ) {
						$line->set_name( $product['name'] );
					}

					if ( array_key_exists( 'price', $product ) ) {
						$value = GFCommon::to_number( $product['price'] );

						$line->set_unit_price( new TaxedMoney( $value, $currency ) );

						if ( array_key_exists( 'quantity', $product ) ) {
							$value = ( $value * intval( $product['quantity'] ) );
						}

						$line->set_total_amount( new TaxedMoney( $value, $currency ) );
					}

					if ( array_key_exists( 'quantity', $product ) ) {
						$line->set_quantity( intval( $product['quantity'] ) );
					}

					if ( array_key_exists( 'options', $product ) && is_array( $product['options'] ) ) {
						$options = $product['options'];

						foreach ( $options as $option ) {
							$line = $payment->lines->new_line();

							$product_lines[] = $line;

							$line->set_quantity( 1 );

							if ( array_key_exists( 'option_label', $option ) ) {
								$line->set_name( $option['option_label'] );
							}

							if ( array_key_exists( 'price', $option ) ) {
								$value = GFCommon::to_number( $option['price'] );

								$line->set_unit_price( new TaxedMoney( $value, $currency ) );

								$line->set_total_amount( new TaxedMoney( $value, $currency ) );
							}
						}
					}

					if (
						GravityForms::SUBSCRIPTION_AMOUNT_FIELD === $this->feed->subscription_amount_type
							&&
						$key === $this->feed->subscription_amount_field
					) {
						$subscription_lines = new PaymentLines();

						foreach ( $product_lines as $line ) {
							$subscription_lines->add_line( $line );
						}
					}
				}
			}

			// Shipping.
			if ( array_key_exists( 'shipping', $product_fields ) && is_array( $product_fields['shipping'] ) && array_key_exists( 'price', $product_fields['shipping'] ) && false !== $product_fields['shipping']['price'] ) {
				$shipping = $product_fields['shipping'];

				$line = $payment->lines->new_line();

				$line->set_type( PaymentLineType::SHIPPING );
				$line->set_quantity( 1 );

				if ( array_key_exists( 'id', $shipping ) ) {
					$line->set_id( $shipping['id'] );
				}

				if ( array_key_exists( 'name', $shipping ) ) {
					$line->set_name( $shipping['name'] );
				}

				if ( array_key_exists( 'price', $shipping ) ) {
					$value = $shipping['price'];

					$line->set_unit_price( new TaxedMoney( $value, $currency ) );

					$line->set_total_amount( new TaxedMoney( $value, $currency ) );
				}
			}
		}

		/**
		 * Donation.
		 *
		 * @todo Should we do something with 'donation'?
		 * @link https://github.com/wp-pay-extensions/gravityforms/blob/2.4.0/src/PaymentData.php#L231-L264
		 */

		// Does payment contain any lines?
		if ( 0 === count( $payment->lines ) ) {
			return $lead;
		}

		// Total amount.
		$payment->set_total_amount( $payment->lines->get_amount() );

		// Don't delay feed actions for free payments.
		$amount = $payment->get_total_amount()->get_value();

		if ( empty( $amount ) ) {
			$this->feed->delay_actions = array();
		}

		/**
		 * Subscription.
		 *
		 * As soon as a recurring amount is set, we create a subscription.
		 */
		$interval = $data->get_subscription_interval();

		if ( null !== $interval->value && $interval->value > 0 && $subscription_lines->get_amount()->get_value() > 0 ) {
			$payment->subscription_source_id = $lead['id'];

			$subscription = new Subscription();

			$subscription->lines = $subscription_lines;

			$subscription->set_total_amount( $subscription_lines->get_amount() );

			$start_date = new \DateTimeImmutable();

			$regular_phase = ( new SubscriptionPhaseBuilder() )
				->with_start_date( $start_date )
				->with_amount( $subscription_lines->get_amount() )
				->with_interval( 'P' . $interval->value . $interval->unit )
				->with_total_periods( $data->get_subscription_frequency() )
				->create();

			if ( 'sync' === $this->feed->subscription_interval_date_type ) {
				$proration_rule = new ProratingRule( $interval->unit );

				switch ( $interval->unit ) {
					case 'D':
						break;
					case 'W':
						$proration_rule->by_numeric_day_of_the_week( \intval( $this->feed->subscription_interval_date ) );
						break;
					case 'M':
						$proration_rule->by_numeric_day_of_the_month( \intval( $this->feed->subscription_interval_date_day ) );
						break;
					case 'Y':
						$proration_rule->by_numeric_day_of_the_month( \intval( $this->feed->subscription_interval_date_day ) );
						$proration_rule->by_numeric_month( \intval( $this->feed->subscription_interval_date_month ) );
				}

				$align_date = $proration_rule->get_date( $start_date );

				$proration_phase = SubscriptionPhase::prorate( $regular_phase, $align_date, ( '1' === $this->feed->subscription_interval_date_prorate ) );

				$subscription->add_phase( $proration_phase );
			}

			$subscription->add_phase( $regular_phase );

			$subscription->next_period();

			$payment->subscription = $subscription;
		}

		// Use iDEAL instead of 'Direct Debit (mandate via iDEAL)' without subscription.
		if ( null === $payment->get_subscription() && PaymentMethods::DIRECT_DEBIT_IDEAL === $payment->get_method() ) {
			$payment->method = PaymentMethods::IDEAL;
		}

		// Update entry meta.
		gform_update_meta( $lead['id'], 'ideal_feed_id', $this->feed->id );
		gform_update_meta( $lead['id'], 'payment_gateway', 'pronamic_pay' );

		$lead[ LeadProperties::PAYMENT_STATUS ]   = PaymentStatuses::PROCESSING;
		$lead[ LeadProperties::PAYMENT_DATE ]     = gmdate( 'y-m-d H:i:s' );
		$lead[ LeadProperties::TRANSACTION_TYPE ] = GravityForms::TRANSACTION_TYPE_PAYMENT;

		GravityForms::update_entry( $lead );

		// Start.
		try {
			$this->payment = Plugin::start_payment( $payment );
		} catch ( \Exception $e ) {
			$this->payment = $payment;

			$this->error = $e;
		}

		// Update entry meta.
		gform_update_meta( $lead['id'], 'pronamic_payment_id', $this->payment->get_id() );
		gform_update_meta( $lead['id'], 'pronamic_subscription_id', $this->payment->get_subscription_id() );

		$lead[ LeadProperties::PAYMENT_STATUS ] = PaymentStatuses::transform( $this->payment->get_status() );
		$lead[ LeadProperties::PAYMENT_AMOUNT ] = $this->payment->get_total_amount()->get_value();
		$lead[ LeadProperties::TRANSACTION_ID ] = $this->payment->get_transaction_id();

		GravityForms::update_entry( $lead );

		// Pending payment.
		if ( PaymentStatuses::PROCESSING === $lead[ LeadProperties::PAYMENT_STATUS ] ) {
			// Add pending payment.
			$action = array(
				'id'             => $this->payment->get_id(),
				'transaction_id' => $this->payment->get_transaction_id(),
				'amount'         => $this->payment->get_total_amount()->get_value(),
				'entry_id'       => $lead['id'],
			);

			$this->extension->payment_action( 'add_pending_payment', $lead, $action );
		}

		// Return lead.
		return $lead;
	}

	/**
	 * Maybe delay notifications.
	 *
	 * @param bool  $is_disabled  Is disabled flag.
	 * @param array $notification Gravity Forms notification.
	 * @param array $form         Gravity Forms form.
	 * @param array $lead         Gravity Forms lead/entry.
	 *
	 * @return bool
	 */
	public function maybe_delay_notification( $is_disabled, $notification, $form, $lead ) {
		if ( ! $is_disabled && $this->is_processing( $form ) ) {
			$is_disabled = in_array( $notification['id'], $this->feed->delay_notification_ids, true );
		}

		return $is_disabled;
	}

	/**
	 * Maybe delay admin notification
	 *
	 * @param bool  $is_disabled Is disabled flag.
	 * @param array $form        Gravity Forms form.
	 * @param array $lead        Gravity Forms lead/entry.
	 *
	 * @return boolean true if admin notification is disabled / delayed, false otherwise
	 */
	public function maybe_delay_admin_notification( $is_disabled, $form, $lead ) {
		if ( ! $is_disabled && $this->is_processing( $form ) ) {
			$is_disabled = $this->feed->delay_admin_notification;
		}

		return $is_disabled;
	}

	/**
	 * Maybe delay user notification
	 *
	 * @param bool  $is_disabled Is disabled flag.
	 * @param array $form        Gravity Forms form.
	 * @param array $lead        Gravity Forms lead/entry.
	 *
	 * @return boolean true if user notification is disabled / delayed, false otherwise
	 */
	public function maybe_delay_user_notification( $is_disabled, $form, $lead ) {
		if ( ! $is_disabled && $this->is_processing( $form ) ) {
			$is_disabled = $this->feed->delay_user_notification;
		}

		return $is_disabled;
	}

	/**
	 * Maybe delay post creation.
	 *
	 * @param boole $is_disabled Is disabled flag.
	 * @param array $form        Gravity Forms form.
	 * @param array $lead        Gravity Forms lead/entry.
	 *
	 * @return boolean true if post creation is disabled / delayed, false otherwise
	 */
	public function maybe_delay_post_creation( $is_disabled, $form, $lead ) {
		if ( ! $is_disabled && $this->is_processing( $form ) ) {
			$is_disabled = $this->feed->delay_post_creation;
		}

		return $is_disabled;
	}

	/**
	 * Maybe delay feed.
	 *
	 * @param bool   $is_delayed Is delayed flag.
	 * @param array  $form       Gravity Forms form.
	 * @param array  $entry      Gravity Forms entry.
	 * @param string $slug       Delay action slug.
	 *
	 * @return bool
	 */
	public function maybe_delay_feed( $is_delayed, $form, $entry, $slug ) {
		if ( $this->is_processing( $form ) && isset( $this->feed->delay_actions[ $slug ] ) ) {
			$is_delayed = true;
		}

		return $is_delayed;
	}

	/**
	 * Maybe delay workflow.
	 *
	 * @link https://github.com/gravityflow/gravityflow/blob/master/class-gravity-flow.php#L4711-L4720
	 *
	 * @param bool  $is_delayed Indicates if processing of the workflow should be delayed.
	 * @param array $entry      Gravity Forms entry.
	 * @param array $form       Gravity Forms form.
	 *
	 * @return bool
	 */
	public function maybe_delay_workflow( $is_delayed, $entry, $form ) {
		return $this->maybe_delay_feed( $is_delayed, $form, $entry, 'gravityflow' );
	}

	/**
	 * Confirmation.
	 *
	 * @link http://www.gravityhelp.com/documentation/page/Gform_confirmation
	 *
	 * @param array $confirmation Gravity Forms confirmation.
	 * @param array $form         Gravity Forms form.
	 * @param array $lead         Gravity Forms lead/entry.
	 * @param bool  $ajax         AJAX request flag.
	 *
	 * @return array|string
	 */
	public function confirmation( $confirmation, $form, $lead, $ajax ) {
		if ( ! $this->is_processing( $form ) ) {
			return $confirmation;
		}

		if ( ! $this->gateway || ! $this->payment ) {
			return $confirmation;
		}

		$confirmation = array( 'redirect' => $this->payment->get_pay_redirect_url() );

		if ( $this->error instanceof \Exception ) {
			$html  = '<ul>';
			$html .= '<li>' . Plugin::get_default_error_message() . '</li>';
			$html .= '<li>' . $this->error->getMessage() . '</li>';
			$html .= '</ul>';

			$confirmation = $html;
		}

		return $confirmation;
	}

	/**
	 * After submission.
	 *
	 * @param array $lead Gravity Forms lead/entry.
	 * @param array $form Gravity Forms form.
	 */
	public function after_submission( $lead, $form ) {

	}
}
