<?php

declare(strict_types=1);

namespace Kreait\Firebase;

use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\ApiClient;
use Kreait\Firebase\Messaging\AppInstance;
use Kreait\Firebase\Messaging\AppInstanceApiClient;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Messages;
use Kreait\Firebase\Messaging\Message;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\RegistrationToken;
use Kreait\Firebase\Messaging\SendReport;
use Kreait\Firebase\Messaging\Topic;
use Kreait\Firebase\Util\JSON;
use Psr\Http\Message\ResponseInterface;

class Messaging
{
    const FCM_MAX_BATCH_SIZE = 100;

    /**
     * @var ApiClient
     */
    private $messagingApi;

    /**
     * @var AppInstanceApiClient
     */
    private $appInstanceApi;

    /**
     * @internal
     */
    public function __construct(ApiClient $messagingApiClient, AppInstanceApiClient $appInstanceApiClient)
    {
        $this->messagingApi = $messagingApiClient;
        $this->appInstanceApi = $appInstanceApiClient;
    }

    /**
     * @param array|CloudMessage|Message|mixed $message
     */
    public function send($message): array
    {
        $message = $this->checkMessage($message);
        if (($message instanceof CloudMessage) && !$message->hasTarget()) {
            throw new InvalidArgumentException('The given message has no target');
        }

        $response = $this->messagingApi->sendMessage($message);

        return JSON::decode((string) $response->getBody(), true);
    }

    /**
     * @return array
     */
    public function sendAll(array $messages): MulticastSendReport
    {
        if (\count($messages) > self::FCM_MAX_BATCH_SIZE) {
            throw new InvalidArgumentException(
                \sprintf('messages list must not contain more than %d items', self::FCM_MAX_BATCH_SIZE)
            );
        }

        $collection = new Messages();
        foreach ($messages as $message) {
            $message = $this->checkMessage($message);
            if (($message instanceof CloudMessage) && !$message->hasTarget()) {
                throw new InvalidArgumentException('The given message has no target');
            }
            $collection->add($message);
        }

        $reports = [];
        $sendResponse = $this->messagingApi->sendBatchRequest($collection);
        $requests = $collection->getIterator();
        while ($body = $sendResponse->getBody()) {
            $reports[] = $this->buildSendReport($requests->current()->getTarget(), \GuzzleHttp\Psr7\parse_response((string) $body));
            $requests->next();
        }

        return MulticastSendReport::withItems($reports);
    }

    /**
     * @param array|Message|mixed $message
     * @param string[]|RegistrationToken[] $deviceTokens
     */
    public function sendMulticast($message, array $deviceTokens): MulticastSendReport
    {
        $message = $this->checkMessage($message);
        if (!($message instanceof CloudMessage)) {
            $message = CloudMessage::fromArray($message->jsonSerialize());
        }

        $messages = [];

        foreach ($deviceTokens as $token) {
            $target = MessageTarget::with(MessageTarget::TOKEN, (string) $token);
            $messages[] = $message->withChangedTarget($target->type(), $target->value());
        }

        return $this->sendAll($messages);
    }

    /**
     * @param array|CloudMessage|Message|mixed $message
     *
     * @throws InvalidArgumentException
     * @throws InvalidMessage
     */
    public function validate($message): array
    {
        $message = $this->checkMessage($message);
        try {
            $response = $this->messagingApi->validateMessage($message);
        } catch (NotFound $e) {
            throw (new InvalidMessage($e->getMessage(), $e->getCode()))
                ->withResponse($e->response());
        }

        return JSON::decode((string) $response->getBody(), true);
    }

    /**
     * @param string|Topic $topic
     * @param RegistrationToken|RegistrationToken[]|string|string[] $registrationTokenOrTokens
     */
    public function subscribeToTopic($topic, $registrationTokenOrTokens): array
    {
        $topic = $topic instanceof Topic ? $topic : Topic::fromValue($topic);
        $tokens = $this->ensureArrayOfRegistrationTokens($registrationTokenOrTokens);

        $response = $this->appInstanceApi->subscribeToTopic($topic, $tokens);

        return JSON::decode((string) $response->getBody(), true);
    }

    /**
     * @param string|Topic $topic
     * @param RegistrationToken|RegistrationToken[]|string|string[] $registrationTokenOrTokens
     */
    public function unsubscribeFromTopic($topic, $registrationTokenOrTokens): array
    {
        $topic = $topic instanceof Topic ? $topic : Topic::fromValue($topic);
        $tokens = $this->ensureArrayOfRegistrationTokens($registrationTokenOrTokens);

        $response = $this->appInstanceApi->unsubscribeFromTopic($topic, $tokens);

        return JSON::decode((string) $response->getBody(), true);
    }

    /**
     * @see https://developers.google.com/instance-id/reference/server#results
     *
     * @param RegistrationToken|string $registrationToken
     *
     * @throws InvalidArgument if the registration token is invalid
     */
    public function getAppInstance($registrationToken): AppInstance
    {
        $token = $registrationToken instanceof RegistrationToken
            ? $registrationToken
            : RegistrationToken::fromValue($registrationToken);

        try {
            $response = $this->appInstanceApi->getAppInstance((string) $token);
        } catch (MessagingException $e) {
            // The token is invalid
            throw new InvalidArgument("The registration token '{$token}' is invalid");
        }

        $data = JSON::decode((string) $response->getBody(), true);

        return AppInstance::fromRawData($token, $data);
    }

    /**
     * @param mixed $tokenOrTokens
     *
     * @return RegistrationToken[]
     */
    private function ensureArrayOfRegistrationTokens($tokenOrTokens): array
    {
        if ($tokenOrTokens instanceof RegistrationToken) {
            return [$tokenOrTokens];
        }

        if (\is_string($tokenOrTokens)) {
            return [RegistrationToken::fromValue($tokenOrTokens)];
        }

        if (!\is_array($tokenOrTokens)) {
            $tokenOrTokens = [$tokenOrTokens];
        }

        $tokens = [];

        foreach ($tokenOrTokens as $value) {
            if ($value instanceof RegistrationToken) {
                $tokens[] = $value;
            } elseif ($value instanceof AppInstance) {
                $tokens[] = $value->registrationToken();
            } elseif (\is_string($value)) {
                $tokens[] = RegistrationToken::fromValue($value);
            }
        }

        if (empty($tokens)) {
            throw new InvalidArgument('Invalid or empty list of registration tokens.');
        }

        return $tokens;
    }

    private function checkMessage($message): Message
    {
        if (\is_array($message)) {
            $message = CloudMessage::fromArray($message);
        }
        if (!($message instanceof Message)) {
            throw new InvalidArgumentException(
                'Unsupported message type. Use an array or a class implementing %s'.Message::class
            );
        }

        return $message;
    }

    private function buildSendReport($target, ResponseInterface $response)
    {
        $isSuccess = $response->getStatusCode() === 200;
        if ($isSuccess) {
            $data = JSON::decode((string) $response->getBody(), true);

            return SendReport::success($target, $data);
        }

        return SendReport::failure($target, MessagingException::fromResponse($response));
    }
}
