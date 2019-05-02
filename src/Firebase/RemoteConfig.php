<?php

declare(strict_types=1);

namespace Kreait\Firebase;

use Generator;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Kreait\Firebase\Exception\RemoteConfig\ValidationFailed;
use Kreait\Firebase\Exception\RemoteConfig\VersionNotFound;
use Kreait\Firebase\Exception\RemoteConfigException;
use Kreait\Firebase\RemoteConfig\FindVersions;
use Kreait\Firebase\RemoteConfig\Template;
use Kreait\Firebase\RemoteConfig\Version;
use Kreait\Firebase\RemoteConfig\VersionNumber;
use Kreait\Firebase\Util\JSON;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The Firebase Remote Config.
 *
 * @see https://firebase.google.com/docs/remote-config/use-config-rest
 * @see https://firebase.google.com/docs/reference/remote-config/rest/v1/projects
 * @see https://firebase.google.com/docs/reference/remote-config/rest/v1/projects.remoteConfig
 */
class RemoteConfig
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

    public function get(): Template
    {
        return Template::fromResponse($this->request('GET', 'remoteConfig'));
    }

    /**
     * Validates the given template without publishing it.
     *
     * @param Template|array $template
     *
     * @throws ValidationFailed if the validation failed
     */
    public function validate($template): void
    {
        $template = $template instanceof Template ? $template : Template::fromArray($template);

        $this->request('PUT', 'remoteConfig', [
            'headers' => [
                'Content-Type' => 'application/json; UTF-8',
                'If-Match' => $template->getEtag(),
            ],
            'query' => [
                'validate_only' => 'true',
            ],
            'body' => JSON::encode($template),
        ]);
    }

    /**
     * @param Template|array $template
     *
     * @throws RemoteConfigException
     *
     * @return string The etag value of the published template that can be compared to in later calls
     */
    public function publish($template): string
    {
        $template = $template instanceof Template ? $template : Template::fromArray($template);

        $response = $this->request('PUT', 'remoteConfig', [
            'headers' => [
                'Content-Type' => 'application/json; UTF-8',
                'If-Match' => $template->getEtag(),
            ],
            'body' => JSON::encode($template),
        ]);

        $etag = $response->getHeader('ETag');

        return (string) array_shift($etag);
    }

    /**
     * Returns a version with the given number.
     *
     * @param VersionNumber|mixed $versionNumber
     *
     * @throws VersionNotFound
     *
     * @return Version
     */
    public function getVersion($versionNumber): Version
    {
        $versionNumber = $versionNumber instanceof VersionNumber
            ? $versionNumber
            : VersionNumber::fromValue($versionNumber);

        foreach ($this->listVersions() as $version) {
            if ($version->versionNumber()->equalsTo($versionNumber)) {
                return $version;
            }
        }

        throw VersionNotFound::withVersionNumber($versionNumber);
    }

    /**
     * Returns a version with the given number.
     *
     * @param VersionNumber|mixed $versionNumber
     *
     * @throws VersionNotFound
     *
     * @return Template
     */
    public function rollbackToVersion($versionNumber): Template
    {
        $versionNumber = $versionNumber instanceof VersionNumber
            ? $versionNumber
            : VersionNumber::fromValue($versionNumber);

        $response = $this->request('POST', 'remoteConfig:rollback', [
            'json' => [
                'version_number' => (string) $versionNumber,
            ],
        ]);

        return Template::fromResponse($response);
    }

    /**
     * @param FindVersions|array $query
     *
     * @return Generator|Version[]
     */
    public function listVersions($query = null): Generator
    {
        $query = $query instanceof FindVersions ? $query : FindVersions::fromArray((array) $query);
        $pageToken = null;
        $count = 0;

        $startTime = null;
        if ($since = $query->since()) {
            $startTime = $since->format('Y-m-d\TH:i:s.v\Z');
        }

        $endTime = null;
        if ($until = $query->until()) {
            $endTime = $until->format('Y-m-d\TH:i:s.v\Z');
        }

        $upToVersion = null;
        if ($query->upToVersion()) {
            $upToVersion = (string) $upToVersion;
        }

        do {
            $response = $this->request('GET', 'remoteConfig:listVersions', array_filter([
                'startTime' => $startTime,
                'endTime' => $endTime,
                'endVersionNumber' => $upToVersion,
                'nextPageToken' => $pageToken,
            ]));

            $result = JSON::decode((string) $response->getBody(), true);

            foreach ((array) ($result['versions'] ?? []) as $versionData) {
                ++$count;
                yield Version::fromArray($versionData);

                if ($count === (int) $query->limit()) {
                    return;
                }
            }

            $pageToken = $result['nextPageToken'] ?? null;
        } while ($pageToken);
    }

    private function request(string $method, string $endpoint, array $options = null): ResponseInterface
    {
        $endpoint = ltrim($endpoint, '/');
        $url = 'https://firebaseremoteconfig.googleapis.com/v1/projects/'.$this->projectId.'/'.ltrim($endpoint, '/');

        $options = $options ?? [];

        $options = array_merge($options, [
            'decode_content' => 'gzip', // sets content-type and deflates response body
        ]);

        try {
            return $this->httpClient->request($method, $url, $options);
        } catch (RequestException $e) {
            throw RemoteConfigException::fromRequestException($e);
        } catch (Throwable | GuzzleException $e) {
            throw new RemoteConfigException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
