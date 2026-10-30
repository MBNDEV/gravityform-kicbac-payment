# Gravity Forms Kicbac Payment: hooks and Klaviyo sync

Reference for what the plugin does when an entry's Kicbac payment or subscription changes, and the hooks other code can use.

## Built-in Klaviyo sync

Code: `supports/class-gform-klaviyo.php` (`GF_Kicbac_Klaviyo_Support`), loaded by the add-on's `init()`.

When Gravity Forms Klaviyo is connected (Forms > Settings > Klaviyo), every change below is copied onto the donor's Klaviyo profile as custom properties. The donor is found by the Email field mapped in the form's MBN Kicbac Payment settings; a profile is created when none exists. Nothing is sent when Klaviyo is not connected or the entry has no mapped email.

A failed Klaviyo call never affects the payment: it is logged (Forms > Settings > Logging, MBN Kicbac Payment) and added as an entry note.

| Property | Value |
|---|---|
| `kicbac_payment_status` | `Approved`, `Declined`, `Failed`, `Partially Refunded`, `Refunded`, `Voided`, `Authorized` or `Unknown` |
| `kicbac_payment_amount` | One-time amount charged (number) |
| `kicbac_payment_date` | When it was charged |
| `kicbac_order_id` | Order ID sent to Kicbac |
| `kicbac_transaction_id` | Kicbac transaction of the one-time charge |
| `kicbac_auth_code` | Authorization code |
| `kicbac_refunded_amount` | Total refunded so far (number) |
| `kicbac_refund_transaction_id` | Kicbac transaction of the latest refund or void |
| `kicbac_refund_date` | When it was refunded |
| `kicbac_refund_note` | The admin's note on the latest refund |
| `kicbac_subscription_status` | `Active`, `Cancelled` or `Payment Failed` |
| `kicbac_subscription_id` | Kicbac subscription ID |
| `kicbac_subscription_amount` | Recurring amount (number) |
| `kicbac_billing_cycle` | e.g. `Monthly`, `Every 3 months (12 payments)` |
| `kicbac_next_charge_date` | Next subscription charge |
| `kicbac_cancel_note` | The admin's note on the cancellation |
| `kicbac_customer_vault_id` | Kicbac Customer Vault ID |
| `kicbac_response_text` | Last gateway response |
| `kicbac_last_update` | What changed it: `charge`, `refund`, `cancel` or the webhook event type, e.g. `transaction.refund.success` |
| `kicbac_updated_at` | When it was sent (ISO 8601, UTC) |
| `kicbac_entry_id` | Gravity Forms entry ID |
| `kicbac_form` | Form title |
| `kicbac_test_mode` | Whether the form charges with the test keys |

Fields that are empty on the entry are left out. An entry that started more than one subscription keeps its subscription values as comma-separated lists, in the same order.

Klaviyo segments and flows can use these directly, for example a "refund processed" email triggered when `kicbac_payment_status` changes to `Refunded`.

## `gfmbn_kicbac_payment_status_updated` (action)

```php
do_action( 'gfmbn_kicbac_payment_status_updated', int $entry_id, array $state, string $source, array $context );
```

Fires whenever an entry's Kicbac payment or subscription state changes. The Klaviyo sync above runs on it at priority 10.

| Argument | Meaning |
|---|---|
| `$entry_id` | The Gravity Forms entry. |
| `$state` | `payment_status`, `payment_amount`, `refunded_amount`, `transaction_id`, `subscription_status`, `subscription_id`, `subscription_amount`, `next_charge_date` after the change. |
| `$source` | `charge`, `refund`, `cancel` or `webhook`. |
| `$context` | By source, below. |

| `$source` | When | `$context` |
|---|---|---|
| `charge` | The entry was saved after its charge at submission. | empty |
| `refund` | An admin refunded or voided the one-time charge from the entry page. | `type` (`refund` or `void`), `amount`, `refund_transaction_id`, `note` |
| `cancel` | An admin cancelled a subscription from the entry page. | `subscription_id`, `note` |
| `webhook` | A signed Kicbac webhook updated the entry (portal refund or void, subscription payment, failed renewal, deleted subscription). | `event_type`, `event` (the webhook's `event_body`) |

## `gfmbn_kicbac_klaviyo_properties` (filter)

```php
apply_filters( 'gfmbn_kicbac_klaviyo_properties', array $properties, array $entry, array $form, string $source, array $context );
```

Changes what is sent to Klaviyo. Return an empty array to skip the sync for that entry.

```php
// Add the donor's company, and skip test-mode entries.
add_filter( 'gfmbn_kicbac_klaviyo_properties', function ( $properties, $entry, $form ) {
	if ( ! empty( $properties['kicbac_test_mode'] ) ) {
		return array();
	}

	$properties['kicbac_company'] = rgar( $entry, rgars( $form, 'gravityform-kicbac-payment/company' ) );

	return $properties;
}, 10, 3 );
```

## Entry data

Every Kicbac value is stored as entry meta prefixed `kicbac_` (read with `gform_get_meta( $entry_id, 'kicbac_payment_status' )`), and refunds and cancellations add an entry note naming the admin and their note.
