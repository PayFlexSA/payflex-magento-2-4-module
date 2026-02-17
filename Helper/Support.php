<?php

namespace Payflex\Gateway\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;


class Support extends AbstractHelper
{
    /**
     * @var \Payflex\Gateway\Helper\Communication
     */
    private $communication;

    private $limits;

    protected $orderCollectionFactory;

    private $objectManager;

    private $timezone;

    private $github_latest_version;

    private $configuration;



    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->objectManager = $objectManager;
        $context = $objectManager->get('Magento\Framework\App\Helper\Context');

        // Get system timezone
        $timezone = $objectManager->create('Magento\Framework\Stdlib\DateTime\TimezoneInterface');
        $timezone->getConfigTimezone();
        $timezone->date();

        $timezone = new \DateTimeZone($timezone->getConfigTimezone());
        $this->timezone = $timezone;

        $date = $objectManager->get('Magento\Framework\Stdlib\DateTime\DateTime');
        $this->communication = new Communication($context, $date);
        $this->configuration = $objectManager->get("\Payflex\Gateway\Helper\Configuration");
    }

    public function getMagentoVersion()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
        return $productMetadata->getVersion();
    }

    public function getModuleVersion()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $moduleList = $objectManager->get('Magento\Framework\Module\ModuleListInterface');
        return $moduleList->getOne('Payflex_Gateway')['setup_version'];
    }

    public function getPHPVersion()
    {
        $version = phpversion();
        $parts = explode('.', $version);
        
        if (count($parts) > 2) {
            return $parts[0] . '.' . $parts[1] . '<span style="color: lightgray;">.' . implode('.', array_slice($parts, 2)) . '</span>';
        }
        
        return $version;
    }

    public function checkValidPayflexCredentials()
    {
        return TRUE;
    }

    public function getAccessToken()
    {
        // $this->communication->forceRefeshToken();
        $accessToken = $this->communication->getAccessToken();
        if($accessToken == null)
        {
            $this->communication->forceRefeshToken();
            $accessToken = $this->communication->getAccessToken();
        }

        if($accessToken == null)
        {
            return FALSE;
        }

        return $this->communication->getAccessToken();
    }

    public function getFormattedPayflexEnvironment()
    {
        $environment = $this->communication->getEnvironments();
        $current_env = $this->communication->getCurrentEnvironment('key');

        $current_env_text = '';
        
        // Format array into html string
        $html = "<div class='environment-table'>";
        foreach($environment as $env => $data)
        {
            if($env == $current_env)
            {
                $current_env_text = ' (current)';
            }
            $html .= "<div class='environment-row'>";
            $html .= "<div class='env-detail-row'>";
            $html .= "<span class='env-header'>Environment</span>";
            $html .= "<span class='env-detail'>: $env $current_env_text</span>";
            $html .= "</div>";
            foreach($data as $key => $value)
            {
                $html .= "<div class='env-detail-row'>";
                $html .= "<span class='env-header'>$key</span>";
                $html .= "<span class='env-detail'>: $value</span>";
                $html .= "</div>";
            }
            $html .= "</div class='environment-row'>";
            $current_env_text = '';
        }
        $html .= "</div>";

        return $html;
    }

    public function validAccessToken()
    {
        $accessToken = $this->communication->getAccessToken();
        if($accessToken == null || $accessToken == "")
        {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * This is currently only used to get the pending orders for the support page.
     * It's not used for any other functionality.
     * 
     * @param string|null $year Optional year to filter orders (YYYY format)
     * @param string|null $month Optional month to filter orders (1-12)
     * @param bool $onlyPending If true, only show pending orders. If false, show all orders.
     * @return array
     */
    public function getPendingOrders($year = null, $month = null, $onlyPending = true)
    {
        $pMethod = 'payflex_gateway';
        $orders = [];

        // Set date range based on parameters
        if ($year && $month) {
            $orderFromDateTime = date("Y-m-d H:i:s", strtotime($year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01 00:00:00'));
            $orderToDateTime = date("Y-m-d H:i:s", strtotime('last day of ' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . ' 23:59:59'));
        } else {
            // Default to last 24 hours
            $orderFromDateTime = date("Y-m-d H:i:s", strtotime('-24 hours'));
            $orderToDateTime = date("Y-m-d H:i:s", strtotime('0 minutes'));
        }

        $ocf = $this->orderCollectionFactory->create();
        $ocf->addAttributeToSelect( 'entity_id');
        $ocf->addAttributeToSelect('increment_id');
        $ocf->addAttributeToSelect('store_id');
        
        // Only filter by pending_payment status if onlyPending is true
        if ($onlyPending) {
            $ocf->addAttributeToFilter( 'status', ['eq' => 'pending_payment'] );
        }
        
        $ocf->addFieldToFilter('created_at', array('from' => $orderFromDateTime, 'to' => $orderToDateTime));
        $ocf->getSelect()
        ->join(
            ["sop" => "sales_order_payment"],
            'main_table.entity_id = sop.parent_id',
            array('method')
        )
        ->where('sop.method = ?',$pMethod );
        $ocf->setOrder(
          'increment_id',
          'desc'
        );
        
        foreach($ocf->getData() as $field => $value)
        {
            $current_order = $this->objectManager->get( '\Magento\Sales\Model\Order' )->loadByIncrementId( $value['increment_id'] );
            $id = $value['increment_id'];

            // Check order status
            $order_status = $current_order->getStatus();
            $orders[$id]['order_status'] = $order_status;

            
            $orders[$id]['order_id'] = $value['increment_id'];
            $orders[$id]['store_id'] = $value['store_id'];

            // Get order date
            $order_date = '';
            
            $order = $this->objectManager->create('Magento\Sales\Model\Order')->load($value['entity_id']);
            $order_date = $order->getCreatedAt();
            $orders[$id]['order_date'] = $order_date;

            $cron_check_time = date("Y-m-d H:i:s", strtotime('+30 minutes', strtotime($order_date)));

            // Check the time from now till the cron check time for this order and output the difference in human readable format (example "Cron will check in 30 minutes")
            // Get current system timezone
            $timezone = $this->objectManager->create('Magento\Framework\Stdlib\DateTime\TimezoneInterface');
            $timezone->getConfigTimezone();
            $timezone->date();
            $now            = new \DateTime('now', $this->timezone);
            $checkTime      = new \DateTime($cron_check_time);
            $interval       = $now->diff($checkTime);
            $cron_till_time = '';
            
            if ($checkTime > $now) {
                $cron_till_time = "Cron will start checking in " . $interval->format('%i minute(s)');
            } else {
                // Check if it's been more than 1 hour since the cron check time
                $hoursDiff = $interval->h + ($interval->days * 24);
                if ($hoursDiff >= 1 || $interval->days > 0) {
                    $cron_till_time = "Should have been checked " . $this->get_date_diff($checkTime->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')) . " ago";
                } else {
                    $cron_till_time = "Currently in queue";
                }
            }

            $orders[$id]['cron_check_time'] = $cron_check_time;
            $orders[$id]['cron_till_time'] = $cron_till_time;

            $order_link = '';
            $order_link = $this->objectManager->get('Magento\Store\Model\StoreManagerInterface')->getStore($value['store_id'])->getBaseUrl() . "admin/sales/order/view/order_id/" . $value['entity_id'];
            $orders[$id]['order_link'] = $order_link;
        }
        return $orders;
    }

    public function formatPrice($price)
    {
        // If not a valid number, return as is
        if (!is_numeric($price)) {
            return $price;
        }

        $storeManager = $this->objectManager->get('Magento\Store\Model\StoreManagerInterface');
        $storeCurrency = $storeManager->getStore()->getCurrentCurrency();
        $symbol = $storeCurrency->getCurrencySymbol() ?: $storeCurrency->getCode();

        return $symbol . number_format($price, 2);
    }

    public function getPayflexLimits($field = null, $fallback_value = null)
    {
        if($this->limits)
        {
            if($field) return $this->limits[$field] ?? $fallback_value;
            return $this->limits;
        }


        $storeId      = $this->objectManager->get('Magento\Store\Model\StoreManagerInterface')->getStore()->getId();
        $limits       = $this->communication->getMerchantConfiguration($storeId);
        $this->limits = $limits;

        if($field) return $limits[$field] ?? $fallback_value;

        return $limits;
    }

    public function numberOfStores()
    {
        $storeManager = $this->objectManager->get('Magento\Store\Model\StoreManagerInterface');
        $stores = $storeManager->getStores();
        return count($stores);
    }

    /**
     * Get the last time the Magento cron was run, in human readable. This is the last time the main magento cron was run, not the payflex cron
     */
    public function getLastCronRanTime()
    {
        $cron_schedule = $this->objectManager->create('Magento\Cron\Model\ResourceModel\Schedule\Collection');
        $cron_schedule->addFieldToFilter('job_code', 'payflex_gateway_check_order_status');
        $cron_schedule->addFieldToFilter('status', 'success');
        $cron_schedule->setOrder('executed_at', 'desc');
        $cron_schedule->setPageSize(1);
        $cron_schedule->setCurPage(1);
        $cron_schedule->load();
        $last_cron_time = '';
        foreach($cron_schedule as $cron)
        {

            $last_cron_time = $cron->getExecutedAt();
        }

        if($last_cron_time == '')
        {
            return "Cron has not been run yet";
        }

        // Get current system timezone
        $timezone = $this->objectManager->create('Magento\Framework\Stdlib\DateTime\TimezoneInterface');
        $timezone->getConfigTimezone();
        $timezone->date();
        $now            = new \DateTime('now');
        $checkTime      = new \DateTime($last_cron_time);
        
        // use get_date_diff
        $cron_till_time = $this->get_date_diff($checkTime->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')). " ago";

        return $cron_till_time;
    }

    /**
     * Get the difference between 2 dates in human readable format
     */
    private function get_date_diff( $time1, $time2, $precision = 2 ) {
        // If not numeric then convert timestamps
        if( !is_int( $time1 ) ) {
            $time1 = strtotime( $time1 );
        }
        if( !is_int( $time2 ) ) {
            $time2 = strtotime( $time2 );
        }
    
        // If time1 > time2 then swap the 2 values
        if( $time1 > $time2 ) {
            list( $time1, $time2 ) = array( $time2, $time1 );
        }
    
        // Set up intervals and diffs arrays
        $intervals = array( 'year', 'month', 'day', 'hour', 'minute', 'second' );
        $diffs = array();
    
        foreach( $intervals as $interval ) {
            // Create temp time from time1 and interval
            $ttime = strtotime( '+1 ' . $interval, $time1 );
            // Set initial values
            $add = 1;
            $looped = 0;
            // Loop until temp time is smaller than time2
            while ( $time2 >= $ttime ) {
                // Create new temp time from time1 and interval
                $add++;
                $ttime = strtotime( "+" . $add . " " . $interval, $time1 );
                $looped++;
            }
    
            $time1 = strtotime( "+" . $looped . " " . $interval, $time1 );
            $diffs[ $interval ] = $looped;
        }
    
        $count = 0;
        $times = array();
        foreach( $diffs as $interval => $value ) {
            // Break if we have needed precission
            if( $count >= $precision ) {
                break;
            }
            // Add value and interval if value is bigger than 0
            if( $value > 0 ) {
                if( $value != 1 ){
                    $interval .= "s";
                }
                // Add value and interval to times array
                $times[] = $value . " " . $interval;
                $count++;
            }
        }
    
        // Return string with times
        return implode( ", ", $times );
    }

    public function get_github_version()
    {
        if($this->github_latest_version) {
            return $this->github_latest_version;
        }

        $url = "https://api.github.com/repos/PayFlexSA/payflex-magento-2-4-module/releases/latest";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3');
        $output = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($output, true);

        $this->github_latest_version = $data['tag_name'] ?? null;
        return $this->github_latest_version;
    }

    public function get_github_version_comparison($current_version)
    {
        $latest_version = $this->get_github_version();
        if (!$latest_version) {
            return "Unable to fetch latest version";
        }

        // Strip 'v' prefix if it exists for comparison
        $current_version = ltrim($current_version, 'v');
        $latest_version  = ltrim($latest_version, 'v');

        if (version_compare($current_version, $latest_version, '<')) {
            return "Update available: " . "<a href='" . $this->get_github_latest_link() . "' target='_blank'>v". $latest_version ."</a>";
        } elseif (version_compare($current_version, $latest_version, '>')) {
            return "You are ahead of the latest release: v" . $latest_version;
        } else {
            return "You are on the latest version";
        }
    }

    public function get_github_latest_link()
    {
        return "https://github.com/PayFlexSA/payflex-magento-2-4-module/releases/latest";
    }

    public function get_payflex_install_type()
    {
        $isComposerInstall = false;

        // If it's a composer install, this module wouldn't be in the app/code directory, it would be in the vendor directory.
        $modulePath = $this->objectManager->get('Magento\Framework\Module\Dir')->getDir('Payflex_Gateway');

        if (strpos($modulePath, 'vendor/payflex') !== false) $isComposerInstall = true;

        $magento_base_path = $this->objectManager->get('Magento\Framework\App\Filesystem\DirectoryList')->getRoot();
        
        $moduleRelPath = str_replace($magento_base_path, '', $modulePath);

        $install_directory = ' ('.$moduleRelPath.')';

        return $isComposerInstall ? 'Composer'.$install_directory : 'Manual'.$install_directory;
    }
}
