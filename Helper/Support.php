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

    protected $orderCollectionFactory;

    private $objectManager;

    private $timezone;



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
        return phpversion();
    }

    public function checkValidPayflexCredentials()
    {
        return TRUE;
    }

    public function getAccessToken()
    {
        $this->communication->forceRefeshToken();
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
        $environment = $this->communication->environments;
        
        // Format array into html string
        $html = "<div class='environment-table'>";
        foreach($environment as $env => $data)
        {
            $html .= "<div class='environment-row'>";
            $html .= "<div class='env-detail-row'>";
            $html .= "<span class='env-header'>Environment</span>";
            $html .= "<span class='env-detail'>: $env</span>";
            $html .= "</div>";
            foreach($data as $key => $value)
            {
                $html .= "<div class='env-detail-row'>";
                $html .= "<span class='env-header'>$key</span>";
                $html .= "<span class='env-detail'>: $value</span>";
                $html .= "</div>";
            }
            $html .= "</div class='environment-row'>";;
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

    public function getPendingOrders()
    {
        $pMethod = 'payflex_gateway';
        $orders = [];

        $orderFromDateTime = date("Y-m-d H:i:s", strtotime('-24 hours'));
        $orderToDateTime = date("Y-m-d H:i:s", strtotime('0 minutes'));
        $ocf = $this->orderCollectionFactory->create();
        $ocf->addAttributeToSelect( 'entity_id');
        $ocf->addAttributeToSelect('increment_id');
        $ocf->addAttributeToSelect('store_id');
        $ocf->addAttributeToFilter( 'status', ['eq' => 'pending_payment'] );
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
                $cron_till_time = "Currently in queue";
            }

            $orders[$id]['cron_check_time'] = $cron_check_time;
            $orders[$id]['cron_till_time'] = $cron_till_time;

            $order_link = '';
            $order_link = $this->objectManager->get('Magento\Store\Model\StoreManagerInterface')->getStore($value['store_id'])->getBaseUrl() . "admin/sales/order/view/order_id/" . $value['entity_id'];
            $orders[$id]['order_link'] = $order_link;
        }
        return $orders;
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
}
