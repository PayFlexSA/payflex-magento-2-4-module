<?php

namespace Payflex\Gateway\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * One row per checkout attempt: the Payflex session token, redirect URL and the
 * link between the Magento order increment id and the Payflex order id.
 */
class RequestToken extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Payflex\Gateway\Model\ResourceModel\RequestToken::class);
    }
}
