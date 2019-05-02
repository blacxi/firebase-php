<?php

declare(strict_types=1);

namespace Kreait\Firebase\Tests\Unit\Auth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Kreait\Firebase\Auth\CustomTokenViaGoogleIam;
use Kreait\Firebase\Exception\AuthException;
use Kreait\Firebase\Tests\UnitTestCase;
use Prophecy\Argument;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class CustomTokenViaGoogleIamTest extends UnitTestCase
{
    private $client;
    private $generator;

    protected function setUp(): void
    {
        $this->client = $this->prophesize(ClientInterface::class);

        $this->generator = new CustomTokenViaGoogleIam(
            'client@email.tld',
            $this->client->reveal()
        );
    }

    /** @test */
    public function it_handles_a_request_exception()
    {
        $this->client->request(Argument::cetera())
            ->willThrow(new RequestException('Foo', $this->createMock(RequestInterface::class)));

        $this->expectException(AuthException::class);
        $this->generator->createCustomToken('uid');
    }

    /** @test */
    public function it_handles_any_exception_on_sending_a_request()
    {
        $this->client->request(Argument::cetera())
            ->willThrow(new RuntimeException('foo'));

        $this->expectException(AuthException::class);
        $this->generator->createCustomToken('uid');
    }

    /** @test */
    public function it_does_not_succeed_when_the_api_reponse_misses_a_value()
    {
        $this->client->request(Argument::cetera())
            ->willReturn(new Response(200, [], '{}'));

        $this->expectException(AuthException::class);
        $this->generator->createCustomToken('uid');
    }
}
