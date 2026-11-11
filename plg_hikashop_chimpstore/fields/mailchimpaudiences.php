<?php
/**
 * @package     Joomla.Site
 * @subpackage  chimpstore
 * @version     1.0
 * @license     GNU General Public License version 2 
 */

defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

require_once JPATH_PLUGINS . '/hikashop/chimpstore/helper/mailchimphelper.php';

class JFormFieldMailchimpaudiences extends ListField
{
    protected $type = 'Mailchimpaudiences';

    protected function getOptions(): array
    {
        $options = [];

        $plugin = PluginHelper::getPlugin('hikashop', 'chimpstore');

        if (!$plugin || empty($plugin->params)) {
            $options[] = HTMLHelper::_(
                'select.option',
                '',
                Text::_('PLG_HIKASHOP_CHIMPSTORE_ENTER_API_KEY_FIRST')
            );

            return array_merge(parent::getOptions(), $options);
        }

        $params = new Registry($plugin->params);
        $apiKey = trim((string) $params->get('api_key', ''));

        if ($apiKey === '') {
            $options[] = HTMLHelper::_(
                'select.option',
                '',
                Text::_('PLG_HIKASHOP_CHIMPSTORE_ENTER_API_KEY_FIRST')
            );

            return array_merge(parent::getOptions(), $options);
        }

        try {
            $mailchimp = new MailchimpHelper($apiKey);
            $lists = $mailchimp->get('lists', [
                'count' => 100,
            ]);

            if (!$mailchimp->success()) {
                $options[] = HTMLHelper::_(
                    'select.option',
                    '',
                    Text::_('PLG_HIKASHOP_CHIMPSTORE_AUDIENCE_API_ERROR')
                );

                return array_merge(parent::getOptions(), $options);
            }

            if (empty($lists['lists'])) {
                $options[] = HTMLHelper::_(
                    'select.option',
                    '',
                    Text::_('PLG_HIKASHOP_CHIMPSTORE_NO_AUDIENCES')
                );

                return array_merge(parent::getOptions(), $options);
            }

            foreach ($lists['lists'] as $list) {
                if (empty($list['id'])) {
                    continue;
                }

                $options[] = HTMLHelper::_(
                    'select.option',
                    $list['id'],
                    $list['name'] ?? $list['id']
                );
            }

        } catch (\Throwable $e) {
            $options[] = HTMLHelper::_(
                'select.option',
                '',
                Text::_('PLG_HIKASHOP_CHIMPSTORE_AUDIENCE_API_ERROR')
            );
        }

        return array_merge(parent::getOptions(), $options);
    }
}
