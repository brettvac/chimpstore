<?php
/**
 * @package     Joomla.Site
 * @subpackage  chimpstore
 * @version     1.0
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;

/**
 * Mailchimp API v3 helper.
 */
class MailchimpHelper
{
    private const TIMEOUT = 10;

    private string $apiEndpoint;
    private string $apiKey;
    private $http;

    private bool $requestSuccessful = false;
    private string $lastError = '';
    private array $lastResponse = [];

    public function __construct(string $apiKey)
    {
        $this->apiKey = trim($apiKey);

        $separator = strrpos($this->apiKey, '-');

        if ($separator === false) {
            throw new \InvalidArgumentException('Invalid Mailchimp API key supplied.');
        }

        $dataCenter = substr($this->apiKey, $separator + 1);

        if (!preg_match('/^[a-z0-9]+$/i', $dataCenter)) {
            throw new \InvalidArgumentException('Invalid Mailchimp API key supplied.');
        }

        $this->apiEndpoint = 'https://' . $dataCenter . '.api.mailchimp.com/3.0';

        $this->http = HttpFactory::getHttp([
            'timeout' => self::TIMEOUT,
        ]);
    }

    public static function subscriberHash(string $email): string
    {
        return md5(strtolower(trim($email)));
    }

    public function get(string $method, array $args = []): array|false
    {
        return $this->makeRequest('get', $method, $args);
    }

    public function post(string $method, array $args = []): array|false
    {
        return $this->makeRequest('post', $method, $args);
    }

    public function put(string $method, array $args = []): array|false
    {
        return $this->makeRequest('put', $method, $args);
    }

    public function patch(string $method, array $args = []): array|false
    {
        return $this->makeRequest('patch', $method, $args);
    }

    public function delete(string $method, array $args = []): array|false
    {
        return $this->makeRequest('delete', $method, $args);
    }

    public function success(): bool
    {
        return $this->requestSuccessful;
    }

    public function getLastError(): string|false
    {
        return $this->lastError ?: false;
    }

    public function getLastResponse(): array
    {
        return $this->lastResponse;
    }

    private function makeRequest(
        string $verb,
        string $method,
        array $args = []
    ): array|false {
        $this->requestSuccessful = false;
        $this->lastError = '';
        $this->lastResponse = [];

        $method = ltrim($method, '/');

        if (
            $method === ''
            || str_contains($method, '://')
            || str_contains($method, '..')
        ) {
            $this->lastError = 'Invalid Mailchimp API endpoint.';
            return false;
        }

        $url = $this->apiEndpoint . '/' . $method;

        $headers = [
            'Accept'        => 'application/vnd.api+json',
            'Content-Type'  => 'application/vnd.api+json',
            'Authorization' => 'apikey ' . $this->apiKey,
        ];

        if (isset($args['language'])) {
            $headers['Accept-Language'] = $args['language'];
            unset($args['language']);
        }

        try {
            switch ($verb) {
                case 'get':
                    if ($args !== []) {
                        $url .= '?' . http_build_query(
                            $args,
                            '',
                            '&',
                            PHP_QUERY_RFC3986
                        );
                    }

                    $response = $this->http->get(
                        $url,
                        $headers,
                        self::TIMEOUT
                    );
                    break;

                case 'post':
                    $response = $this->http->post(
                        $url,
                        json_encode($args, JSON_THROW_ON_ERROR),
                        $headers,
                        self::TIMEOUT
                    );
                    break;

                case 'put':
                    $response = $this->http->put(
                        $url,
                        json_encode($args, JSON_THROW_ON_ERROR),
                        $headers,
                        self::TIMEOUT
                    );
                    break;

                case 'patch':
                    $response = $this->http->patch(
                        $url,
                        json_encode($args, JSON_THROW_ON_ERROR),
                        $headers,
                        self::TIMEOUT
                    );
                    break;

                case 'delete':
                    $response = $this->http->delete(
                        $url,
                        $headers,
                        self::TIMEOUT
                    );
                    break;

                default:
                    $this->lastError = 'Unsupported HTTP method.';
                    return false;
            }

            $this->lastResponse = [
                'code'    => $response->code,
                'headers' => $response->headers,
                'body'    => $response->body,
            ];

            $result = [];

            if ($response->body !== '') {
                $result = json_decode(
                    $response->body,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (!is_array($result)) {
                    throw new \UnexpectedValueException(
                        'Invalid JSON response from Mailchimp.'
                    );
                }
            }

            if ($response->code >= 200 && $response->code <= 299) {
                $this->requestSuccessful = true;
                return $result;
            }

            if (isset($result['detail'])) {
                $this->lastError = sprintf(
                    '%s: %s',
                    $result['status'] ?? $response->code,
                    $result['detail']
                );

                // Include Mailchimp's field-specific validation errors.
                if (!empty($result['errors']) && is_array($result['errors'])) {
                    foreach ($result['errors'] as $error) {
                        $field = $error['field'] ?? 'unknown field';
                        $message = $error['message'] ?? 'Unknown validation error';

                        $this->lastError .= sprintf(
                            ' | Field: %s - %s',
                            $field,
                            $message
                        );
                    }
                }
            } else {
                $this->lastError = sprintf(
                    'Mailchimp API returned HTTP status %d.',
                    $response->code
                );
            }

            return $result ?: false;

        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }
}
