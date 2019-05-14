<?php

namespace Kreait\Firebase;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use function GuzzleHttp\Psr7\uri_for;
use Kreait\Firebase\Database\Reference;
use Kreait\Firebase\Database\RuleSet;
use Kreait\Firebase\Database\Transaction;
use Kreait\Firebase\Exception\ApiException;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Exception\OutOfRangeException;
use Kreait\Firebase\Util\JSON;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * The Firebase Realtime Database.
 *
 * @see https://firebase.google.com/docs/reference/js/firebase.database.Database
 */
class Database
{
    public const SERVER_TIMESTAMP = ['.sv' => 'timestamp'];

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var UriInterface
     */
    private $uri;

    private function __construct(UriInterface $uri, ClientInterface $httpClient)
    {
        $this->uri = $uri;
        $this->httpClient = $httpClient;
    }

    /**
     * Returns a Reference to the root or the specified path.
     *
     * @see https://firebase.google.com/docs/reference/js/firebase.database.Database#ref
     *
     * @param string $path
     *
     * @throws InvalidArgumentException
     *
     * @return Reference
     */
    public function getReference(string $path = null): Reference
    {
        return new Reference($this->uri->withPath($path ?? ''), $this->httpClient);
    }

    /**
     * Returns a reference to the root or the path specified in url.
     *
     * @see https://firebase.google.com/docs/reference/js/firebase.database.Database#refFromURL
     *
     * @param string|UriInterface $uri
     *
     * @throws InvalidArgumentException If the URL is invalid
     * @throws OutOfRangeException If the URL is not in the same domain as the current database
     *
     * @return Reference
     */
    public function getReferenceFromUrl($uri): Reference
    {
        try {
            $uri = uri_for($uri);
        } catch (\InvalidArgumentException $e) {
            // Wrap exception so that everything stays inside the Firebase namespace
            throw new InvalidArgumentException($e->getMessage(), $e->getCode());
        }

        if (($givenHost = $uri->getHost()) !== ($dbHost = $this->uri->getHost())) {
            throw new InvalidArgumentException(sprintf(
                'The given URI\'s host "%s" is not covered by the database for the host "%s".',
                $givenHost, $dbHost
            ));
        }

        return $this->getReference($uri->getPath());
    }

    /**
     * Retrieve Firebase Database Rules.
     *
     * @see https://firebase.google.com/docs/database/rest/app-management#retrieving-firebase-realtime-database-rules
     *
     * @return RuleSet
     */
    public function getRules(): RuleSet
    {
        $response = $this->request('GET', $this->uri->withPath('.settings/rules'));

        $rules = JSON::decode((string) $response->getBody(), true);

        return RuleSet::fromArray($rules);
    }

    /**
     * Update Firebase Database Rules.
     *
     * @see https://firebase.google.com/docs/database/rest/app-management#updating-firebase-realtime-database-rules
     *
     * @param RuleSet $ruleSet
     */
    public function updateRules(RuleSet $ruleSet): void
    {
        $this->request('PUT', $this->uri->withPath('.settings/rules'), [
            'body' => json_encode($ruleSet, JSON_PRETTY_PRINT),
        ]);
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

    public function runTransaction(callable $callable)
    {
        $transaction = new Transaction($this->httpClient);

        return $callable($transaction);
    }
}
