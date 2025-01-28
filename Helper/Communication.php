<?php

namespace Payflex\Gateway\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

class Communication extends AbstractHelper
{
    private $_accessToken;

    private $_date;

    /**
     *
     * @var \Payflex\Gateway\Helper\Configuration
     */
    private $_configuration;
    /**
     * @var DirectoryList
     */
    private $directoryList;
    private $storeManager;
    public $environments;
    public function __construct(Context $context, \Magento\Framework\Stdlib\DateTime\DateTime $date)
    {
        parent::__construct($context);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_configuration = $objectManager->get("\Payflex\Gateway\Helper\Configuration");
        $this->directoryList = $objectManager->get("Magento\Framework\App\Filesystem\DirectoryList");
        $this->_logger->info('Communication : '.__METHOD__);
        $this->_accessToken = null;
        $this->_date = $date;
        $this->storeManager = $objectManager->get("\Magento\Store\Model\StoreManagerInterface");  
        $this->environments = [
            'develop' => array(
                "name"		=>	"Sandbox Test",
                "api_url"	=>	"https://api.uat.payflex.co.za",
                "auth_url"  =>  "https://auth-uat.payflex.co.za/auth/merchant",
                "web_url"	=>	"https://api.uat.payflex.co.za",
                "auth_audience" => "https://auth-dev.payflex.co.za",
            ),
            'production' => 	array(
                "name"		=>	"Production",
                "api_url"	=>	"https://api.payflex.co.za",
                "auth_url"  =>  "https://auth.payflex.co.za/auth/merchant",
                "web_url"	=>	"https://api.payflex.co.za",
                "auth_audience" => "https://auth-production.payflex.co.za",
            )
        ];
    }
    public function getPayflexPage($requestData, $storeId = null)
    {
        $this->_logger->info(__METHOD__);
        $storeId = $this->storeManager->getStore()->getId();
        $orderIncrementId = $requestData['merchantReference'];
        $requestData['merchant']['redirectConfirmUrl'] = $this->_getUrl('payflex/order/success', ['_secure' => true, '_nosid' => true, 'mage_order_id' => $orderIncrementId]);
        $requestData['merchant']['redirectCancelUrl'] = $this->_getUrl('payflex/order/fail', ['_secure' => true, '_nosid' => true, 'mage_order_id' => $orderIncrementId]);
        $requestData = json_encode($requestData);

        $this->_logger->info(__METHOD__ . " request: ". $requestData);
        $url = $this->_getApiUrl('/order/productSelect', $storeId);
        $header = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->getAccessToken($storeId)];

        /**
         * If magento version is under 2.4.6 then use ZendClient::POST. 2.4.6 and above use Laminas\Http\Request::METHOD_POST
         * If you have linting enabled, you will probably see an error with one of the two lines below.
         * This is because the linter is not aware of the versionCheck method and will assume the method does not exist.
        */ 
        if(!$this->_configuration->versionCheck('2.4.6'))
        {
            $post = \Magento\Framework\HTTP\ZendClient::POST;
        }
        else{
            $post = \Laminas\Http\Request::METHOD_POST;
        }

        $result = $this->_sendRequest($url, $header, [], $post, $requestData);
        $this->_logger->info(__METHOD__ . " getPayflexPage response: ". $result['response']);
        return json_decode($result['response'], true);
    }

    public function getTransactionStatus($payflexId, $storeId = null)
    {
        if($storeId == null){
            $storeId = $this->storeManager->getStore()->getId();
        }
        $this->_logger->info(__METHOD__ . " payflexId:{$payflexId} storeId:{$storeId}");
        $payflexUrl = $this->_getApiUrl('/order/'. $payflexId, $storeId);
        $header = ['Authorization: Bearer ' . $this->getAccessToken($storeId)];
        $result = $this->_sendRequest($payflexUrl, $header);
        return json_decode($result['response'], true);
    }

    public function refund($orderIncrementId, $payflexId, $amount, $storeId = null)
    {
        $storeId = $this->storeManager->getStore()->getId();
        $this->_logger->info(__METHOD__ . "order:{$orderIncrementId} payflexId:{$payflexId} storeId:{$storeId}");

        $requestData = json_encode([
            'amount'=> $amount,
            'merchantRefundReference' => $orderIncrementId.'-'.$amount.' '.$this->_date->date(),
            ]);
        $this->_logger->info(__METHOD__ . " request: ". $requestData);
        $payflexUrl = $this->_getApiUrl('/order/' . $payflexId . '/refund/', $storeId);

        $header = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->getAccessToken($storeId)];

        if(!$this->_configuration->versionCheck('2.4.6'))
        {
            $post = \Magento\Framework\HTTP\ZendClient::POST;
        }else{
            $post = \Laminas\Http\Request::METHOD_POST;
        }

        $result = $this->_sendRequest($payflexUrl, $header, [],$post, $requestData);
        return $result;
    }

    public function getMerchantConfiguration($storeId)
    {
        
        $this->_logger->info(__METHOD__ . "storeId:{$storeId}");
        $payflexUrl = $this->_getApiUrl('/configuration', $storeId);

        $header = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->getAccessToken($storeId)];
        $result = $this->_sendRequest($payflexUrl, $header);
        return json_decode($result['response'], true);
    }

    public function forceRefeshToken($storeId = null)
    {
        $this->_logger->info(__METHOD__. " Refreshing Token");
        $this->getAccessToken($storeId, true);
    }

    public function getAccessToken($storeId = null, $force = false)
    {
        if($storeId == null){
            $storeId = $this->storeManager->getStore()->getId();
        }

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storedAccessToken = $objectManager->create("\Payflex\Gateway\Model\RequestAccessToken");
        $storedAccessTokenModel = $storedAccessToken->load($storeId, "store_id");

        if($force)
        {
            // Delete Existing Entry
            $storedAccessTokenModel->delete();
        }

        $date_now = time();
        $expireDate = $storedAccessTokenModel->getExpire();
        if ( $expireDate != '' && $date_now < strtotime($expireDate) && !$force && !empty($storedAccessTokenModel->getToken())) {
            $this->_logger->info('Stored Token from DB : '.$storedAccessTokenModel->getToken());
            return $storedAccessTokenModel->getToken();
        }else{
            //   return fresh token to DB
            $freshToken = $this->getTokenApiCall($storeId);
            $newtimestamp = strtotime( date("Y-m-d H:i:s") .' + 3500 second');
            $expireTime =  date('Y-m-d H:i:s', $newtimestamp);

            if($expireDate != ''){
                // Update Existing Entry
                $storedAccessTokenModel->setToken( $freshToken );
                $storedAccessTokenModel->setExpire($expireTime);
                $storedAccessTokenModel->save();
                
                // Update Existing Entry
            }else{

                // Insert New Entry
                    $this->_logger->info("Get fresh token from Auth0". $freshToken);
                    $requestTokenModel = $objectManager->create("\Payflex\Gateway\Model\RequestAccessToken");
                    $requestTokenModel->setData(
                        array(
                            "store_id"=> $storeId,
                            "token" =>  $freshToken,
                            "expire" => $expireTime
                        
                        ));
                    $requestTokenModel->save();
                // Insert New Entry
            }
            
            return $freshToken;
            //return fresh token to DB
        }
    }
    protected function getTokenApiCall($storeId){
        $this->_logger->info('getTokenApiCall'.$storeId);
        $AuthURL = '';
		$Audience = '';
        /*** 
            Use auth_url and auth_audience from produciton if selected envrionment is producitonelse use it from develp.
        ***/   
		if($this->_configuration->getPayflexEnvironment($storeId)) {
            $AuthURL = $this->environments['production']['auth_url'];
			$Audience = $this->environments['production']['auth_audience'];
		} else {
			$AuthURL = $this->environments['develop']['auth_url'];
			$Audience = $this->environments['develop']['auth_audience'];
		}
        $accessTokenParam = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->_configuration->getPayflexClientId($storeId),
            'client_secret' => $this->_configuration->getPayflexClientSecret($storeId),
            'audience' => $Audience,
        ];

        $headers = [
            'Content-Type: application/json'
        ];
        // $url = $this->_configuration->getPayflexAuthTokenEndpoint($storeId);

        try {
            # Check if magento version is 2.4.
            if(!$this->_configuration->versionCheck('2.4.6'))
            {
                $post = \Magento\Framework\HTTP\ZendClient::POST;
            }else{
                $post = \Laminas\Http\Request::METHOD_POST;
            }
            $accessTokenResult = $this->_sendRequest($AuthURL, $headers, [], $post, json_encode($accessTokenParam));
            $response = json_decode($accessTokenResult['response'], true);
            $this->_logger->info('getAccessTokencalled');
        } catch (\Exception $ex) {
            $this->_logger->error($ex->getMessage());
            return false;
        }
        if (!$response || !isset($response['access_token'])) {
            $errorMessage = 'Error getting access token from PayFlex';
            // throw new \Magento\Framework\Exception\PaymentException(__($errorMessage));
            return false;
        }
        return $response['access_token'];
    }
    protected function _getApiUrl($path, $storeId = null)
    {
        if($this->_configuration->getPayflexEnvironment($storeId)) {
			$baseUrl = $this->environments['production']['api_url'];
		}else{
            $baseUrl = $this->environments['develop']['api_url'];
        }
        // $baseUrl = $this->_configuration->getPayflexApiEndpoint($storeId);
        $apiUrl = rtrim($baseUrl, '/') . '/' . trim($path, '/');
        return $apiUrl;
    }

    private function _sendRequest($url, $header = [], $params = [], $method = '', $postBody = null)
    {
        if($method == ''){
            if(!$this->_configuration->versionCheck('2.4.6'))
            {
                $method = \Magento\Framework\HTTP\ZendClient::GET;
            }
            else{
                $method = \Laminas\Http\Request::METHOD_GET;
            }
        }
        $this->_logger->info(__METHOD__ . " postUrl: {$url}");
        $ch = curl_init();
        switch ($method) {
            case "POST":
                curl_setopt($ch, CURLOPT_POST, 1);

                if ($postBody)
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
                break;
            case "PUT":
                curl_setopt($ch, CURLOPT_PUT, 1);
                break;
            default:
                if (!empty($params))
                    $url = sprintf("%s?%s", $url, http_build_query($params));
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
        if($errorNo){
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
        $this->_logger->info(__METHOD__ . " response from PayFlex - HttpCode:{$httpcode} Body:{$response}");
        return $result;
    }
}
