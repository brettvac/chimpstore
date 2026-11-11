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
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;

class JFormFieldMailchimpstores extends FormField
{
    protected $type = 'Mailchimpstores';

    private function debugLog(string $message): void
    {
        $plugin = PluginHelper::getPlugin('hikashop', 'chimpstore');
        $params = $plugin ? new Registry($plugin->params) : new Registry();

        if (!(bool) $params->get('debug', 0)) {
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

    private function client(string $apiKey): ?MailchimpHelper
    {
        try {
            return new MailchimpHelper($apiKey);
        } catch (\Throwable $e) {
            $this->debugLog('Mailchimp client initialization failed: ' . $e->getMessage());
            return null;
        }
    }
    
    private function getPluginParams(): ?Registry
    {
        $plugin = PluginHelper::getPlugin('hikashop', 'chimpstore');

        return $plugin && $plugin->params ? new Registry($plugin->params) : null;
    }

    protected function getInput()
    {
        $app = Factory::getApplication();
        $params = $this->getPluginParams();

        if (!$params) {
            return '<div class="alert alert-warning">' . htmlspecialchars(
                Text::_('PLG_HIKASHOP_CHIMPSTORE_SAVE_CONFIGURATION'), ENT_QUOTES, 'UTF-8'
            ) . '</div>';
        }

        $apiKey = trim((string) $params->get('api_key', ''));

        if ($apiKey === '') {
            return '<div class="alert alert-warning">' . htmlspecialchars(
                Text::_('PLG_HIKASHOP_CHIMPSTORE_ENTER_API_KEY_FIRST'), ENT_QUOTES, 'UTF-8'
            ) . '</div>';
        }

        $client = $this->client($apiKey);

        if (!$client) {
            return '<div class="alert alert-danger">' . htmlspecialchars(
                Text::_('PLG_HIKASHOP_CHIMPSTORE_LIBRARY_MISSING'), ENT_QUOTES, 'UTF-8'
            ) . '</div>';
        }

        // Process explicit store actions using the saved API key; the key is never put in the URL.
        $action = $app->input->getCmd('chimpstore_store_action', '');

        if ($action !== '') {
            $storeId = trim($app->input->getString('chimpstore_store_id', ''));

            try {
                // Validate Joomla's session token before processing any store action.
                if (!Session::checkToken('get')) {
                    $app->enqueueMessage(
                        Text::_('JINVALID_TOKEN'),
                        'error'
                    );
                } elseif ($action === 'create' && $storeId === '') {
                    $storeName = trim($app->input->getString('chimpstore_store_name', ''));
                    $currency = strtoupper(trim($app->input->getString('chimpstore_store_currency', 'USD')));
                    $audienceId = trim((string) $params->get('audience_id', ''));

                    if ($storeName === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
                        $app->enqueueMessage(
                            Text::_('PLG_HIKASHOP_CHIMPSTORE_STORE_NAME_CURRENCY_REQUIRED'),
                            'warning'
                        );
                    } elseif ($audienceId === '') {
                        $app->enqueueMessage(
                            Text::_('PLG_HIKASHOP_CHIMPSTORE_SELECT_AUDIENCE'),
                            'warning'
                        );
                    } else {
                        // Generate a stable, site-specific Mailchimp store ID.
                        $newStoreId = 'hikashop-' . substr(
                            hash('sha256', Uri::root()),
                            0,
                            24
                        );

                        $client->post('ecommerce/stores', [
                            'id'            => $newStoreId,
                            'name'          => mb_substr($storeName, 0, 50),
                            'currency_code' => $currency,
                            'list_id'       => $audienceId,
                        ]);

                        if ($client->success()) {
                            // Save the newly created store ID in the plugin configuration.
                            $db = Factory::getContainer()->get(DatabaseInterface::class);

                            $query = $db->getQuery(true)
                                ->select($db->quoteName(['extension_id', 'params']))
                                ->from($db->quoteName('#__extensions'))
                                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                                ->where($db->quoteName('folder') . ' = ' . $db->quote('hikashop'))
                                ->where($db->quoteName('element') . ' = ' . $db->quote('chimpstore'));

                            $db->setQuery($query);
                            $extension = $db->loadObject();

                            if (!$extension) {
                                throw new \RuntimeException(
                                    'Could not locate chimpstore plugin configuration.'
                                );
                            }

                            $savedParams = new Registry($extension->params);
                            $savedParams->set('store_id', $newStoreId);

                            $update = (object) [
                                'extension_id' => $extension->extension_id,
                                'params'       => $savedParams->toString(),
                            ];

                            $db->updateObject('#__extensions', $update, 'extension_id');

                            $app->enqueueMessage(
                                Text::_('PLG_HIKASHOP_CHIMPSTORE_STORE_CREATED'),
                                'success'
                            );

                            $this->debugLog('Store creation succeeded: ' . $newStoreId);
                        } else {
                            $error = (string) $client->getLastError();

                            $this->debugLog('Store creation failed: ' . $error);

                            $app->enqueueMessage(
                                Text::sprintf('PLG_HIKASHOP_CHIMPSTORE_API_ERROR', $error),
                                'error'
                            );
                        }
                    }
                } elseif ($action === 'update' && $storeId !== '') {
                    $storeName = trim($app->input->getString('chimpstore_store_name', ''));
                    $currency = strtoupper(trim($app->input->getString('chimpstore_store_currency', 'USD')));

                    if ($storeName === '' || $currency === '') {
                        $app->enqueueMessage(
                            Text::_('PLG_HIKASHOP_CHIMPSTORE_STORE_NAME_CURRENCY_REQUIRED'),
                            'warning'
                        );
                    } else {
                        $client->patch('ecommerce/stores/' . rawurlencode($storeId), [
                            'name' => mb_substr($storeName, 0, 50),
                            'currency_code' => $currency,
                        ]);

                        if ($client->success()) {
                            $app->enqueueMessage(
                                Text::_('PLG_HIKASHOP_CHIMPSTORE_STORE_UPDATED'),
                                'message'
                            );

                            $this->debugLog(
                                'Store update succeeded: ' . print_r($client->getLastResponse(), true)
                            );
                        } else {
                            $error = (string) $client->getLastError();

                            $this->debugLog('Store update failed: ' . $error);

                            $app->enqueueMessage(
                                Text::sprintf('PLG_HIKASHOP_CHIMPSTORE_API_ERROR', $error),
                                'error'
                            );
                        }
                    }
                } elseif ($action === 'delete' && $storeId !== '') {
                    $client->delete('ecommerce/stores/' . rawurlencode($storeId));

                    if ($client->success()) {
                        $app->enqueueMessage(
                            Text::_('PLG_HIKASHOP_CHIMPSTORE_STORE_DELETED'),
                            'message'
                        );

                        $this->debugLog('Store deletion succeeded: ' . $storeId);
                    } else {
                        $error = (string) $client->getLastError();

                        $this->debugLog('Store deletion failed: ' . $error);

                        $app->enqueueMessage(
                            Text::sprintf('PLG_HIKASHOP_CHIMPSTORE_API_ERROR', $error),
                            'error'
                        );
                    }
                }
            } catch (\Throwable $e) {
                $this->debugLog('Store action exception: ' . $e->getMessage());

                $app->enqueueMessage(
                    Text::sprintf('PLG_HIKASHOP_CHIMPSTORE_API_ERROR', $e->getMessage()),
                    'error'
                );
            }

            // Remove action parameters before redirecting.
            $url = Uri::getInstance();

            $url->delVar('chimpstore_store_action');
            $url->delVar('chimpstore_store_id');
            $url->delVar('chimpstore_store_name');
            $url->delVar('chimpstore_store_currency');

            // Remove the session token from the redirect URL.
            $url->delVar($app->getSession()->getFormToken());

            $app->redirect($url->toString(), 303);

            return '';
        }

        try {
            $response = $client->get('ecommerce/stores');
        } catch (\Throwable $e) {
            $this->debugLog('Could not retrieve Mailchimp stores: ' . $e->getMessage());

            return '<div class="alert alert-danger">' . htmlspecialchars(
                $e->getMessage(), ENT_QUOTES, 'UTF-8'
            ) . '</div>';
        }

        if (!$client->success()) {
            $error = (string) $client->getLastError();

            $this->debugLog('Could not retrieve Mailchimp stores: ' . $error);

            return '<div class="alert alert-danger">' . htmlspecialchars(
                Text::sprintf('PLG_HIKASHOP_CHIMPSTORE_API_ERROR', $error), ENT_QUOTES, 'UTF-8'
            ) . '</div>';
        }

        $stores = $response['stores'] ?? [];
        $selected = (string) $this->value;
        $selectedStore = null;

        foreach ($stores as $store) {
            if ((string) ($store['id'] ?? '') === $selected) {
                $selectedStore = $store;
                break;
            }
        }

        $options = [
            HTMLHelper::_('select.option', '', Text::_('PLG_HIKASHOP_CHIMPSTORE_SELECT_STORE'))
        ];

        foreach ($stores as $store) {
            $options[] = HTMLHelper::_(
                'select.option',
                $store['id'],
                $store['name'] ?? $store['id']
            );
        }

        $html = HTMLHelper::_(
            'select.genericlist',
            $options,
            $this->name,
            ['class' => 'form-select', 'id' => $this->id],
            'value',
            'text',
            $this->value,
            $this->id
        );

        $storeName = (string) (
            $selectedStore['name'] ?? Factory::getApplication()->get('sitename', 'HikaShop Store')
        );

        $currency = (string) ($selectedStore['currency_code'] ?? 'USD');

        $html .= '<div class="mt-3" id="chimpstore-store-tools">';

        $html .= '<label class="form-label" for="chimpstore-store-name">'
            . htmlspecialchars(Text::_('PLG_HIKASHOP_CHIMPSTORE_STORE_NAME'), ENT_QUOTES, 'UTF-8')
            . '</label>';

        $html .= '<input class="form-control" id="chimpstore-store-name" maxlength="50" value="'
            . htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8')
            . '">';

        $html .= '<label class="form-label mt-2" for="chimpstore-store-currency">'
            . htmlspecialchars(Text::_('PLG_HIKASHOP_CHIMPSTORE_STORE_CURRENCY'), ENT_QUOTES, 'UTF-8')
            . '</label>';

        $html .= '<input class="form-control" id="chimpstore-store-currency" maxlength="3" pattern="[A-Za-z]{3}" value="'
            . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8')
            . '">';

        $html .= '<div class="d-flex flex-wrap gap-2 mt-2">';

        $html .= '<button type="button" class="btn btn-primary" id="chimpstore-update-store">'
            . htmlspecialchars(Text::_('PLG_HIKASHOP_CHIMPSTORE_SYNCHRONIZE_STORE'), ENT_QUOTES, 'UTF-8')
            . '</button>';

        $html .= '<button type="button" class="btn btn-danger" id="chimpstore-delete-store"'
            . ($selectedStore ? '' : ' disabled')
            . '>'
            . htmlspecialchars(Text::_('PLG_HIKASHOP_CHIMPSTORE_DELETE_STORE'), ENT_QUOTES, 'UTF-8')
            . '</button>';

        $html .= '</div><p class="form-text">'
            . htmlspecialchars(Text::_('PLG_HIKASHOP_CHIMPSTORE_STORE_TOOLS_DESC'), ENT_QUOTES, 'UTF-8')
            . '</p></div>';

        $html .= '<script>
        (function () {
            const select = document.getElementById(' . json_encode($this->id) . ');
            const name = document.getElementById("chimpstore-store-name");
            const currency = document.getElementById("chimpstore-store-currency");
            const update = document.getElementById("chimpstore-update-store");
            const del = document.getElementById("chimpstore-delete-store");

            function actionUrl(action) {
                const url = new URL(window.location.href);

                url.searchParams.set("chimpstore_store_action", action);
                url.searchParams.set("chimpstore_store_id", select.value);
                url.searchParams.set("chimpstore_store_name", name.value);
                url.searchParams.set("chimpstore_store_currency", currency.value);

                // Include Joomla session token in the action URL.
                url.searchParams.set(' . json_encode($app->getSession()->getFormToken()) . ', "1");

                return url.toString();
            }

            const storeDetails = ' . json_encode(
                array_reduce($stores, function ($carry, $store) {
                    $carry[(string) ($store['id'] ?? '')] = [
                        'name' => (string) ($store['name'] ?? ''),
                        'currency_code' => (string) ($store['currency_code'] ?? 'USD'),
                    ];

                    return $carry;
                }, []),
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
            ) . ';

            select.addEventListener("change", function () {
                del.disabled = !select.value;

                const store = storeDetails[select.value];

                if (store) {
                    name.value = store.name;
                    currency.value = store.currency_code || "USD";
                }
            });

            update.addEventListener("click", function () {
                if (!name.value.trim() || !/^[a-z]{3}$/i.test(currency.value.trim())) {
                    alert(' . json_encode(Text::_('PLG_HIKASHOP_CHIMPSTORE_STORE_NAME_CURRENCY_REQUIRED')) . ');
                    return;
                }

                const action = select.value ? "update" : "create";

                window.location.href = actionUrl(action);
            });

            del.addEventListener("click", function () {
                if (!select.value) return;

                if (confirm(' . json_encode(Text::_('PLG_HIKASHOP_CHIMPSTORE_CONFIRM_DELETE_STORE')) . ')) {
                    window.location.href = actionUrl("delete");
                }
            });
        })();
        </script>';

        return $html;
    }
}
