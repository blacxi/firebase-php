<?php

declare(strict_types=1);

namespace Kreait\Firebase\Tests\Integration\Auth;

use Kreait\Firebase\Auth;
use Kreait\Firebase\Auth\CustomTokenViaGoogleIam;
use Kreait\Firebase\Tests\IntegrationTestCase;
use Kreait\Firebase\Util\JSON;

class CustomTokenViaGoogleIamTest extends IntegrationTestCase
{
    /**
     * @var CustomTokenViaGoogleIam
     */
    private $generator;

    /**
     * @var Auth
     */
    private $auth;

    protected function setUp(): void
    {
        $this->generator = new CustomTokenViaGoogleIam(
            self::$serviceAccount->getClientEmail(),
            self::$factory->createApiClient()
        );

        $this->auth = self::$firebase->getAuth();
    }

    public function testCreateCustomToken()
    {
        $user = $this->auth->createUser([]);

        $idTokenResponse = $this->auth->exchangeCustomTokenForIdAndRefreshToken(
            $this->generator->createCustomToken($user->uid, ['foo' => 'bar'])
        );
        $idToken = JSON::decode($idTokenResponse->getBody()->getContents(), true)['idToken'];

        $verifiedToken = $this->auth->verifyIdToken((string) $idToken);

        $this->assertSame($user->uid, $verifiedToken->getClaim('sub'));
        $this->assertSame('bar', $verifiedToken->getClaim('foo'));

        $this->auth->deleteUser($user->uid);
    }
}
