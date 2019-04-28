<?php

namespace Kreait\Firebase;

use Firebase\Auth\Token\Domain\Generator as TokenGenerator;
use Firebase\Auth\Token\Domain\Verifier as IdTokenVerifier;
use Firebase\Auth\Token\Exception\InvalidSignature;
use Firebase\Auth\Token\Exception\InvalidToken;
use Firebase\Auth\Token\Exception\IssuedInTheFuture;
use Generator;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonSerializable;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\CredentialsMismatch;
use Kreait\Firebase\Exception\Auth\InvalidCustomToken;
use Kreait\Firebase\Exception\Auth\InvalidPassword;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Kreait\Firebase\Exception\AuthException;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Util\DT;
use Kreait\Firebase\Util\JSON;
use Kreait\Firebase\Value\ClearTextPassword;
use Kreait\Firebase\Value\Email;
use Kreait\Firebase\Value\PhoneNumber;
use Kreait\Firebase\Value\Provider;
use Kreait\Firebase\Value\Uid;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Token;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

class Auth
{
    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var TokenGenerator
     */
    private $tokenGenerator;

    /**
     * @var IdTokenVerifier
     */
    private $idTokenVerifier;

    private function __construct(ClientInterface $httpClient, TokenGenerator $customToken, IdTokenVerifier $idTokenVerifier)
    {
        $this->httpClient = $httpClient;
        $this->tokenGenerator = $customToken;
        $this->idTokenVerifier = $idTokenVerifier;
    }

    public function getUser($uid): UserRecord
    {
        $uid = $uid instanceof Uid ? $uid : new Uid($uid);

        $response = $this->request('getAccountInfo', [
            'localId' => [(string) $uid],
        ]);

        $data = JSON::decode((string) $response->getBody(), true);

        if (empty($data['users'][0])) {
            throw UserNotFound::withCustomMessage('No user with uid "'.$uid.'" found.');
        }

        return UserRecord::fromResponseData($data['users'][0]);
    }

    /**
     * @param int $maxResults
     * @param int $batchSize
     *
     * @return Generator|UserRecord[]
     */
    public function listUsers(int $maxResults = null, int $batchSize = null): Generator
    {
        $maxResults = $maxResults ?? 1000;
        $batchSize = $batchSize ?? 1000;

        $pageToken = null;
        $count = 0;

        do {
            $response = $this->request('downloadAccount', array_filter([
                'maxResults' => $batchSize,
                'nextPageToken' => $pageToken,
            ]));

            $result = JSON::decode((string) $response->getBody(), true);

            foreach ((array) ($result['users'] ?? []) as $userData) {
                yield UserRecord::fromResponseData($userData);

                if (++$count === $maxResults) {
                    return;
                }
            }

            $pageToken = $result['nextPageToken'] ?? null;
        } while ($pageToken);
    }

    /**
     * Creates a new user with the provided properties.
     *
     * @param array|Request\CreateUser $properties
     *
     * @throws InvalidArgumentException if invalid properties have been provided
     * @throws AuthException if the Auth API couldn't process the request
     *
     * @return UserRecord
     */
    public function createUser($properties): UserRecord
    {
        $request = $properties instanceof Request\CreateUser
            ? $properties
            : Request\CreateUser::withProperties($properties);

        $response = $this->request('signupNewUser', $request);

        $uid = JSON::decode((string) $response->getBody(), true)['localId'];

        return $this->getUser($uid);
    }

    /**
     * Updates the given user with the given properties.
     *
     * @param Uid|string $uid
     * @param array|Request\UpdateUser $properties
     *
     * @throws InvalidArgumentException if invalid properties have been provided
     *
     * @return UserRecord
     */
    public function updateUser($uid, $properties): UserRecord
    {
        $request = $properties instanceof Request\UpdateUser
            ? $properties
            : Request\UpdateUser::withProperties($properties);

        $request = $request->withUid($uid);

        $response = $this->request('setAccountInfo', $request);

        $uid = JSON::decode((string) $response->getBody(), true)['localId'];

        return $this->getUser($uid);
    }

    /**
     * @param Email|string $email
     * @param ClearTextPassword|string $password
     *
     * @return UserRecord
     */
    public function createUserWithEmailAndPassword($email, $password): UserRecord
    {
        return $this->createUser(
            Request\CreateUser::new()
                ->withUnverifiedEmail($email)
                ->withClearTextPassword($password)
        );
    }

    /**
     * Returns a user for the given email address.
     *
     * @param Email|string $email
     *
     * @throws AuthException
     * @throws UserNotFound
     *
     * @return UserRecord
     */
    public function getUserByEmail($email): UserRecord
    {
        $email = $email instanceof Email ? $email : new Email($email);

        $response = $this->request('getAccountInfo', [
            'email' => [(string) $email],
        ]);

        $data = JSON::decode((string) $response->getBody(), true);

        if (empty($data['users'][0])) {
            throw UserNotFound::withCustomMessage('No user with email "'.$email.'" found.');
        }

        return UserRecord::fromResponseData($data['users'][0]);
    }

    /**
     * @param PhoneNumber|string $phoneNumber
     *
     * @return UserRecord
     */
    public function getUserByPhoneNumber($phoneNumber): UserRecord
    {
        $phoneNumber = $phoneNumber instanceof PhoneNumber ? $phoneNumber : new PhoneNumber($phoneNumber);

        $response = $this->request('getAccountInfo', [
            'phoneNumber' => [(string) $phoneNumber],
        ]);

        $data = JSON::decode((string) $response->getBody(), true);

        if (empty($data['users'][0])) {
            throw UserNotFound::withCustomMessage('No user with phone number "'.$phoneNumber.'" found.');
        }

        return UserRecord::fromResponseData($data['users'][0]);
    }

    public function createAnonymousUser(): UserRecord
    {
        return $this->createUser(Request\CreateUser::new());
    }

    /**
     * @param Uid|string $uid
     * @param ClearTextPassword|string $newPassword
     *
     * @return UserRecord
     */
    public function changeUserPassword($uid, $newPassword): UserRecord
    {
        return $this->updateUser($uid, Request\UpdateUser::new()->withClearTextPassword($newPassword));
    }

    /**
     * @param Uid|string $uid
     * @param Email|string $newEmail
     *
     * @return UserRecord
     */
    public function changeUserEmail($uid, $newEmail): UserRecord
    {
        return $this->updateUser($uid, Request\UpdateUser::new()->withEmail($newEmail));
    }

    /**
     * @param Uid|string $uid
     *
     * @return UserRecord
     */
    public function enableUser($uid): UserRecord
    {
        return $this->updateUser($uid, Request\UpdateUser::new()->markAsEnabled());
    }

    /**
     * @param Uid|string $uid
     *
     * @return UserRecord
     */
    public function disableUser($uid): UserRecord
    {
        return $this->updateUser($uid, Request\UpdateUser::new()->markAsDisabled());
    }

    /**
     * @param Uid|string $uid
     *
     * @throws UserNotFound
     */
    public function deleteUser($uid): void
    {
        $uid = $uid instanceof Uid ? $uid : new Uid($uid);

        try {
            $this->request('deleteAccount', [
                'localId' => (string) $uid,
            ]);
        } catch (UserNotFound $e) {
            throw UserNotFound::withCustomMessage('No user with uid "'.$uid.'" found.');
        }
    }

    /**
     * @param Uid|string $uid
     * @param UriInterface|string $continueUrl
     */
    public function sendEmailVerification($uid, $continueUrl = null, string $locale = null): void
    {
        $response = $this->exchangeCustomTokenForIdAndRefreshToken(
            $this->createCustomToken($uid)
        );

        $idToken = JSON::decode((string) $response->getBody(), true)['idToken'];

        $headers = $locale ? ['X-Firebase-Locale' => $locale] : null;

        $data = array_filter([
            'requestType' => 'VERIFY_EMAIL',
            'idToken' => (string) $idToken,
            'continueUrl' => (string) $continueUrl,
        ]);

        $this->request('getOobConfirmationCode', $data, $headers);
    }

    /**
     * @param Email|string $email
     * @param UriInterface|string|null $continueUrl
     */
    public function sendPasswordResetEmail($email, $continueUrl = null, string $locale = null): void
    {
        $email = $email instanceof Email ? $email : new Email($email);

        $headers = $locale ? ['X-Firebase-Locale' => $locale] : null;

        $data = array_filter([
            'email' => (string) $email,
            'requestType' => 'PASSWORD_RESET',
            'continueUrl' => trim((string) $continueUrl),
        ]);

        $this->request('getOobConfirmationCode', $data, $headers);
    }

    /**
     * @param Uid|string $uid
     * @param array $attributes
     *
     * @return UserRecord
     */
    public function setCustomUserAttributes($uid, array $attributes): UserRecord
    {
        return $this->updateUser($uid, Request\UpdateUser::new()->withCustomAttributes($attributes));
    }

    /**
     * @param Uid|string $uid
     * @param array $claims
     *
     * @return Token
     */
    public function createCustomToken($uid, array $claims = null): Token
    {
        $claims = $claims ?? [];

        $uid = $uid instanceof Uid ? $uid : new Uid($uid);

        return $this->tokenGenerator->createCustomToken($uid, $claims);
    }

    /**
     * Verifies a JWT auth token. Returns a Promise with the tokens claims. Rejects the promise if the token
     * could not be verified. If checkRevoked is set to true, verifies if the session corresponding to the
     * ID token was revoked. If the corresponding user's session was invalidated, a RevokedToken
     * exception is thrown. If not specified the check is not applied.
     *
     * NOTE: Allowing time inconsistencies might impose a security risk. Do this only when you are not able
     * to fix your environment's time to be consistent with Google's servers. This parameter is here
     * for backwards compatibility reasons, and will be removed in the next major version. You
     * shouldn't rely on it.
     *
     * @param Token|string $idToken the JWT to verify
     * @param bool $checkIfRevoked whether to check if the ID token is revoked
     * @param bool $allowTimeInconsistencies whether to allow tokens that have mismatching timestamps
     *
     * @throws InvalidToken
     * @throws IssuedInTheFuture
     * @throws RevokedIdToken
     * @throws InvalidSignature
     *
     * @return Token the verified token
     */
    public function verifyIdToken($idToken, bool $checkIfRevoked = null, bool $allowTimeInconsistencies = null): Token
    {
        $checkIfRevoked = $checkIfRevoked ?? false;
        $allowTimeInconsistencies = $allowTimeInconsistencies ?? false;

        try {
            $verifiedToken = $this->idTokenVerifier->verifyIdToken($idToken);
        } catch (IssuedInTheFuture $e) {
            if (!$allowTimeInconsistencies) {
                throw $e;
            }

            $verifiedToken = $e->getToken();
        } catch (InvalidToken $e) {
            $verifiedToken = $idToken instanceof Token ? $idToken : (new Parser())->parse($idToken);

            if (stripos($e->getMessage(), 'authentication time') !== false) {
                $authTime = $verifiedToken->getClaim('auth_time', false);

                if ($authTime && !$allowTimeInconsistencies && $authTime > time()) {
                    throw $e;
                }
            } else {
                throw $e;
            }
        }

        if ($checkIfRevoked && $allowTimeInconsistencies) {
            throw new InvalidToken($verifiedToken, 'Allowing mismatching timestamps cannot be combined with token revokation checks.');
        }

        if ($checkIfRevoked) {
            $tokenAuthenticatedAt = DT::toUTCDateTimeImmutable($verifiedToken->getClaim('auth_time'));
            $validSince = $this->getUser($verifiedToken->getClaim('sub'))->tokensValidAfterTime;

            if ($validSince && ($tokenAuthenticatedAt < $validSince)) {
                throw new RevokedIdToken($verifiedToken);
            }
        }

        return $verifiedToken;
    }

    /**
     * Verifies wether the given email/password combination is correct and returns
     * a UserRecord when it is, an Exception otherwise.
     *
     * This method has the side effect of changing the last login timestamp of the
     * given user. The recommended way to authenticate users in a client/server
     * environment is to use a Firebase Client SDK to authenticate the user
     * and to send an ID Token generated by the client back to the server.
     *
     * @param Email|string $email
     * @param ClearTextPassword|string $password
     *
     * @throws InvalidPassword if the given password does not match the given email address
     *
     * @return UserRecord if the combination of email and password is correct
     */
    public function verifyPassword($email, $password): UserRecord
    {
        $email = $email instanceof Email ? $email : new Email($email);
        $password = $password instanceof ClearTextPassword ? $password : new ClearTextPassword($password);

        $response = $this->request('verifyPassword', [
            'email' => (string) $email,
            'password' => (string) $password,
        ]);

        $uid = JSON::decode((string) $response->getBody(), true)['localId'];

        return $this->getUser($uid);
    }

    /**
     * Revokes all refresh tokens for the specified user identified by the uid provided.
     * In addition to revoking all refresh tokens for a user, all ID tokens issued
     * before revocation will also be revoked on the Auth backend. Any request with an
     * ID token generated before revocation will be rejected with a token expired error.
     *
     * @param Uid|string $uid the user whose tokens are to be revoked
     */
    public function revokeRefreshTokens($uid): void
    {
        $uid = $uid instanceof Uid ? $uid : new Uid($uid);

        $this->request('setAccountInfo', [
            'localId' => (string) $uid,
            'validSince' => time(),
        ]);
    }

    public function unlinkProvider($uid, $provider): UserRecord
    {
        $uid = $uid instanceof Uid ? $uid : new Uid($uid);

        $providers = array_map(static function ($provider) {
            return $provider instanceof Provider ? $provider : new Provider($provider);
        }, (array) $provider);

        $response = $this->request('setAccountInfo', [
            'localId' => (string) $uid,
            'deleteProvider' => $providers,
        ]);

        $uid = JSON::decode((string) $response->getBody(), true)['localId'];

        return $this->getUser($uid);
    }

    /**
     * Takes a custom token and exchanges it with an ID token.
     *
     * @param Token $token
     *
     * @see https://firebase.google.com/docs/reference/rest/auth/#section-verify-custom-token
     *
     * @throws InvalidCustomToken when the custom token is invalid for some reason
     * @throws CredentialsMismatch when the custom token does not match the project it's being sent to
     *
     * @return ResponseInterface
     */
    public function exchangeCustomTokenForIdAndRefreshToken(Token $token): ResponseInterface
    {
        return $this->request('verifyCustomToken', [
            'token' => (string) $token,
            'returnSecureToken' => true,
        ]);
    }

    /**
     * @param string $uri
     * @param JsonSerializable|array|object $data
     * @param array|null $headers
     *
     * @throws AuthException
     *
     * @return ResponseInterface
     */
    private function request(string $uri, $data, array $headers = null): ResponseInterface
    {
        if ($data instanceof JsonSerializable && empty($data->jsonSerialize())) {
            $data = (object) []; // Ensure '{}' instead of '[]' when JSON encoded
        }

        $options = array_filter([
            'json' => $data,
            'headers' => $headers,
        ]);

        try {
            return $this->httpClient->request('POST', $uri, $options);
        } catch (RequestException $e) {
            throw AuthException::fromRequestException($e);
        } catch (Throwable | GuzzleException $e) {
            throw new AuthException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
