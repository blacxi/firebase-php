<?php

namespace Kreait\Firebase\Tests\Unit;

use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Kreait\Firebase\Database;
use Kreait\Firebase\Database\RuleSet;
use Kreait\Firebase\Exception\ApiException;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Tests\UnitTestCase;
use OutOfRangeException;

class DatabaseTest extends UnitTestCase
{
    private $httpClient;

    /**
     * @var Database
     */
    private $database;

    protected function setUp(): void
    {
        $uri = new Uri('https://database-uri.tld');
        $this->httpClient = $this->createMock(ClientInterface::class);

        $this->database = $this->instantiate(Database::class, $uri, $this->httpClient);
    }

    public function testGetReference()
    {
        $this->assertSame('any', $this->database->getReference('any')->getKey());
    }

    public function testGetReferenceWithInvalidPath()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->database->getReference('#');
    }

    public function testGetReferenceFromUrl()
    {
        $url = 'https://database-uri.tld/foo/bar';

        $reference = $this->database->getReferenceFromUrl($url);

        $this->assertSame($url, (string) $reference);
        $this->assertSame('bar', $reference->getKey());
        $this->assertSame('foo', $reference->getParent()->getKey());

        $this->expectException(OutOfRangeException::class);
        $reference->getParent()->getParent();
    }

    public function testGetReferenceFromInvalidUrl()
    {
        $this->expectException(InvalidArgumentException::class);

        // We don't test any possibly invalid URL, this is already handled by the HTTP client library
        $this->database->getReferenceFromUrl(false);
    }

    public function testGetReferenceFromNonMatchingUrl()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->database->getReferenceFromUrl('http://non-matching.tld');
    }

    public function testGetRules()
    {
        $rules = RuleSet::default()->getRules();

        $this->httpClient
            ->method('send')
            ->willReturn(new Response(200, [], \json_encode($rules)));

        $this->assertEquals($rules, $this->database->getRules()->getRules());
    }

    public function testCatchRequestException()
    {
        $request = new Request('GET', 'foo');

        $this->httpClient
            ->method($this->anything())
            ->willThrowException(new RequestException('foo', $request));

        $this->expectException(ApiException::class);

        $this->database->getRules();
    }

    public function testCatchAnyException()
    {
        $this->httpClient
            ->method($this->anything())
            ->willThrowException(new Exception());

        $this->expectException(ApiException::class);

        $this->database->getRules();
    }
}
