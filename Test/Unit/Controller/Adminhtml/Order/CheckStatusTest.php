<?php

namespace Payflex\Gateway\Test\Unit\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Payflex\Gateway\Controller\Adminhtml\Order\CheckStatus;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\OrderStatusManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Check Status can invoice or cancel an order, so it only answers POST requests.
 */
class CheckStatusTest extends TestCase
{
    /** @var HttpRequest&MockObject */
    private $request;

    /** @var OrderStatusManager&MockObject */
    private $statusManager;

    /** @var array */
    private $data;

    /** @var CheckStatus */
    private $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->statusManager = $this->createMock(OrderStatusManager::class);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->data = $data;
            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);

        $this->controller = new CheckStatus(
            $context,
            $jsonFactory,
            $this->statusManager,
            $this->createMock(PayflexLogger::class)
        );
    }

    public function testGetRequestIsRefused(): void
    {
        $this->request->method('isPost')->willReturn(false);
        $this->request->method('getParam')->with('order_id')->willReturn('5');
        $this->statusManager->expects($this->never())->method('checkAndApply');
        $this->statusManager->expects($this->never())->method('checkStatus');

        $this->controller->execute();

        $this->assertFalse($this->data['success']);
        $this->assertSame('Invalid request method.', $this->data['message']);
    }

    public function testPostChecksAndAppliesTheStatus(): void
    {
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getParam')->with('order_id')->willReturn('5');
        $this->statusManager->expects($this->once())
            ->method('checkAndApply')
            ->with(5)
            ->willReturn(['success' => true, 'updated' => true]);

        $this->controller->execute();

        $this->assertSame(['success' => true, 'updated' => true], $this->data);
    }

    public function testMissingOrderIdIsRefused(): void
    {
        $this->request->method('isPost')->willReturn(true);
        $this->request->method('getParam')->with('order_id')->willReturn(null);
        $this->statusManager->expects($this->never())->method('checkAndApply');

        $this->controller->execute();

        $this->assertSame('Missing order id.', $this->data['message']);
    }
}
