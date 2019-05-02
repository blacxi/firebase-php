<?php

namespace Kreait\Firebase\Tests\Unit;

use Exception;
use Firebase\Auth\Token\Domain\Generator;
use Firebase\Auth\Token\Domain\Verifier;
use Firebase\Auth\Token\Exception\InvalidToken;
use Firebase\Auth\Token\Exception\IssuedInTheFuture;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Kreait\Firebase\Auth;
use Kreait\Firebase\Exception\AuthException;
use Kreait\Firebase\Request\CreateUser;
use Kreait\Firebase\Tests\UnitTestCase;
use Lcobucci\JWT\Token;
use Prophecy\Argument;
use Psr\Http\Message\RequestInterface;

class AuthTest extends UnitTestCase
{
    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var Generator
     */
    private $tokenGenerator;

    /**
     * @var Verifier
     */
    private $idTokenVerifier;

    /**
     * @var Auth
     */
    private $auth;

    protected function setUp(): void
    {
        $this->tokenGenerator = $this->createMock(Generator::class);
        $this->idTokenVerifier = $this->createMock(Verifier::class);
        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->auth = $this->instantiate(Auth::class, $this->httpClient, $this->tokenGenerator, $this->idTokenVerifier);
    }

    public function testCatchHttpRequestException()
    {
        $request = $this->prophesize(RequestInterface::class);

        $this->httpClient->method('request')
            ->willThrowException(new RequestException('Foo', $request->reveal()));

        $this->expectException(AuthException::class);
        $this->auth->createUser(CreateUser::new());
    }

    public function testCatchHttpThrowable()
    {
        $this->httpClient->method('request')
            ->willThrowException(new Exception());

        $this->expectException(AuthException::class);
        $this->auth->createUser(CreateUser::new());
    }

    public function testCreateCustomToken()
    {
        $this->tokenGenerator
            ->expects($this->once())
            ->method('createCustomToken');

        $this->auth->createCustomToken('uid');
    }

    public function testVerifyIdToken()
    {
        $this->idTokenVerifier
            ->expects($this->once())
            ->method('verifyIdToken');

        $this->auth->verifyIdToken('some id token string');
    }

    public function testDisallowFutureTokens()
    {
        $tokenProphecy = $this->prophesize(Token::class);
        $tokenProphecy->getClaim('iat')->willReturn(\date('U'));

        $token = $tokenProphecy->reveal();

        $this->idTokenVerifier
            ->expects($this->once())
            ->method('verifyIdToken')
            ->willThrowException(new IssuedInTheFuture($token));

        $this->expectException(IssuedInTheFuture::class);
        $this->auth->verifyIdToken('foo');
    }

    public function testAllowFutureTokens()
    {
        $tokenProphecy = $this->prophesize(Token::class);
        $tokenProphecy->getClaim('iat')->willReturn(\date('U'));

        $token = $tokenProphecy->reveal();

        $this->idTokenVerifier
            ->expects($this->once())
            ->method('verifyIdToken')
            ->willReturn($token);

        $verifiedToken = $this->auth->verifyIdToken('foo', false, true);
        $this->assertSame($token, $verifiedToken);
    }

    public function testAllowAuthTimeToBeInTheFuture()
    {
        $tokenProphecy = $this->prophesize(Token::class);
        $tokenProphecy->getClaim('auth_time', Argument::any())->willReturn(\date('U'));

        $token = $tokenProphecy->reveal();

        $this->idTokenVerifier
            ->expects($this->once())
            ->method('verifyIdToken')
            ->willThrowException(new InvalidToken($token, 'authentication time'));

        $verifiedToken = $this->auth->verifyIdToken($token, false, false);
        $this->assertSame($token, $verifiedToken);
    }

    public function testDoNotAllowTimeInconsistenciesAndCheckRevokationStatusAtTheSameTime()
    {
        $token = $this->prophesize(Token::class);

        $this->idTokenVerifier
            ->expects($this->once())
            ->method('verifyIdToken')
            ->willReturn($token->reveal());

        $this->expectException(InvalidToken::class);
        $this->auth->verifyIdToken($token->reveal(), true, true);
    }
}
