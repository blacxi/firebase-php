<?php

namespace Kreait\Firebase\Tests\Unit;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kreait\Firebase\Exception\RemoteConfig\OperationAborted;
use Kreait\Firebase\Exception\RemoteConfig\PermissionDenied;
use Kreait\Firebase\Exception\RemoteConfigException;
use Kreait\Firebase\RemoteConfig;
use Kreait\Firebase\Tests\UnitTestCase;

class RemoteConfigTest extends UnitTestCase
{
    private $http;

    /**
     * @var RemoteConfig
     */
    private $remoteConfig;

    protected function setUp(): void
    {
        $this->http = $this->createMock(ClientInterface::class);

        $this->remoteConfig = $this->instantiate(RemoteConfig::class, 'project-id', $this->http);
    }

    /**
     * @param $requestException
     * @param $expectedClass
     * @dataProvider requestExceptions
     */
    public function testCatchRequestException($requestException, $expectedClass)
    {
        $this->http
            ->method('request')
            ->willThrowException($requestException);

        try {
            $this->remoteConfig->get();
        } catch (\Throwable $e) {
            $this->assertInstanceOf(RemoteConfigException::class, $e);
            $this->assertInstanceOf($expectedClass, $e);
        }
    }

    public function testCatchThrowable()
    {
        $this->http
            ->method('request')
            ->willThrowException(new \Exception());

        $this->expectException(RemoteConfigException::class);

        $this->remoteConfig->get();
    }

    public function requestExceptions(): array
    {
        $request = new Request('GET', 'http://example.com');

        return [
            [
                new RequestException('Bad Request', $request, new Response(400, [], '{"error":{"message":"ABORTED"}}')),
                OperationAborted::class,
            ],
            [
                new RequestException('Bad Request', $request, new Response(400, [], '{"error":{"message":"PERMISSION_DENIED"}}')),
                PermissionDenied::class,
            ],
            [
                new RequestException('Forbidden', $request, new Response(403, [], '{"error":{"message":"UNKOWN"}}')),
                RemoteConfigException::class,
            ],
        ];
    }
}
