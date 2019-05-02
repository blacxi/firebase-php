<?php

namespace Kreait\Firebase\Tests\Unit\Database\Reference;

use GuzzleHttp\Psr7\Uri;
use Kreait\Firebase\Database\Reference\Validator;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Tests\UnitTestCase;
use Psr\Http\Message\UriInterface;

class ValidatorTest extends UnitTestCase
{
    /**
     * @var UriInterface
     */
    private $uri;

    /**
     * @var Validator
     */
    private $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uri = new Uri('http://domain.tld');
        $this->validator = new Validator();
    }

    public function testValidateDepth()
    {
        $uri = $this->uri->withPath(\str_pad('', 66, 'x/'));

        $this->expectException(InvalidArgumentException::class);
        $this->validator->validateUri($uri);
    }

    public function testValidateKeySize()
    {
        $uri = $this->uri->withPath(\str_pad('', 769, 'x'));

        $this->expectException(InvalidArgumentException::class);
        $this->validator->validateUri($uri);
    }

    /**
     * @dataProvider invalidChars
     */
    public function testValidateChars(string $invalidChar)
    {
        $uri = $this->uri->withPath($invalidChar);

        $this->expectException(InvalidArgumentException::class);
        $this->validator->validateUri($uri);
    }

    public function invalidChars()
    {
        return [
            '.' => ['.'],
            '#' => ['#'],
            '$' => ['$'],
            '[' => ['['],
            ']' => [']'],
        ];
    }
}
