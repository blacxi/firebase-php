<?php

declare(strict_types=1);

namespace Kreait\Firebase\Tests\Unit\Messaging;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Exception\Messaging\UnknownError;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\BatchRequestClient;
use Kreait\Firebase\Messaging\SubRequestCollection;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class BatchRequestClientTest extends TestCase
{
    /** @var MockHandler */
    private $mock;

    /** @var BatchRequestClient */
    private $client;

    protected function setUp()
    {
        $this->mock = new MockHandler();
        $handler = HandlerStack::create($this->mock);
        $client = new Client(['handler' => $handler]);

        $this->client = new BatchRequestClient($client, 'http://example.com');
    }

    /**
     * @dataProvider requestExceptions
     */
    public function testCatchRequestException($requestException, $expectedClass)
    {
        $this->mock->append($requestException);

        $this->expectException($expectedClass);
        $this->client->sendBatchRequest(new SubRequestCollection());
    }

    public function testCatchAnyException()
    {
        $this->mock->append(new Exception());

        $this->expectException(MessagingException::class);

        $this->client->sendBatchRequest(new SubRequestCollection());
    }

    public function requestExceptions(): array
    {
        $request = new Request('GET', 'http://example.com');
        $responseBody = '{}';

        return [
            [
                new RequestException('Bad Request', $request, new Response(400, [], $responseBody)),
                InvalidArgument::class,
            ],
            [
                new RequestException('Unauthorized', $request, new Response(401, [], $responseBody)),
                AuthenticationError::class,
            ],
            [
                new RequestException('Forbidden', $request, new Response(403, [], $responseBody)),
                AuthenticationError::class,
            ],
            [
                new RequestException('Internal Server Error', $request, new Response(500, [], $responseBody)),
                ServerError::class,
            ],
            [
                new RequestException('Service Unavailable', $request, new Response(503, [], $responseBody)),
                ServerUnavailable::class,
            ],
            [
                new RequestException('I\'m a teapot', $request, new Response(418, [], $responseBody)),
                UnknownError::class,
            ],
        ];
    }
}
