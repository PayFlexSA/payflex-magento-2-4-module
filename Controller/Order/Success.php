<?php

namespace Payflex\Gateway\Controller\Order;

class Success extends CommonAction
{
    public function execute()
    {
        $this->_logger->info(__METHOD__);
        $this->success();
    }
}
