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

        $transport = parent::_prepareSpecificInformation($transport);
        $data = (array)$this->getInfo()->getAdditionalInformation();

        $labelMap = [
            'method_title'       => __('Payment Method'),
            'orderId'            => __('Payflex Order ID'),
            'orderStatus'        => __('Payflex Status'),
            'merchantReference'  => __('Merchant Reference'),
            'amount'             => __('Amount'),
            'currency'           => __('Currency'),
            'payflex_id'         => __('Payflex Order ID'),
        ];

        $hiddenKeys = [
            'items', 'billing', 'shipping', 'merchant',
            'cartId', 'guestEmail', 'token', 'expire', 'url'
        ];

        $display = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $hiddenKeys, true)) {
                continue;
            }

            $label = $labelMap[$key] ?? __(ucwords(str_replace('_', ' ', $key)));

            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }

            $display[(string)$label] = (string)$value;
        }

        // Optional fallback if method_title is missing
        if (!isset($display[(string)__('Payment Method')])) {
            $display[(string)__('Payment Method')] = 'Payflex';
        }

        return $transport->setData(array_merge($transport->getData(), $display));
    }
}
