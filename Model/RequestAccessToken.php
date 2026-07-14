<?php

namespace Payflex\Gateway\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Cached Payflex OAuth access token, one row per store.
 */
class RequestAccessToken extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Payflex\Gateway\Model\ResourceModel\RequestAccessToken::class);
    }
}
