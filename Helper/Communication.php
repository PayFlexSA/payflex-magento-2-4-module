<?php

namespace Payflex\Gateway\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestAccessTokenFactory;

/**
 * Low-level Payflex API client: OAuth token handling and the raw HTTP calls to
 * the hosted-checkout, transaction-status, refund and configuration endpoints.
 */
class Communication extends AbstractHelper
{
    /**
     * @var DateTime
     */
    private $_date;

    /**
     * @var Configuration
     */
    private $_configuration;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var RequestAccessTokenFactory
     */
    private $accessTokenFactory;

    /**
     * Hard-coded Payflex environments. Kept public for the admin support page.
     *
     * @var array
     */
    public $environments;

    /**
     * In-memory cache of merchant configuration, keyed by store id.
     *
     * @var array
     */
    private $merchantConfiguration = [];

    public function __construct(
        Context $context,
        DateTime $date,
        PayflexLogger $logger,
        Configuration $configuration,
        StoreManagerInterface $storeManager,
        RequestAccessTokenFactory $accessTokenFactory
    ) {
        parent::__construct($context);
        $this->_logger = $logger;
        $this->_configuration = $configuration;
        $this->storeManager = $storeManager;
        $this->accessTokenFactory = $accessTokenFactory;
        $this->_date = $date;

        $this->environments = [
            'develop' => [
                "name"          => "Sandbox Test",
                "api_url"       => "https://api.uat.payflex.co.za",
                "auth_url"      => "https://auth-uat.payflex.co.za/auth/merchant",
                "web_url"       => "https://api.uat.payflex.co.za",
                "auth_audience" => "https://auth-dev.payflex.co.za",
            ],
            'production' => [
                "name"          => "Production",
                "api_url"       => "https://api.payflex.co.za",
                "auth_url"      => "https://auth.payflex.co.za/auth/merchant",
                "web_url"       => "https://api.payflex.co.za",
                "auth_audience" => "https://auth-production.payflex.co.za",
            ],
        ];
    }

    public function getPayflexPage($requestData, $storeId = null)
    {
        $this->_logger->info(__METHOD__);
        if ($storeId === null) {
            $storeId = $this->storeManager->getStore()->getId();
        }
        $orderIncrementId = $requestData['merchantReference'];
        $requestData['merchant']['redirectConfirmUrl'] = $this->_getUrl('payflex/order/success', ['_secure' => true, '_nosid' => true, 'mage_order_id' => $orderIncrementId]);
        $requestData['merchant']['redirectCancelUrl'] = $this->_getUrl('payflex/order/fail', ['_secure' => true, '_nosid' => true, 'mage_order_id' => $orderIncrementId]);

        if ($this->_configuration->getWebhookEnabled($storeId)) {
            $callbackUrl = $this->_getUrl('payflex/webhook/notify', [
                '_secure'       => true,
                '_nosid'        => true,
                'mage_order_id' => $orderIncrementId,
                'key'           => $this->getWebhookKey($orderIncrementId, $storeId),
            ]);

            if (strpos($callbackUrl, 'https://') === 0) {
                $requestData['merchant']['statusCallbackUrl'] = $callbackUrl;
            } else {
                $this->_logger->critical(__METHOD__ . " webhook URL is not https, not sending it: {$callbackUrl}");
            }
        }

        $requestData = json_encode($requestData);

        $this->_logger->info(__METHOD__ . " request: " . $requestData);
        $url = $this->_getApiUrl('/order/productSelect', $storeId);
        $header = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->getAccessToken($storeId)];

        $result = $this->sendRequest($url, $header, [], 'POST', $requestData);
        $this->_logger->info(__METHOD__ . " getPayflexPage response: " . $result['response']);
        return json_decode($result['response'], true);
    }

    public function getEnvironments($storeId = null)
    {
        return $this->environments;
    }

    /**
     * Key included in the status callback URL so the webhook can tell a genuine
     * Payflex callback from an anonymous request.
     */
    public function getWebhookKey($orderIncrementId, $storeId = null)
    {
        if ($storeId === null) {
            $storeId = $this->storeManager->getStore()->getId();
        }

        $secret = (string) $this->_configuration->getPayflexClientSecret($storeId);

        return hash_hmac('sha256', (string) $orderIncrementId, $secret);
    }

    /**
     * Gets the value of a field of the current environment.
     * For example, getCurrentEnvironment('api_url') will return the api_url of the current environment.
     * If field_name is 'key', it will return the key of the current environment (eg: develop or production).
     *
     * @param string   $field_name - the name of the field to get (eg: api_url, auth_url, name, key)
     * @param int|null $storeId - the store ID to get the environment for. If null, it will use the current store.
     *
     * @return string - the value of the field for the current environment, or 'Unknown' if not found.
     */
    public function getCurrentEnvironment($field_name = 'name', $storeId = null)
    {
        $environment = 'Unknown';
        if ($storeId === null) {
            $storeId = $this->storeManager->getStore()->getId();
        }

        // getPayflexEnvironment only returns an array key number, not the actual key
        $environemnt_key_position = $this->_configuration->getPayflexEnvironment($storeId);

        $indexed_environments = array_values($this->environments);

        $current_environment = $indexed_environments[$environemnt_key_position] ?? 'Unknown';
        $environment         = $current_environment[$field_name] ?? 'Unknown';

        if ($field_name == 'key') {
            $environment_keys = array_keys($this->environments);
            $environment = $environment_keys[$environemnt_key_position] ?? 'Unknown';
        }

        return $environment;
    }

    public function getTransactionStatus($payflexId, $storeId = null)
    {
        if ($storeId === null) {
            $storeId = $this->storeManager->getStore()->getId();
        }
        $this->_logger->info(__METHOD__ . " payflexId:{$payflexId} storeId:{$storeId}");
        $payflexUrl = $this->_getApiUrl('/order/' . $payflexId, $storeId);
        $header = ['Authorization: Bearer ' . $this->getAccessToken($storeId)];
        $result = $this->sendRequest($payflexUrl, $header);
        return json_decode($result['response'], true);
    }

    public function refund($orderIncrementId, $payflexId, $amount, $storeId = null)
    {
        if ($storeId === null) {
            $storeId = $this->storeManager->getStore()->getId();
        }
        $this->_logger->info(__METHOD__ . "order:{$orderIncrementId} payflexId:{$payflexId} storeId:{$storeId}");

        $requestData = json_encode([
            'amount'                  => $amount,
            'isPlugin'                => true,
            'merchantRefundReference' => $orderIncrementId . '-' . $amount . ' ' . $this->_date->date(),
        ]);
        $this->_logger->info(__METHOD__ . " request: " . $requestData);
        $payflexUrl = $this->_getApiUrl('/order/' . $payflexId . '/refund/', $storeId);

        $header = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->getAccessToken($storeId)];

        return $this->sendRequest($payflexUrl, $header, [], 'POST', $requestData);
    }

    public function getMerchantConfiguration($storeId)
    {
        if (isset($this->merchantConfiguration[$storeId])) {
            return $this->merchantConfiguration[$storeId];
        }

        $this->_logger->info(__METHOD__ . "storeId:{$storeId}");
        $payflexUrl = $this->_getApiUrl('/configuration', $storeId);

        $header = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->getAccessToken($storeId)];
        $result = $this->sendRequest($payflexUrl, $header);

        $this->merchantConfiguration[$storeId] = json_decode($result['response'], true);

        return $this->merchantConfiguration[$storeId];
    }

    public function forceRefeshToken($storeId = null)
    {
        $this->_logger->info(__METHOD__ . " Refreshing Token");
        $this->getAccessToken($storeId, true);
    }

    public function getAccessToken($storeId = null, $force = false)
    {
        if ($storeId === null) {
            $storeId = $this->storeManager->getStore()->getId();
        }

        $storedAccessTokenModel = $this->accessTokenFactory->create()->load($storeId, "store_id");

        if ($force) {
            $storedAccessTokenModel->delete();
        }

        $date_now = time();
        $expireDate = $storedAccessTokenModel->getExpire();
        if ($expireDate != '' && $date_now < strtotime($expireDate) && !$force && !empty($storedAccessTokenModel->getToken())) {
            return $storedAccessTokenModel->getToken();
        }

        // Fetch a fresh token and persist it.
        $freshToken = $this->getTokenApiCall($storeId);
        $newtimestamp = strtotime(date("Y-m-d H:i:s") . ' + 3500 second');
        $expireTime = date('Y-m-d H:i:s', $newtimestamp);

        if ($expireDate != '') {
            // Update existing entry
            $storedAccessTokenModel->setToken($freshToken);
            $storedAccessTokenModel->setExpire($expireTime);
            $storedAccessTokenModel->save();
        } else {
            // Insert new entry
            $requestTokenModel = $this->accessTokenFactory->create();
            $requestTokenModel->setData([
                "store_id" => $storeId,
                "token"    => $freshToken,
                "expire"   => $expireTime,
            ]);
            $requestTokenModel->save();
        }

        return $freshToken;
    }

    protected function getTokenApiCall($storeId)
    {
        $this->_logger->info('getTokenApiCall' . $storeId);

        // Use the auth details of the selected environment (production vs sandbox).
        if ($this->_configuration->getPayflexEnvironment($storeId)) {
            $AuthURL  = $this->environments['production']['auth_url'];
            $Audience = $this->environments['production']['auth_audience'];
        } else {
            $AuthURL  = $this->environments['develop']['auth_url'];
            $Audience = $this->environments['develop']['auth_audience'];
        }
        $accessTokenParam = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->_configuration->getPayflexClientId($storeId),
            'client_secret' => $this->_configuration->getPayflexClientSecret($storeId),
            'audience'      => $Audience,
        ];

        $headers = ['Content-Type: application/json'];

        try {
            $accessTokenResult = $this->sendRequest($AuthURL, $headers, [], 'POST', json_encode($accessTokenParam));
            $response = json_decode($accessTokenResult['response'], true);
        } catch (\Exception $ex) {
            $this->_logger->error($ex->getMessage());
            return false;
        }
        if (!$response || !isset($response['access_token'])) {
            return false;
        }
        return $response['access_token'];
    }

    protected function _getApiUrl($path, $storeId = null)
    {
        if ($this->_configuration->getPayflexEnvironment($storeId)) {
            $baseUrl = $this->environments['production']['api_url'];
        } else {
            $baseUrl = $this->environments['develop']['api_url'];
        }
        return rtrim($baseUrl, '/') . '/' . trim($path, '/');
    }

    /**
     * Perform the HTTP request via cURL. Protected so unit tests can replace it.
     */
    protected function sendRequest($url, $header = [], $params = [], $method = 'GET', $postBody = null)
    {
        $this->_logger->info(__METHOD__ . " postUrl: {$url}");
        $ch = curl_init();
        switch ($method) {
            case "POST":
                curl_setopt($ch, CURLOPT_POST, 1);
                if ($postBody) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
                }
                break;
            case "PUT":
                curl_setopt($ch, CURLOPT_PUT, 1);
                break;
            default:
                if (!empty($params)) {
                    $url = sprintf("%s?%s", $url, http_build_query($params));
                }
        }
        curl_setopt($ch, CURLOPT_URL, $url);

        if (!empty($header)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }

        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $errorNo = curl_errno($ch);
        $errorMessage = '';
        if ($errorNo) {
            $errorMessage = " Error:" . curl_error($ch) . " Error Code:" . curl_errno($ch);
            $this->_logger->critical(__METHOD__ . $errorMessage);
        }
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpcode && substr($httpcode, 0, 2) != "20") {
            $errorMessage = " HTTP CODE: {$httpcode} for URL: {$url}";
            $this->_logger->critical(__METHOD__ . $errorMessage);
        }
        $result = [
            'httpcode' => $httpcode,
            'response' => $response,
            'errmsg' => $errorMessage,
        ];
        curl_close($ch);
        $this->_logger->info(__METHOD__ . " response from Payflex - HttpCode:{$httpcode} Body:{$response}");
        return $result;
    }
}
