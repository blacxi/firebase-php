<?php

declare(strict_types=1);

namespace Kreait\Firebase\Messaging;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use function GuzzleHttp\Psr7\stream_for;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
class ApiClient extends BaseClient
{
    const FIREBASE_MESSAGING_BATCH_URL = 'https://fcm.googleapis.com/batch';

    /**
     * @var BatchRequestClient
     */
    protected $batchRequestClient;

    /**
     * @internal
     */
    public function __construct(ClientInterface $client)
    {
        parent::__construct($client);
        $this->batchRequestClient = new BatchRequestClient($client, self::FIREBASE_MESSAGING_BATCH_URL);
    }

    public function sendMessage(Message $message): ResponseInterface
    {
        return $this->sendMessageAsync($message)->wait();
    }

    public function sendMessageAsync(Message $message): PromiseInterface
    {
        $request = $this->createRequest('POST', 'messages:send');

        return $this->sendAsync($request, [
            'json' => ['message' => $message->jsonSerialize()],
        ]);
    }

    public function sendBatchRequest(Messages $messages): ResponseInterface
    {
        return $this->sendBatchRequestAsync($messages)->wait();
    }

    public function sendBatchRequestAsync(Messages $messages): PromiseInterface
    {
        $collection = new SubRequestCollection();
        foreach ($messages as $message) {
            $request = $this->createRequest('POST', 'messages:send')
                ->withBody(stream_for(\json_encode(['message' => $message->jsonSerialize()])));
            $collection->addRequest($request);
        }

        return $this->batchRequestClient->sendBatchRequestAsync($collection);
    }

    public function validateMessage(Message $message): ResponseInterface
    {
        return $this->validateMessageAsync($message)->wait();
    }

    public function validateMessageAsync(Message $message): PromiseInterface
    {
        $request = $this->createRequest('POST', 'messages:send');

        return $this->sendAsync($request, [
            'json' => [
                'message' => $message->jsonSerialize(),
                'validate_only' => true,
            ],
        ]);
    }
}
