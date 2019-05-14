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
use Kreait\Firebase\Exception\ApiException;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Exception\OutOfRangeException;
use Kreait\Firebase\Tests\UnitTestCase;

class ReferenceTest extends UnitTestCase
{
    /**
     * @var Uri
     */
    private $uri;
    private $httpClient;
    private $reference;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uri = new Uri('http://domain.tld/parent/key');
        $this->httpClient = $this->createMock(ClientInterface::class);

        $this->reference = new Reference($this->uri, $this->httpClient);
    }

    public function testGetKey()
    {
        $this->assertSame('key', $this->reference->getKey());
    }

    public function testGetPath()
    {
        $this->assertSame('parent/key', $this->reference->getPath());
    }

    public function testGetParent()
    {
        $this->assertSame('parent', $this->reference->getParent()->getPath());
    }

    public function testGetParentOfRoot()
    {
        $this->expectException(OutOfRangeException::class);

        $this->reference->getParent()->getParent();
    }

    public function testGetRoot()
    {
        $root = $this->reference->getRoot();

        $this->assertSame('/', $root->getUri()->getPath());
    }

    public function testGetChild()
    {
        $child = $this->reference->getChild('child');

        $this->assertSame('parent/key/child', $child->getPath());
    }

    public function testGetInvalidChild()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reference->getChild('#');
    }

    public function testGetChildKeys()
    {
        $this->httpClient
            ->method('send')
            ->willReturn(new Response(200, [], '{"a": true, "b": true, "c": true}'));

        $this->assertSame(['a', 'b', 'c'], $this->reference->getChildKeys());
    }

    public function testGetChildKeysWhenNoChildrenAreSet()
    {
        $this->httpClient
            ->method('send')
            ->willReturn(new Response(200, [], '"scalar value"'));

        $this->expectException(OutOfRangeException::class);

        $this->reference->getChildKeys();
    }

    public function testModifiersReturnQueries()
    {
        $this->assertInstanceOf(Query::class, $this->reference->equalTo('x'));
        $this->assertInstanceOf(Query::class, $this->reference->endAt('x'));
        $this->assertInstanceOf(Query::class, $this->reference->limitToFirst(1));
        $this->assertInstanceOf(Query::class, $this->reference->limitToLast(1));
        $this->assertInstanceOf(Query::class, $this->reference->orderByChild('child'));
        $this->assertInstanceOf(Query::class, $this->reference->orderByKey());
        $this->assertInstanceOf(Query::class, $this->reference->orderByValue());
        $this->assertInstanceOf(Query::class, $this->reference->shallow());
        $this->assertInstanceOf(Query::class, $this->reference->startAt('x'));
    }

    public function testGetSnapshot()
    {
        $this->httpClient
            ->method('send')
            ->willReturn(new Response(200, [], '"value"'));

        $this->assertSame('value', $this->reference->getSnapshot()->getValue());
    }

    public function testGetValue()
    {
        $this->httpClient
            ->method('send')
            ->willReturn(new Response(200, [], json_encode('value')));

        $this->assertSame('value', $this->reference->getValue());
    }

    public function testSet()
    {
        $this->httpClient
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response());

        $this->reference->set('value');
    }

    public function testRemove()
    {
        $this->httpClient
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response());

        $this->reference->remove();
    }

    public function testUpdate()
    {
        $this->httpClient
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response());

        $this->reference->update(['any' => 'thing']);
    }

    public function testPush()
    {
        $this->httpClient
            ->method('send')
            ->willReturn(new Response(200, [], '{"name": "newChild"}'));

        $childReference = $this->reference->push('value');

        $this->assertSame('newChild', $childReference->getKey());
    }

    public function testGetUri()
    {
        $uri = $this->reference->getUri();

        $this->assertSame((string) $uri, (string) $this->reference);
    }

    public function testCatchRequestException()
    {
        $request = new Request('GET', 'foo');

        $this->httpClient
            ->method($this->anything())
            ->willThrowException(new RequestException('foo', $request));

        $this->expectException(ApiException::class);

        $this->reference->getSnapshot();
    }

    public function testCatchAnyException()
    {
        $this->httpClient
            ->method($this->anything())
            ->willThrowException(new Exception());

        $this->expectException(ApiException::class);

        $this->reference->getSnapshot();
    }
}
