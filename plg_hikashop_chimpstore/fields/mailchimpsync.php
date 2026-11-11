<?php
/**
 * @package     Joomla.Site
 * @subpackage  chimpstore
 * @version     1.0
 * @license     GNU General Public License version 2 
 */

defined('_JEXEC') or die;

require_once JPATH_PLUGINS . '/hikashop/chimpstore/helper/mailchimphelper.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

class JFormFieldMailchimpsync extends FormField
{
    protected $type = 'Mailchimpsync';

    private function debugLog($message)
    {
        $plugin = PluginHelper::getPlugin('hikashop', 'chimpstore');
        $params = $plugin ? new Registry($plugin->params) : new Registry();

        // Do not load HikaShop's helper unless debugging is enabled.
        if (!(bool) $params->get('debug', 0)) {
            return;
        }

        // Load HikaShop's helper if its logging function is not available.
        if (!function_exists('hikashop_writeToLog')) {
            if (!defined('DS')) {
                define('DS', DIRECTORY_SEPARATOR);
            }

            $helper = rtrim(JPATH_ADMINISTRATOR, DS)
                . DS . 'components'
                . DS . 'com_hikashop'
                . DS . 'helpers'
                . DS . 'helper.php';

            if (is_file($helper)) {
                include_once $helper;
            }
        }

        hikashop_writeToLog('[chimpstore] ' . $message, 'chimpstore');
    }
    
    private function client(string $apiKey): ?MailchimpHelper
    {
        try {
            return new MailchimpHelper($apiKey);
        } catch (\Throwable $e) {
            $this->debugLog('Mailchimp client initialization failed: ' . $e->getMessage());
            return null;
        }
    }

    protected function getInput()
    {
        $app = Factory::getApplication();
        $plugin = PluginHelper::getPlugin('hikashop', 'chimpstore');
        if (!$plugin || !$plugin->params) {
            return '<div class="alert alert-warning">' . htmlspecialchars(Text::_('PLG_HIKASHOP_CHIMPSTORE_SAVE_CONFIGURATION'), ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $params = new Registry($plugin->params);
              
        $apiKey = trim((string) $params->get('api_key', ''));
        $storeId = trim((string) $params->get('store_id', ''));

        if ($apiKey === '' || $storeId === '') {
            return '<div class="alert alert-warning">' . htmlspecialchars(Text::_('PLG_HIKASHOP_CHIMPSTORE_SAVE_API_AND_STORE'), ENT_QUOTES, 'UTF-8') . '</div>';
        }

        if ($app->input->getCmd('chimpstore_sync_products', '') === '1') {
            @set_time_limit(0);
            $client = $this->client($apiKey);
            if (!$client) {
                $app->enqueueMessage(Text::_('PLG_HIKASHOP_CHIMPSTORE_LIBRARY_MISSING'), 'error');
                return '';
            }

            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('p.product_id'),
                    $db->quoteName('p.product_name'),
                    '(SELECT ' . $db->quoteName('price_value') . ' FROM ' . $db->quoteName('#__hikashop_price')
                    . ' WHERE ' . $db->quoteName('price_product_id') . ' = ' . $db->quoteName('p.product_id') . ' LIMIT 1) AS ' . $db->quoteName('price_value')
                ])
                ->from($db->quoteName('#__hikashop_product', 'p'))
                ->where($db->quoteName('p.product_published') . ' = 1')
                ->where($db->quoteName('p.product_type') . ' = ' . $db->quote('main'));
            $db->setQuery($query);
            $products = $db->loadObjectList();

            $successCount = 0;
            $errorCount = 0;
            
            // Synchronize each published HikaShop product with Mailchimp.
            foreach ($products as $product) {
                $productId = (string) $product->product_id;
                $price = max(0, (float) $product->price_value);
                $data = [
                    'id' => $productId,
                    'title' => (string) $product->product_name,
                    'variants' => [[
                        'id' => $productId,
                        'title' => (string) $product->product_name,
                        'price' => $price,
                    ]],
                ];

                try {
                    // PUT creates or replaces the product, so changed names/prices are synchronized too.
                    $client->put(
                        'ecommerce/stores/' . rawurlencode($storeId) . '/products/' . rawurlencode($productId),
                        $data
                    );

                    if ($client->success()) {
                        $this->debugLog(
                            'Product sync succeeded: ' . $product->product_name .
                            ' (ID: ' . $product->product_id . ')'
                        );

                        $successCount++;
                    } else {
                        $this->debugLog(
                            'Product sync failed: ' . $product->product_name .
                            ' (ID: ' . $product->product_id . '). Error: ' .
                            $client->getLastError()
                        );

                        $errorCount++;
                    }
                } catch (\Throwable $e) {
                    $this->debugLog(
                        'Product sync exception: ' . $product->product_name .
                        ' (ID: ' . $product->product_id . '). Error: ' .
                        $e->getMessage()
                    );

                    $errorCount++;
                }
            
            } 

            $this->debugLog(
                'Product synchronization completed. ' .
                'Successful: ' . $successCount .
                ', Failed: ' . $errorCount
            );
            $app->enqueueMessage(
                Text::sprintf('PLG_HIKASHOP_CHIMPSTORE_SYNC_RESULT', $successCount, $errorCount),
                $errorCount > 0 ? 'warning' : 'success'
            );

            $uri = Uri::getInstance();
            $uri->delVar('chimpstore_sync_products');
            $app->redirect($uri->toString(), 303);
            return '';
        }

        $html = '<button type="button" class="btn btn-success" id="chimpstore-sync-btn">'
            . '<span class="icon-loop" aria-hidden="true"></span> '
            . htmlspecialchars(Text::_('PLG_HIKASHOP_CHIMPSTORE_SYNC_PRODUCTS_BUTTON'), ENT_QUOTES, 'UTF-8')
            . '</button><p class="small text-muted mt-2">'
            . htmlspecialchars(Text::_('PLG_HIKASHOP_CHIMPSTORE_SYNC_PRODUCTS_DESC'), ENT_QUOTES, 'UTF-8')
            . '</p><script>
            (function () {
                const button = document.getElementById("chimpstore-sync-btn");
                if (!button) return;
                button.addEventListener("click", function () {
                    button.disabled = true;
                    button.textContent = ' . json_encode(Text::_('PLG_HIKASHOP_CHIMPSTORE_SYNCING')) . ';
                    const url = new URL(window.location.href);
                    url.searchParams.set("chimpstore_sync_products", "1");
                    window.location.href = url.toString();
                });
            })();
            </script>';

        return $html;
    }
}
