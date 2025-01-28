<?php
namespace Payflex\Gateway\Block;

use Magento\Framework\View\Element\Template\Context;

class Info extends \Magento\Payment\Block\Info
{

    /**
     *
     * @var string
     */
    protected $_template = 'Payflex_Gateway::info/default.phtml';

    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get("\Payflex\Gateway\Logger\PayflexLogger");
        $this->_logger->info(__METHOD__);
    }

    protected function _prepareSpecificInformation($transport = null)
    {
        $this->_logger->info(__METHOD__);
        if (null !== $this->_paymentSpecificInformation) {
            return $this->_paymentSpecificInformation;
        }

        $data = $this->getInfo()->getAdditionalInformation();
        $decodedData = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['items', 'billing', 'shipping', 'merchant'])) {
                continue;
            }
            if (strtotime($key)) {
                $decodedValue = json_decode($value, true);
                if (!$decodedValue) {
                    $decodedData[$key] = $this->getValueAsArray($value, true);
                } else {
                    $decodedData[$key] = $decodedValue;
                }
            }
        	else {
        		$decodedData[$key] = $value;
        	}
        }

        $transport = parent::_prepareSpecificInformation($transport);

        $this->_paymentSpecificInformation = $transport->setData(array_merge($decodedData, $transport->getData()));

        return $this->_paymentSpecificInformation;
    }
}
