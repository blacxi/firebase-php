<?php

declare(strict_types=1);

namespace Kreait\Firebase;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Message;
use Kreait\Firebase\Messaging\RegistrationToken;
use Kreait\Firebase\Messaging\Topic;
use Kreait\Firebase\Util\JSON;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

class Messaging
{
    /**
     * @var string
     */
    private $projectId;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    private function __construct(string $projectId, ClientInterface $httpClient)
    {
        $this->projectId = $projectId;
        $this->httpClient = $httpClient;
    }

    /**
     * @param array|CloudMessage|Message $message
     *
     * @return array
     */
    public function send($message): array
    {
        if (is_array($message)) {
            $message = CloudMessage::fromArray($message);
        }

        if (!($message instanceof Message)) {
            throw new InvalidArgumentException(
                'Unsupported message type. Use an array or a class implementing %s'.Message::class
            );
        }
        $response = $this->requestFcm('POST', 'messages:send', [
            'json' => ['message' => $message->jsonSerialize()],
        ]);

        return JSON::decode((string) $response->getBody(), true);
    }

    /**
     * @param array|CloudMessage|Message $message
     *
     * @throws InvalidArgumentException
     * @throws InvalidMessage
     *
     * @return array
     */
    public function validate($message): array
    {
        if (is_array($message)) {
            $message = CloudMessage::fromArray($message);
        }

        if (!($message instanceof Message)) {
            throw new InvalidArgumentException(
                'Unsupported message type. Use an array or a class implementing %s'.Message::class
            );
        }

        try {
            $response = $this->requestFcm('POST', 'messages:send', [
                'json' => [
                    'message' => $message->jsonSerialize(),
                    'validate_only' => true,
                ],
            ]);
        } catch (NotFound $e) {
            throw (new InvalidMessage($e->getMessage(), $e->getCode()))
                ->withResponse($e->response());
        }

        return JSON::decode((string) $response->getBody(), true);
    }

    /**
     * @param string|Topic $topic
     * @param RegistrationToken|RegistrationToken[]|string|string[] $registrationTokenOrTokens
     *
     * @return array
     */
    public function subscribeToTopic($topic, $registrationTokenOrTokens): array
    {
        $topic = $topic instanceof Topic ? $topic : Topic::fromValue($topic);
        $tokens = $this->ensureArrayOfRegistrationTokens($registrationTokenOrTokens);

        $response = $this->requestTopics('POST', '/iid/v1:batchAdd', [
            'json' => [
                'to' => '/topics/'.$topic,
                'registration_tokens' => $tokens,
            ],
        ]);

        return JSON::decode((string) $response->getBody(), true);
    }

    /**
     * @param string|Topic $topic
     * @param RegistrationToken|RegistrationToken[]|string|string[] $registrationTokenOrTokens
     *
     * @return array
     */
    public function unsubscribeFromTopic($topic, $registrationTokenOrTokens): array
    {
        $topic = $topic instanceof Topic ? $topic : Topic::fromValue($topic);
        $tokens = $this->ensureArrayOfRegistrationTokens($registrationTokenOrTokens);

        $response = $this->requestTopics('POST', '/iid/v1:batchRemove', [
            'json' => [
                'to' => '/topics/'.$topic,
                'registration_tokens' => $tokens,
            ],
        ]);

        return JSON::decode((string) $response->getBody(), true);
    }

    private function ensureArrayOfRegistrationTokens($tokenOrTokens): array
    {
        if ($tokenOrTokens instanceof RegistrationToken) {
            return [$tokenOrTokens];
        }

        if (is_string($tokenOrTokens)) {
            return [RegistrationToken::fromValue($tokenOrTokens)];
        }

        if (is_array($tokenOrTokens)) {
            if (empty($tokenOrTokens)) {
                throw new InvalidArgument('Empty array of registration tokens.');
            }

            return array_map(static function ($token) {
                return $token instanceof RegistrationToken ? $token : RegistrationToken::fromValue($token);
            }, $tokenOrTokens);
        }

        throw new InvalidArgument('Invalid registration tokens.');
    }

    private function requestFcm($method, $endpoint, array $options = null): ResponseInterface
    {
        $options = $options ?? [];

        /** @var UriInterface $uri */
        $uri = new Uri('https://fcm.googleapis.com/v1/projects/'.$this->projectId);
        $path = rtrim($uri->getPath(), '/').'/'.ltrim($endpoint, '/');
        $uri = $uri->withPath($path);

        try {
            return $this->httpClient->request($method, $uri, $options);
        } catch (RequestException $e) {
            throw MessagingException::fromRequestException($e);
        } catch (Throwable | GuzzleException $e) {
            throw new MessagingException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function requestTopics($method, $endpoint, array $options = null): ResponseInterface
    {
        $options = $options ?? [];

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'access_token_auth' => 'true',
        ]);

        /** @var UriInterface $uri */
        $uri = new Uri('https://iid.googleapis.com');
        $path = rtrim($uri->getPath(), '/').'/'.ltrim($endpoint, '/');
        $uri = $uri->withPath($path);

        try {
            return $this->httpClient->request($method, $uri, $options);
        } catch (RequestException $e) {
            throw MessagingException::fromRequestException($e);
        } catch (Throwable | GuzzleException $e) {
            throw new MessagingException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
