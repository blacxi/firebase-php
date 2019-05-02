<?php

namespace Kreait\Firebase\Tests\Unit\Database;

use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Kreait\Firebase\Database\Query;
use Kreait\Firebase\Database\Reference;
use Kreait\Firebase\Exception\IndexNotDefined;
use Kreait\Firebase\Exception\QueryException;
use Kreait\Firebase\Tests\UnitTestCase;

class QueryTest extends UnitTestCase
{
    protected $uri;
    protected $reference;
    protected $httpClient;

    /**
     * @var Query
     */
    protected $query;

    protected function setUp(): void
    {
        $this->uri = new Uri('http://domain.tld/some/path');

        $reference = $this->createMock(Reference::class);
        $reference->method('getURI')->willReturn($this->uri);

        $this->reference = $reference;

        $this->httpClient = $this->createMock(ClientInterface::class);

        $this->query = new Query($this->reference, $this->httpClient);
    }

    public function testGetReference()
    {
        $this->assertSame($this->reference, $this->query->getReference());
    }

    public function testGetSnapshot()
    {
        $this->httpClient
            ->method('send')
            ->willReturn(new Response(200, [], '"value"'));

        $snapshot = $this->query->orderByKey()->equalTo(2)->getSnapshot();

        $this->assertSame('value', $snapshot->getValue());
    }

    public function testGetValue()
    {
        $this->httpClient
            ->method('send')
            ->willReturn(new Response(200, [], \json_encode('value')));

        $this->assertSame('value', $this->query->getValue());
    }

    public function testGetUri()
    {
        $uri = $this->query->getUri();

        $this->assertSame((string) $uri, (string) $this->query);
    }

    public function testModifiersReturnQueries()
    {
        $this->assertInstanceOf(Query::class, $this->query->equalTo('x'));
        $this->assertInstanceOf(Query::class, $this->query->endAt('x'));
        $this->assertInstanceOf(Query::class, $this->query->limitToFirst(1));
        $this->assertInstanceOf(Query::class, $this->query->limitToLast(1));
        $this->assertInstanceOf(Query::class, $this->query->orderByChild('child'));
        $this->assertInstanceOf(Query::class, $this->query->orderByKey());
        $this->assertInstanceOf(Query::class, $this->query->orderByValue());
        $this->assertInstanceOf(Query::class, $this->query->shallow());
        $this->assertInstanceOf(Query::class, $this->query->startAt('x'));
    }

    public function testOnlyOneSorterIsAllowed()
    {
        $this->expectException(QueryException::class);

        $this->query->orderByKey()->orderByValue();
    }

    public function testWrapsApiExceptions()
    {
        $error = [
            'error' => 'Something happened',
        ];

        $request = new Request('GET', 'any');
        $response = new Response(400, [], \json_encode($error));

        $this->httpClient
            ->expects($this->once())
            ->method('send')
            ->willThrowException(RequestException::create($request, $response));

        $this->expectException(QueryException::class);

        $this->query->getSnapshot();
    }

    public function testIndexNotDefined()
    {
        $error = [
            'error' => 'Index not defined, add ".indexOn": ".value", for path "/some/path", to the rules',
        ];

        $request = new Request('GET', 'any');
        $response = new Response(400, [], \json_encode($error));

        $this->httpClient
            ->expects($this->once())
            ->method('send')
            ->willThrowException(RequestException::create($request, $response));

        $this->expectException(IndexNotDefined::class);
        $this->query->getSnapshot();
    }

    public function testCatchRequestException()
    {
        $request = new Request('GET', 'foo');

        $this->httpClient
            ->method($this->anything())
            ->willThrowException(new RequestException('foo', $request));

        $this->expectException(QueryException::class);

        $this->query->getSnapshot();
    }

    public function testCatchAnyException()
    {
        $this->httpClient
            ->method($this->anything())
            ->willThrowException(new Exception());

        $this->expectException(QueryException::class);

        $this->query->getSnapshot();
    }
}
