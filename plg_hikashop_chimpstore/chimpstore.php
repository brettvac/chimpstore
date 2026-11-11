<?php
/**
 * @package     Joomla.Site
 * @subpackage  chimpstore
 * @version     1.0
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;

require_once __DIR__ . '/helper/mailchimphelper.php';

if (!class_exists('hikashopPlugin')) {
    require_once JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/plugin.php';
}

/**
 * HikaShop to Mailchimp integration.
 */
class plgHikashopChimpstore extends hikashopPlugin
{
    public function __construct(&$subject, $config = [])
    {
        parent::__construct($subject, $config);
        Factory::getApplication()->getLanguage()->load('plg_hikashop_chimpstore', JPATH_ADMINISTRATOR);
    }

    /**
     * Log only when the plugin's Debug option is enabled.
     */
    private function debugLog($message): void
    {
        if (!(bool) $this->params->get('debug', 0)) {
            return;
        }

        // Load HikaShop's helper.
        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        $helper = rtrim(JPATH_ADMINISTRATOR, DS)
            . DS . 'components'
            . DS . 'com_hikashop'
            . DS . 'helpers'
            . DS . 'helper.php';

        include_once $helper;

        hikashop_writeToLog('[chimpstore] ' . $message, 'chimpstore');
    }

    private function createMailchimpClient(string $apiKey): ?MailchimpHelper
    {
        try {
            return new MailchimpHelper($apiKey);
        } catch (\Throwable $e) {
            $this->debugLog('Could not initialize Mailchimp client: ' . $e->getMessage());
            return null;
        }
    }

    
    public function onAfterOrderCreate(&$order, &$send_email)
    {
        if (!$this->params) {
            return;
        }

        $apiKey  = trim((string) $this->params->get('api_key', ''));
        $storeId = trim((string) $this->params->get('store_id', ''));

        if ($apiKey === '' || $storeId === '') {
            return;
        }

        // Retrieve the HikaShop customer.
        $userClass = hikashop_get('class.user');
        $user = $userClass
            ? $userClass->get((int) ($order->order_user_id ?? 0))
            : null;

        $email = trim((string) ($user->user_email ?? ''));

        if ($email === '') {
            $this->debugLog('Order skipped: customer email address is blank.');
            return;
        }

        // Verify that order products are available.
        if (
            empty($order->cart->products)
            || !is_iterable($order->cart->products)
        ) {
            $this->debugLog('Order skipped: no order products were available.');
            return;
        }

        $mailchimpInstance = $this->createMailchimpClient($apiKey);

        if (!$mailchimpInstance) {
            return;
        }

        // Retrieve the Mailchimp campaign ID.
        $app = Factory::getApplication();

        $campaignId = $app->input->getString('mc_cid', '');

        if ($campaignId === '' && isset($_COOKIE['mc_cid'])) {
            $campaignId = (string) $_COOKIE['mc_cid'];
        }

        // Determine the order currency.
        $currencyCode = 'USD';
        $currencyId = (int) ($order->order_currency_id ?? 0);

        if ($currencyId > 0) {
            $currencyClass = hikashop_get('class.currency');
            $currency = $currencyClass
                ? $currencyClass->get($currencyId)
                : null;

            if (!empty($currency->currency_code)) {
                $currencyCode = strtoupper(
                    (string) $currency->currency_code
                );
            }
        }

        // Prepare order lines.
        $lines = [];

        foreach ($order->cart->products as $product) {
            $productId = (string) (
                $product->product_id
                ?? $product->cart_product_id
                ?? ''
            );

            if ($productId === '') {
                continue;
            }

            $lines[] = [
                'id'                 => (string) (
                    $product->cart_product_id ?? $productId
                ),
                'product_id'         => $productId,
                'product_variant_id' => $productId,
                'quantity'           => (int) (
                    $product->order_product_quantity ?? 1
                ),
                'price'              => (float) (
                    $product->order_product_price ?? 0
                ),
            ];
        }

        if (!$lines) {
            $this->debugLog('Order skipped: no valid order lines were available.');
            return;
        }

        // Determine order information.
        $orderId = (string) (
            $order->order_number ?? $order->order_id ?? ''
        );

        $price = (float) ($order->order_full_price ?? 0)
            - (float) ($order->order_discount_price ?? 0)
            - (float) ($order->order_shipping_price ?? 0);


        // Retrieve the customer's billing address.
        $billingAddress = $order->cart->billing_address ?? null;

        // If the cart address is unavailable, retrieve it directly using the billing address ID.
        if (!$billingAddress) {
            $billingAddressId = (int) (
                $order->order_billing_address_id ?? 0
            );

            if ($billingAddressId > 0) {
                $addressClass = hikashop_get('class.address');

                if ($addressClass) {
                    $billingAddress = $addressClass->get(
                        $billingAddressId
                    );
                }
            }
        }

        $firstName = trim((string) (
            $billingAddress->address_firstname ?? ''
        ));

        $lastName = trim((string) (
            $billingAddress->address_lastname ?? ''
        ));

        $customerId = (string) (
            $user->user_id ?? $order->order_user_id ?? ''
        );

        if ($customerId === '') {
            $this->debugLog('Order skipped: customer ID is missing.');
            return;
        }

        $this->debugLog(
            'Customer data: ID=' . $customerId
            . ', billing address ID='
            . (int) ($order->order_billing_address_id ?? 0)
            . ', first name available='
            . ($firstName !== '' ? 'yes' : 'no')
            . ', last name available='
            . ($lastName !== '' ? 'yes' : 'no')
        );


        // Create or update the Mailchimp customer.
        $customerData = [
            'id'            => $customerId,
            'email_address' => $email,
            'opt_in_status' => false,
        ];

        // Never overwrite existing names with empty values.
        if ($firstName !== '') {
            $customerData['first_name'] = $firstName;
        }

        if ($lastName !== '') {
            $customerData['last_name'] = $lastName;
        }

        $mailchimpInstance->put(
            "ecommerce/stores/{$storeId}/customers/{$customerId}",
            $customerData
        );

        if (!$mailchimpInstance->success()) {
            $this->debugLog(
                'Customer synchronization failed for '
                . $customerId . ': '
                . (string) $mailchimpInstance->getLastError()
            );

            return;
        }

        $this->debugLog(
            'Customer synchronization succeeded for '
            . $customerId . '.'
        );


        // Create the Mailchimp order.
        $orderData = [
            'id'            => $orderId,
            'customer'      => [
                'id' => $customerId,
            ],
            'currency_code' => $currencyCode,
            'order_total'   => max(0, $price),
            'lines'         => $lines,
        ];

        if ($campaignId !== '') {
            $orderData['campaign_id'] = $campaignId;
        }

        $mailchimpInstance->post(
            "ecommerce/stores/{$storeId}/orders",
            $orderData
        );

        if (!$mailchimpInstance->success()) {
            $this->debugLog(
                'Mailchimp order sync failed for order '
                . $orderId . ': '
                . (string) $mailchimpInstance->getLastError()
            );
        } else {
            $this->debugLog(
                'Mailchimp order sync succeeded for order '
                . $orderId . '.'
            );
        }
    }

}
