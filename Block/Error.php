<?php

namespace Payflex\Gateway\Block;

use Magento\Framework\View\Element\Template\Context;

class Error extends \Magento\Framework\View\Element\Template
{

    public function __construct(
        Context $context,
        \Payflex\Gateway\Logger\PayflexLogger $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->_logger = $logger;
    }

    protected function _prepareLayout()
    {
        $this->setShowContinueButton(true);
        $error = $this->getRequest()->getParam("error");
        $this->_logger->info(__METHOD__ . " error:{$error}");
        $this->setError($error);
        return $this;
    }
}
