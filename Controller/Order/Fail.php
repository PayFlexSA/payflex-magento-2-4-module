<?php

namespace Payflex\Gateway\Controller\Order;

class Fail extends CommonAction
{
    public function execute()
    {
        $this->_logger->info(__METHOD__);
        $this->fail();
    }
}
