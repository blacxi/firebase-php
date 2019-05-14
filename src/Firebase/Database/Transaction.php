<?php

declare(strict_types=1);

namespace Kreait\Firebase\Database;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Kreait\Firebase\Exception\ApiException;
use Kreait\Firebase\Exception\Database\ReferenceHasNotBeenSnapshotted;
use Kreait\Firebase\Exception\Database\TransactionFailed;
use Kreait\Firebase\Util\JSON;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class Transaction
{
    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var string[]
     */
    private $etags;

    public function __construct(ClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        $this->etags = [];
    }

    public function snapshot(Reference $reference): Snapshot
    {
        $uri = (string) $reference->getUri();

        $response = $this->request('GET', $uri, [
            'headers' => [
                'X-Firebase-ETag' => 'true',
            ],
        ]);

        $this->etags[$uri] = $response->getHeaderLine('ETag');

        return new Snapshot($reference, JSON::decode((string) $response->getBody(), true));
    }

    /**
     * @param Reference $reference
     * @param mixed $value
     *
     * @throws ReferenceHasNotBeenSnapshotted
     * @throws TransactionFailed
     */
    public function set(Reference $reference, $value): void
    {
        $etag = $this->getEtagForReference($reference);

        try {
            $this->request('PUT', $reference->getUri(), [
                'headers' => [
                    'if-match' => $etag,
                ],
                'json' => $value,
            ]);
        } catch (ApiException $e) {
            throw TransactionFailed::forReferenceAndApiException($reference, $e);
        }
    }

    /**
     * @throws ReferenceHasNotBeenSnapshotted
     * @throws TransactionFailed
     */
    public function remove(Reference $reference): void
    {
        $etag = $this->getEtagForReference($reference);

        try {
            $this->request('DELETE', $reference->getUri(), [
                'headers' => [
                    'if-match' => $etag,
                ],
            ]);
        } catch (ApiException $e) {
            throw TransactionFailed::forReferenceAndApiException($reference, $e);
        }
    }

    /**
     * @throws ReferenceHasNotBeenSnapshotted
     */
    private function getEtagForReference(Reference $reference): string
    {
        $uri = (string) $reference->getUri();

        if (array_key_exists($uri, $this->etags)) {
            return $this->etags[$uri];
        }

        throw ReferenceHasNotBeenSnapshotted::with($reference);
    }

    private function request(string $method, $uri, array $options = null): ResponseInterface
    {
        $options = $options ?? [];

        $request = new Request($method, $uri);

        try {
            return $this->httpClient->send($request, $options);
        } catch (RequestException $e) {
            throw ApiException::wrapRequestException($e);
        } catch (Throwable | GuzzleException $e) {
            throw new ApiException($request, $e->getMessage(), $e->getCode(), $e);
        }
    }
}
