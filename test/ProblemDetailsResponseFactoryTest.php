<?php

/**
 * @see       https://github.com/mezzio/mezzio-problem-details for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-problem-details/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-problem-details/blob/master/LICENSE.md New BSD License
 */

namespace MezzioTest\ProblemDetails;

use Exception;
use Mezzio\ProblemDetails\Exception\InvalidResponseBodyException;
use Mezzio\ProblemDetails\Exception\ProblemDetailsExceptionInterface;
use Mezzio\ProblemDetails\ProblemDetailsResponseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

class ProblemDetailsResponseFactoryTest extends TestCase
{
    use ProblemDetailsAssertionsTrait;

    /**
     * @var ServerRequestInterface
     */
    private $request;

    /**
     * @var ProblemDetailsResponseFactory
     */
    private $factory;

    protected function setUp() : void
    {
        $this->request = $this->prophesize(ServerRequestInterface::class);
        $this->factory = new ProblemDetailsResponseFactory();
    }

    public function acceptHeaders() : array
    {
        return [
            'empty'                    => ['', 'application/problem+json'],
            'application/xml'          => ['application/xml', 'application/problem+xml'],
            'application/vnd.api+xml'  => ['application/vnd.api+xml', 'application/problem+xml'],
            'application/json'         => ['application/json', 'application/problem+json'],
            'application/vnd.api+json' => ['application/vnd.api+json', 'application/problem+json'],
        ];
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testCreateResponseCreatesExpectedType(string $header, string $expectedType) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($header);

        $response = $this->factory->createResponse(
            $this->request->reveal(),
            500,
            'Unknown error occurred'
        );

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals($expectedType, $response->getHeaderLine('Content-Type'));
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testCreateResponseFromThrowableCreatesExpectedType(string $header, string $expectedType) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($header);

        $exception = new RuntimeException();
        $response = $this->factory->createResponseFromThrowable(
            $this->request->reveal(),
            $exception
        );

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals($expectedType, $response->getHeaderLine('Content-Type'));
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testCreateResponseFromThrowableCreatesExpectedTypeWithExtraInformation(
        string $header,
        string $expectedType
    ) : void {
        $this->request->getHeaderLine('Accept')->willReturn($header);

        $factory = new ProblemDetailsResponseFactory(ProblemDetailsResponseFactory::INCLUDE_THROWABLE_DETAILS);

        $exception = new RuntimeException();
        $response = $factory->createResponseFromThrowable(
            $this->request->reveal(),
            $exception
        );

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals($expectedType, $response->getHeaderLine('Content-Type'));

        $payload = $this->getPayloadFromResponse($response);
        $this->assertArrayHasKey('exception', $payload);
    }

    public function testCreateResponseFromThrowableWillPullDetailsFromProblemDetailsExceptionInterface() : void
    {
        $e = $this->prophesize(RuntimeException::class)->willImplement(ProblemDetailsExceptionInterface::class);
        $e->getStatus()->willReturn(400);
        $e->getDetail()->willReturn('Exception details');
        $e->getTitle()->willReturn('Invalid client request');
        $e->getType()->willReturn('https://example.com/api/doc/invalid-client-request');
        $e->getAdditionalData()->willReturn(['foo' => 'bar']);

        $this->request->getHeaderLine('Accept')->willReturn('application/json');

        $factory = new ProblemDetailsResponseFactory();

        $response = $factory->createResponseFromThrowable(
            $this->request->reveal(),
            $e->reveal()
        );

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals('application/problem+json', $response->getHeaderLine('Content-Type'));

        $payload = $this->getPayloadFromResponse($response);
        $this->assertSame(400, $payload['status']);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Exception details', $payload['detail']);
        $this->assertSame('Invalid client request', $payload['title']);
        $this->assertSame('https://example.com/api/doc/invalid-client-request', $payload['type']);
        $this->assertSame('bar', $payload['foo']);
    }

    public function testFactoryRaisesExceptionIfBodyFactoryDoesNotReturnStream() : void
    {
        $this->request->getHeaderLine('Accept')->willReturn('application/json');

        $factory = new ProblemDetailsResponseFactory(false, null, null, function () {
            return null;
        });

        $this->expectException(InvalidResponseBodyException::class);
        $factory->createResponse($this->request->reveal(), '500', 'This is an error');
    }

    public function testFactoryGeneratesXmlResponseIfNegotiationFails() : void
    {
        $this->request->getHeaderLine('Accept')->willReturn('text/plain');

        $response = $this->factory->createResponse(
            $this->request->reveal(),
            500,
            'Unknown error occurred'
        );

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals('application/problem+xml', $response->getHeaderLine('Content-Type'));
    }

    public function testFactoryRendersPreviousExceptionsInDebugMode() : void
    {
        $this->request->getHeaderLine('Accept')->willReturn('application/json');

        $first = new RuntimeException('first', 101010);
        $second = new RuntimeException('second', 101011, $first);

        $factory = new ProblemDetailsResponseFactory(ProblemDetailsResponseFactory::INCLUDE_THROWABLE_DETAILS);

        $response = $factory->createResponseFromThrowable(
            $this->request->reveal(),
            $second
        );

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals('application/problem+json', $response->getHeaderLine('Content-Type'));

        $payload = $this->getPayloadFromResponse($response);
        $this->assertArrayHasKey('exception', $payload);
        $this->assertEquals(101011, $payload['exception']['code']);
        $this->assertEquals('second', $payload['exception']['message']);
        $this->assertArrayHasKey('stack', $payload['exception']);
        $this->assertInternalType('array', $payload['exception']['stack']);
        $this->assertEquals(101010, $payload['exception']['stack'][0]['code']);
        $this->assertEquals('first', $payload['exception']['stack'][0]['message']);
    }

    public function testFragileDataInExceptionMessageShouldBeHiddenInResponseBodyInNoDebugMode()
    {
        $fragileMessage = 'Your SQL or password here';
        $exception = new Exception($fragileMessage);

        $response = $this->factory->createResponseFromThrowable($this->request->reveal(), $exception);

        $this->assertNotContains($fragileMessage, (string) $response->getBody());
        $this->assertContains($this->factory::DEFAULT_DETAIL_MESSAGE, (string) $response->getBody());
    }

    public function testExceptionCodeShouldBeIgnoredAnd500ServedInResponseBodyInNonDebugMode()
    {
        $exception = new Exception(null, 400);

        $response = $this->factory->createResponseFromThrowable($this->request->reveal(), $exception);

        $payload = $this->getPayloadFromResponse($response);

        $this->assertSame(500, $payload['status']);
        $this->assertSame(500, $response->getStatusCode());
    }

    public function testFragileDataInExceptionMessageShouldBeVisibleInResponseBodyInNonDebugModeWhenAllowToShowByFlag()
    {
        $fragileMessage = 'Your SQL or password here';
        $exception = new Exception($fragileMessage);

        $factory = new ProblemDetailsResponseFactory(false, null, null, null, true);

        $response = $factory->createResponseFromThrowable($this->request->reveal(), $exception);

        $payload = $this->getPayloadFromResponse($response);

        $this->assertSame($fragileMessage, $payload['detail']);
    }

    public function testCustomDetailMessageShouldBeVisible()
    {
        $detailMessage = 'Custom detail message';

        $factory = new ProblemDetailsResponseFactory(false, null, null, null, false, $detailMessage);

        $response = $factory->createResponseFromThrowable($this->request->reveal(), new Exception());

        $payload = $this->getPayloadFromResponse($response);

        $this->assertSame($detailMessage, $payload['detail']);
    }
}
