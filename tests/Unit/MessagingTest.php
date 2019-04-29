<?php

namespace Kreait\Firebase\Tests\Unit;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Exception\Messaging\AuthenticationError;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Exception\Messaging\UnknownError;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Tests\UnitTestCase;
use RuntimeException;
use stdClass;

class MessagingTest extends UnitTestCase
{
    /**
     * @var string
     */
    private $projectId;
    private $httpClient;

    /**
     * @var Messaging
     */
    private $messaging;

    protected function setUp(): void
    {
        $this->projectId = 'project-id';
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->messaging = $this->instantiate(Messaging::class, $this->projectId, $this->httpClient);
    }

    public function testSendInvalidObject()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->messaging->send(new stdClass());
    }

    public function testSendInvalidArray()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->messaging->send([]);
    }

    /**
     * @dataProvider validTokenProvider
     */
    public function testSubscribeToTopicWithValidTokens($tokens)
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn(new Response(200, [], '[]'));

        $this->messaging->subscribeToTopic('topic', $tokens);
    }

    /**
     * @dataProvider invalidTokenProvider
     */
    public function testSubscribeToTopicWithInvalidTokens($tokens)
    {
        $this->expectException(InvalidArgument::class);
        $this->messaging->subscribeToTopic('topic', $tokens);
    }

    /**
     * @dataProvider validTokenProvider
     */
    public function testUnsubscribeFromTopicWithValidTokens($tokens)
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn(new Response(200, [], '[]'));

        $this->messaging->unsubscribeFromTopic('topic', $tokens);
    }

    /**
     * @dataProvider invalidTokenProvider
     */
    public function testUnsubscribeFromTopicWithInvalidTokens($tokens)
    {
        $this->expectException(InvalidArgument::class);
        $this->messaging->unsubscribeFromTopic('topic', $tokens);
    }

    public function testValidateMessageGivenAnInvalidArgument()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->messaging->validate('string');
    }

    /**
     * @param $requestException
     * @param $expectedClass
     * @dataProvider requestExceptions
     */
    public function testCatchRequestExceptionOnFcmRequest($requestException, $expectedClass)
    {
        $this->httpClient
            ->method('request')
            ->willThrowException($requestException);

        $this->expectException($expectedClass);
        $this->messaging->send(CloudMessage::withTarget('topic', 'foo'));
    }

    public function testCatchAnyExceptionOnFcmRequest()
    {
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new RuntimeException());

        $this->expectException(MessagingException::class);

        $this->messaging->send(CloudMessage::withTarget('topic', 'foo'));
    }

    public function testCatchRequestExceptionOnTopicManagementRequest()
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new RequestException('Bad Request', new Request('GET', 'http://example.com'), new Response(400)));

        $this->expectException(MessagingException::class);
        $this->messaging->subscribeToTopic('topic', ['token1', 'token2']);
    }

    public function testCatchAnyExceptionOnTopicManagementRequest()
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new RuntimeException());

        $this->expectException(MessagingException::class);

        $this->messaging->subscribeToTopic('topic', ['token1', 'token2']);
    }

    public function validTokenProvider()
    {
        return [
            ['foo'],
            [['foo']],
            [Messaging\RegistrationToken::fromValue('foo')],
            [[Messaging\RegistrationToken::fromValue('foo')]],
        ];
    }

    public function invalidTokenProvider()
    {
        return [
            [null],
            [[]],
            [1],
        ];
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
