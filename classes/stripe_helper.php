<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Various helper methods for interacting with the Stripe API
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_stripe;

use core_payment\helper;
use core_payment\local\entities\payable;
use core_user;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\WebhookEndpoint;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../.extlib/stripe-php/init.php');

/**
 * The helper class for Stripe payment gateway.
 *
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stripe_helper {

    /**
     * @var StripeClient Secret API key (Do not publish).
     */
    private $stripe;
    /**
     * @var string Public API key.
     */
    private $apikey;

    /**
     * Initialise the Stripe API client.
     *
     * @param string $apikey
     * @param string $secretkey
     */
    public function __construct(string $apikey, string $secretkey) {
        $this->apikey = $apikey;
        $this->stripe = new StripeClient([
            "api_key" => $secretkey
        ]);
        Stripe::setAppInfo(
            'Moodle Stripe Payment Gateway',
            '1.17',
            'https://github.com/alexmorrisnz/moodle-paygw_stripe'
        );
    }

    /**
     * Find a product in the database and the corresponding Stripe Product item.
     *
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return Product|null
     * @throws \dml_exception
     */
    public function get_product(string $component, string $paymentarea, string $itemid): ?Product {
        global $DB;

        if ($record = $DB->get_record('paygw_stripe_products',
            ['component' => $component, 'paymentarea' => $paymentarea, 'itemid' => $itemid])) {
            try {
                return $this->stripe->products->retrieve($record->productid);
            } catch (ApiErrorException $e) {
                // Product exists in Moodle but not in stripe, possibly the keys were switched.
                // Delete product for creation later.
                $DB->delete_records('paygw_stripe_products',
                    ['component' => $component, 'paymentarea' => $paymentarea, 'itemid' => $itemid]);
                return null;
            }
        }
        return null;
    }

    /**
     * Create a product in Stripe and save the ID into the Moodle database.
     *
     * @param string $description
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return Product
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function create_product(string $description, string $component, string $paymentarea, string $itemid): Product {
        global $DB;
        $product = $this->stripe->products->create([
            'name' => $description
        ]);
        $record = new \stdClass();
        $record->productid = $product->id;
        $record->component = $component;
        $record->paymentarea = $paymentarea;
        $record->itemid = $itemid;
        $DB->insert_record('paygw_stripe_products', $record);
        return $product;
    }

    /**
     * Get the first price listed on a product.
     *
     * @param Product $product
     * @return Price|null
     */
    public function get_price(Product $product): ?Price {
        try {
            $prices = $this->stripe->prices->all(['product' => $product->id]);
            foreach ($prices as $price) {
                if ($price instanceof Price) {
                    if ($price->active) {
                        return $price;
                    }
                }
            }
            return null;
        } catch (ApiErrorException $e) {
            return null;
        }
    }

    /**
     * Create a price against an associated product.
     *
     * @param string $currency Currency
     * @param string $productid Product ID
     * @param float $unitamount Price
     * @param bool $automatictax Toggles insertion of a tax behavior
     * @param string|null $defaultbehavior The default tax behavior for the price, if enabled
     */
    public function create_price(string $currency, string $productid, float $unitamount, bool $automatictax,
        ?string $defaultbehavior) {
        $pricedata = [
            'currency' => $currency,
            'product' => $productid,
            'unit_amount' => $unitamount,
        ];
        if ($automatictax == 1) {
            $pricedata['tax_behavior'] = $defaultbehavior ?? 'inclusive';
        }
        return $this->stripe->prices->create($pricedata);
    }

    /**
     * Get the stripe Customer object from the corresponding Moodle user id.
     *
     * @param int $userid
     * @return Customer|null
     * @throws \dml_exception
     */
    public function get_customer(int $userid): ?Customer {
        global $DB;
        if (!$record = $DB->get_record('paygw_stripe_customers', ['userid' => $userid])) {
            return null;
        }
        try {
            return $this->stripe->customers->retrieve($record->customerid);
        } catch (ApiErrorException $e) {
            // Customer exists in Moodle but not in stripe, possibly the keys were switched.
            // Delete customer for creation later.
            $DB->delete_records('paygw_stripe_customers', ['userid' => $userid]);
            return null;
        }
    }

    /**
     * Create a Stripe customer object and save the ID and user ID into the database.
     *
     * @param \stdClass $user
     * @return Customer
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function create_customer($user): Customer {
        global $DB;
        $customer = $this->stripe->customers->create([
            'email' => $user->email,
            'name'  => $user->firstname . ' ' . $user->lastname,
            'description' => get_string('customerdescription', 'paygw_stripe', $user->id),
        ]);
        $record = new \stdClass();
        $record->userid = $user->id;
        $record->customerid = $customer->id;
        $DB->insert_record('paygw_stripe_customers', $record);
        return $customer;
    }

    /**
     * Create a payment intent and return with the checkout session id.
     *
     * @param object $config
     * @param payable $payable
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return string
     * @throws ApiErrorException
     */
    public function generate_payment(object $config, payable $payable, string $description, float $cost, string $component,
        string $paymentarea, string $itemid): string {
        global $CFG, $USER;

        // Ensure webhook exists before we potentially use it.
        $this->create_webhook($payable->get_account_id());

        $unitamount = $this->get_unit_amount($cost, $payable->get_currency());
        $currency = strtolower($payable->get_currency());

        if (!$product = $this->get_product($component, $paymentarea, $itemid)) {
            $product = $this->create_product($description, $component, $paymentarea, $itemid);
        }
        if (!$price = $this->get_price($product)) {
            $price = $this->create_price($currency, $product->id, $unitamount, $config->enableautomatictax == 1,
                $config->defaulttaxbehavior);
        } else {
            if ($price->unit_amount != $unitamount || $price->currency != $currency) {
                // We cannot update the price or currency, so we must create a new price.
                $price->updateAttributes(['active' => false]);
                $price->save();
                $price = $this->create_price($currency, $product->id, $unitamount, $config->enableautomatictax == 1,
                    $config->defaulttaxbehavior);
            }
            // Set tax behavior if not set already.
            if ($config->enableautomatictax == 1 && (!isset($price->tax_behavior) || $price->tax_behavior === 'unspecified')) {
                $price->updateAttributes(['tax_behavior' => $config->tax_behavior ?? 'inclusive']);
                $price->save();
            }
        }

        if (!$customer = $this->get_customer($USER->id)) {
            $customer = $this->create_customer($USER);
        }

        $session = $this->stripe->checkout->sessions->create([
            'success_url' => $CFG->wwwroot . '/payment/gateway/stripe/process.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $CFG->wwwroot . '/payment/gateway/stripe/cancelled.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid,
            'payment_method_types' => $config->paymentmethods,
            'payment_method_options' => [
                'wechat_pay' => [
                    'client' => "web"
                ],
            ],
            'mode' => 'payment',
            'line_items' => [[
                'price' => $price,
                'quantity' => 1
            ]],
            'automatic_tax' => [
                'enabled' => $config->enableautomatictax == 1,
            ],
            'customer' => $customer->id,
            'metadata' => [
                'userid' => $USER->id,
                'username' => $USER->username,
                'firstname' => $USER->firstname,
                'lastname' => $USER->lastname,
                'component' => $component,
                'paymentarea' => $paymentarea,
                'itemid' => $itemid,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'userid' => $USER->id,
                    'username' => $USER->username,
                    'firstname' => $USER->firstname,
                    'lastname' => $USER->lastname,
                    'component' => $component,
                    'paymentarea' => $paymentarea,
                    'itemid' => $itemid,
                ],
            ],
            'allow_promotion_codes' => $config->allowpromotioncodes == 1,
            'customer_update' => [
                'address' => 'auto',
            ],
            'phone_number_collection' => [
                'enabled' => true,
            ],
            'billing_address_collection' => 'required',
        ]);

        return $session->id;
    }

    /**
     * Check if a checkout session has been paid
     *
     * @param string $sessionid Stripe session ID
     * @return bool
     * @throws ApiErrorException
     */
    public function is_paid(string $sessionid): bool {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid);
        return $session->payment_status === 'paid';
    }

    /**
     * Check if a checkout session is pending payment.
     *
     * @param string $sessionid Stripe session ID
     * @return bool
     * @throws ApiErrorException
     */
    public function is_pending(string $sessionid): bool {
        // Check payment intent here as the session status is a simple pass/fail that doesn't include processing.
        $session = $this->stripe->checkout->sessions->retrieve($sessionid, ['expand' => ['payment_intent']]);
        return $session->payment_intent->status === 'processing';
    }

    /**
     * Convert the cost into the unit amount accounting for zero-decimal currencies.
     *
     * @param float $cost
     * @param string $currency
     * @return float
     */
    public function get_unit_amount(float $cost, string $currency): float {
        if (in_array($currency, gateway::get_zero_decimal_currencies())) {
            return $cost;
        }
        return $cost * 100;
    }

    /**
     * Saves the payment intent status with customer and product id details.
     *
     * @param string $sessionid
     * @return void
     * @throws ApiErrorException|\dml_exception
     */
    public function save_payment_status(string $sessionid) {
        global $DB, $USER;

        $session = $this->stripe->checkout->sessions->retrieve($sessionid, ['expand' => ['line_items', 'customer']]);

        $intent = $DB->get_record('paygw_stripe_intents', ['paymentintent' => $session->payment_intent]);
        if ($intent != null) {
            $intent->status = $session->status;
            $intent->paymentstatus = $session->payment_status;
            $DB->update_record('paygw_stripe_intents', $intent);
            return;
        }

        $intent = new \stdClass();
        $intent->userid = $USER->id;
        $intent->paymentintent = $session->payment_intent;
        $intent->customerid = $session->customer->id;
        $intent->amounttotal = $session->amount_total;
        $intent->paymentstatus = $session->payment_status;
        $intent->status = $session->status;
        $intent->productid = $session->line_items->first()->price->product;

        $DB->insert_record('paygw_stripe_intents', $intent);
    }

    /**
     * Find and return webhook endpoint if it exists.
     *
     * @param int $paymentaccountid
     * @return WebhookEndpoint|null
     * @throws ApiErrorException|\dml_exception
     */
    public function get_webhook(int $paymentaccountid): ?WebhookEndpoint {
        global $DB;

        if (!($record = $DB->get_record('paygw_stripe_webhooks', ['paymentaccountid' => $paymentaccountid]))) {
            return null;
        }

        if ($webhook = $this->stripe->webhookEndpoints->retrieve($record->webhookid)) {
            // Webhook still exists, lets set the secret and return.
            $webhook->secret = $record->secret;
            return $webhook;
        }

        return null;
    }

    /**
     * Create webhook for given account id if none already exists.
     *
     * @param int $paymentaccountid
     * @return bool True if webhook was created
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function create_webhook(int $paymentaccountid): bool {
        global $CFG, $DB;

        if ($this->get_webhook($paymentaccountid) != null) {
            return false;
        }

        $webhook = $this->stripe->webhookEndpoints->create([
            'url' => $CFG->wwwroot . '/payment/gateway/stripe/webhook.php',
            'enabled_events' => [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed',
            ],
        ]);

        $datum = new \stdClass();
        $datum->paymentaccountid = $paymentaccountid;
        $datum->webhookid = $webhook->id;
        $datum->secret = $webhook->secret;
        $DB->insert_record('paygw_stripe_webhooks', $datum);

        return true;
    }

    /**
     * Process an async payment event.
     * Deliver the course if payment was successful or notify the user the payment failed.
     *
     * @param Event $event
     * @param array $metadata Array containing component, paymentarea, and itemid values set during session creation.
     * @return bool True if stripe data was valid, false otherwise.
     * @throws ApiErrorException|\dml_exception
     */
    public function process_async_payment(Event $event, array $metadata): bool {
        global $DB;

        if (!isset($event->data->object)) {
            return false;
        }

        // Events are sent to all subscribed webhooks, verify we are the correct receipt for this event.
        $session = $this->stripe->checkout->sessions->retrieve($event->data->object->id, ['expand' => ['payment_intent']]);
        if (!($intentrecord = $DB->get_record('paygw_stripe_intents', ['paymentintent' => $session->payment_intent->id]))) {
            return false;
        }
        $this->save_payment_status($session->id); // Update saved intent status.

        switch ($event->type) {
            case 'checkout.session.async_payment_succeeded':
                if (!$this->is_paid($session->id)) {
                    // Payment not complete, notify user payment failed.
                    $this->notify_user($intentrecord->userid, 'failed');
                    break;
                }

                // Deliver course.
                $payable = helper::get_payable($metadata['component'], $metadata['paymentarea'], $metadata['itemid']);
                $cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(),
                    helper::get_gateway_surcharge('stripe'));
                $paymentid = helper::save_payment($payable->get_account_id(), $metadata['component'], $metadata['paymentarea'],
                    $metadata['itemid'], $intentrecord->userid, $cost, $payable->get_currency(), 'stripe');
                helper::deliver_order($metadata['component'], $metadata['paymentarea'], $metadata['itemid'], $paymentid,
                    $intentrecord->userid);

                // Notify user payment was successful.
                $url = helper::get_success_url($metadata['component'], $metadata['paymentarea'], $metadata['itemid']);
                $this->notify_user($intentrecord->userid, 'successful', ['url' => $url->out()]);
                break;
            case 'checkout.session.async_payment_failed':
                // Notify user payment failed.
                $this->notify_user($intentrecord->userid, 'failed');
                break;
            default:
                return false;
        }
        return true;
    }

    /**
     * Send message to user regarding payment status.
     *
     * @param int $userto User ID to send notification to
     * @param string $status Payment status
     * @param array $data Data passed to get_string
     * @return void
     * @throws \coding_exception
     */
    private function notify_user(int $userto, string $status, array $data = []) {
        $eventdata = new \core\message\message();
        $eventdata->courseid = SITEID;
        $eventdata->component = 'paygw_stripe';
        $eventdata->name = 'payment_' . $status;
        $eventdata->notification = 1;
        $eventdata->userfrom = core_user::get_noreply_user();
        $eventdata->userto = $userto;
        $eventdata->subject = get_string('payment:' . $status . ':subject', 'paygw_stripe', $data);
        $eventdata->fullmessage = get_string('payment:' . $status . ':message', 'paygw_stripe', $data);
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = '';
        $eventdata->smallmessage = '';
        if (isset($data['url'])) {
            $eventdata->contexturl = $data['url'];
        }
        message_send($eventdata);
    }

}
