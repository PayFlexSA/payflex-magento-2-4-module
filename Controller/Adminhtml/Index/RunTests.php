<?php

namespace Payflex\Gateway\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Payflex\Gateway\Helper\Support;

/**
 * Admin AJAX endpoint that runs the module's unit-test suite and returns the
 * per-test results as JSON, for the "Unit Tests" accordion on the Payflex
 * Support page (view/adminhtml/templates/info.phtml).
 */
class RunTests extends Action
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Support
     */
    protected $support;

    /**
     * @param Context     $context
     * @param JsonFactory $resultJsonFactory
     * @param Support     $support
     */
    public function __construct(Context $context, JsonFactory $resultJsonFactory, Support $support)
    {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->support = $support;
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        try {
            return $result->setData($this->support->runUnitTests());
        } catch (\Throwable $e) {
            return $result->setData([
                'available' => false,
                'passed'    => false,
                'summary'   => 'The tests could not be run: ' . $e->getMessage(),
                'counts'    => ['total' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0],
                'suites'    => [],
                'exit_code' => null,
            ]);
        }
    }
}
