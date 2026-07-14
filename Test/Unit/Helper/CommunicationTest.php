<?php

namespace Payflex\Gateway\Test\Unit\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Payflex\Gateway\Helper\Communication;
use Payflex\Gateway\Helper\Configuration;
use Payflex\Gateway\Logger\PayflexLogger;
use Payflex\Gateway\Model\RequestAccessToken;
use Payflex\Gateway\Model\RequestAccessTokenFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CommunicationTest extends TestCase
{
    /** @var Configuration&MockObject */
    private $configuration;

    /** @var RequestAccessTokenFactory&MockObject */
    private $accessTokenFactory;

    protected function setUp(): void
    {
        $this->configuration = $this->createMock(Configuration::class);
        $this->accessTokenFactory = $this->createMock(RequestAccessTokenFactory::class);
    }

    /**
     * Build a Communication instance. Optionally replace the low-level transport
     * so tests never make a real HTTP call.
     */
    private function newCommunication(?array $sendResult = null): Communication
    {
        $context = $this->createMock(Context::class);

        $args = [
            $context,
            $this->createMock(DateTime::class),
            $this->createMock(PayflexLogger::class),
            $this->configuration,
            $this->createMock(StoreManagerInterface::class),
            $this->accessTokenFactory,
        ];

        if ($sendResult === null) {
            return new Communication(...$args);
        }

        /** @var Communication&MockObject $communication */
        $communication = $this->getMockBuilder(Communication::class)
            ->setConstructorArgs($args)
            ->onlyMethods(['sendRequest'])
            ->getMock();
        $communication->method('sendRequest')->willReturn($sendResult);

        return $communication;
    }

    public function testGetCurrentEnvironmentResolvesProduction(): void
    {
        $this->configuration->method('getPayflexEnvironment')->willReturn(1);
        $communication = $this->newCommunication();

        $this->assertSame('production', $communication->getCurrentEnvironment('key', 1));
        $this->assertSame('https://api.payflex.co.za', $communication->getCurrentEnvironment('api_url', 1));
    }

    public function testGetCurrentEnvironmentResolvesSandbox(): void
    {
        $this->configuration->method('getPayflexEnvironment')->willReturn(0);
        $communication = $this->newCommunication();

        $this->assertSame('develop', $communication->getCurrentEnvironment('key', 1));
        $this->assertSame('https://api.uat.payflex.co.za', $communication->getCurrentEnvironment('api_url', 1));
    }

    /**
     * RequestAccessToken exposes getExpire/getToken/etc. via AbstractModel's
     * __call magic, so those have to be declared with addMethods().
     */
    private function newTokenModel()
    {
        return $this->getMockBuilder(RequestAccessToken::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'save', 'delete', 'setData'])
            ->addMethods(['getExpire', 'getToken', 'setToken', 'setExpire'])
            ->getMock();
    }

    public function testGetAccessTokenReturnsCachedTokenWhenValid(): void
    {
        $model = $this->newTokenModel();
        $model->method('load')->willReturnSelf();
        $model->method('getExpire')->willReturn(date('Y-m-d H:i:s', time() + 3600));
        $model->method('getToken')->willReturn('cached-token');
        $this->accessTokenFactory->method('create')->willReturn($model);

        // If the cache is honoured, the transport is never touched, so the plain
        // (non-mocked) instance is safe here.
        $communication = $this->newCommunication();

        $this->assertSame('cached-token', $communication->getAccessToken(1));
    }

    public function testGetAccessTokenFetchesFreshTokenWhenNoneStored(): void
    {
        $model = $this->newTokenModel();
        $model->method('load')->willReturnSelf();
        $model->method('getExpire')->willReturn('');   // nothing cached yet
        $model->method('getToken')->willReturn('');
        $this->accessTokenFactory->method('create')->willReturn($model);

        $this->configuration->method('getPayflexEnvironment')->willReturn(0);

        $communication = $this->newCommunication([
            'httpcode' => 200,
            'response' => json_encode(['access_token' => 'fresh-token']),
            'errmsg'   => '',
        ]);

        $this->assertSame('fresh-token', $communication->getAccessToken(1));
    }

    /**
     * When the auth endpoint answers without an access_token (an error/expired
     * credentials response), getAccessToken must surface false rather than a
     * malformed token that would then be sent as "Bearer ".
     */
    public function testGetAccessTokenReturnsFalseWhenAuthResponseHasNoToken(): void
    {
        $model = $this->newTokenModel();
        $model->method('load')->willReturnSelf();
        $model->method('getExpire')->willReturn('');   // nothing cached yet
        $model->method('getToken')->willReturn('');
        $this->accessTokenFactory->method('create')->willReturn($model);

        $this->configuration->method('getPayflexEnvironment')->willReturn(0);

        $communication = $this->newCommunication([
            'httpcode' => 401,
            'response' => json_encode(['error' => 'invalid_client']),
            'errmsg'   => ' HTTP CODE: 401',
        ]);

        $this->assertFalse($communication->getAccessToken(1));
    }

    public function testGetMerchantConfigurationDecodesResponseAndCaches(): void
    {
        // A valid cached token keeps getAccessToken from calling the transport,
        // so the single mocked sendRequest result is the /configuration payload.
        $model = $this->newTokenModel();
        $model->method('load')->willReturnSelf();
        $model->method('getExpire')->willReturn(date('Y-m-d H:i:s', time() + 3600));
        $model->method('getToken')->willReturn('cached-token');
        $this->accessTokenFactory->method('create')->willReturn($model);

        $communication = $this->newCommunication([
            'httpcode' => 200,
            'response' => json_encode([
                'minimumAmount'     => 50,
                'maximumAmount'     => 20000,
                'enabledForRefunds' => true,
            ]),
            'errmsg'   => '',
        ]);

        $config = $communication->getMerchantConfiguration(1);

        $this->assertSame(50, $config['minimumAmount']);
        $this->assertSame(20000, $config['maximumAmount']);
        $this->assertTrue($config['enabledForRefunds']);

        // A second call for the same store must come from the in-memory cache
        // and return the identical result.
        $this->assertSame($config, $communication->getMerchantConfiguration(1));
    }
}
