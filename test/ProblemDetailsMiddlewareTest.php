<?php

/**
 * @see       https://github.com/mezzio/mezzio-problem-details for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-problem-details/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-problem-details/blob/master/LICENSE.md New BSD License
 */

namespace MezzioTest\ProblemDetails;

use ErrorException;
use Mezzio\ProblemDetails\Exception\MissingResponseException;
use Mezzio\ProblemDetails\ProblemDetailsMiddleware;
use Mezzio\ProblemDetails\ProblemDetailsResponseFactory;
use MezzioTest\ProblemDetails\TestAsset;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Webimpress\HttpMiddlewareCompatibility\HandlerInterface as DelegateInterface;

use const Webimpress\HttpMiddlewareCompatibility\HANDLER_METHOD;

class ProblemDetailsMiddlewareTest extends TestCase
{
    use ProblemDetailsAssertionsTrait;

    protected function setUp() : void
    {
        $this->request = $this->prophesize(ServerRequestInterface::class);
        $this->responseFactory = $this->prophesize(ProblemDetailsResponseFactory::class);
        $this->middleware = new ProblemDetailsMiddleware($this->responseFactory->reveal());
    }

    public function acceptHeaders() : array
    {
        return [
            'empty'                    => [''],
            'application/xml'          => ['application/xml'],
            'application/vnd.api+xml'  => ['application/vnd.api+xml'],
            'application/json'         => ['application/json'],
            'application/vnd.api+json' => ['application/vnd.api+json'],
        ];
    }

    public function testSuccessfulDelegationReturnsDelegateResponse() : void
    {
        $response = $this->prophesize(ResponseInterface::class);
        $delegate = $this->prophesize(DelegateInterface::class);
        $delegate
            ->{HANDLER_METHOD}(Argument::that([$this->request, 'reveal']))
            ->will([$response, 'reveal']);


        $middleware = new ProblemDetailsMiddleware();
        $result = $middleware->process($this->request->reveal(), $delegate->reveal());

        $this->assertSame($response->reveal(), $result);
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testDelegateNotReturningResponseResultsInProblemDetails(string $accept) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($accept);

        $delegate = $this->prophesize(DelegateInterface::class);
        $delegate
            ->{HANDLER_METHOD}(Argument::that([$this->request, 'reveal']))
            ->willReturn('Unexpected');

        $expected = $this->prophesize(ResponseInterface::class)->reveal();
        $this->responseFactory
            ->createResponseFromThrowable($this->request->reveal(), Argument::type(MissingResponseException::class))
            ->willReturn($expected);

        $result = $this->middleware->process($this->request->reveal(), $delegate->reveal());

        $this->assertSame($expected, $result);
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testThrowableRaisedByDelegateResultsInProblemDetails(string $accept) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($accept);

        $exception = new TestAsset\RuntimeException('Thrown!', 507);

        $delegate  = $this->prophesize(DelegateInterface::class);
        $delegate
            ->{HANDLER_METHOD}(Argument::that([$this->request, 'reveal']))
            ->willThrow($exception);

        $expected = $this->prophesize(ResponseInterface::class)->reveal();
        $this->responseFactory
            ->createResponseFromThrowable($this->request->reveal(), $exception)
            ->willReturn($expected);

        $result = $this->middleware->process($this->request->reveal(), $delegate->reveal());

        $this->assertSame($expected, $result);
    }

    /**
     * @dataProvider acceptHeaders
     */
    public function testMiddlewareRegistersErrorHandlerToConvertErrorsToProblemDetails(string $accept) : void
    {
        $this->request->getHeaderLine('Accept')->willReturn($accept);

        $delegate = $this->prophesize(DelegateInterface::class);
        $delegate
            ->{HANDLER_METHOD}(Argument::that([$this->request, 'reveal']))
            ->will(function () {
                trigger_error('Triggered error!', \E_USER_ERROR);
            });

        $expected = $this->prophesize(ResponseInterface::class)->reveal();
        $this->responseFactory
            ->createResponseFromThrowable($this->request->reveal(), Argument::that(function ($e) {
                $this->assertInstanceOf(ErrorException::class, $e);
                $this->assertEquals(\E_USER_ERROR, $e->getSeverity());
                $this->assertEquals('Triggered error!', $e->getMessage());
                return true;
            }))
            ->willReturn($expected);

        $result = $this->middleware->process($this->request->reveal(), $delegate->reveal());

        $this->assertSame($expected, $result);
    }

    public function testRethrowsCaughtExceptionIfUnableToNegotiateAcceptHeader() : void
    {
        $this->request->getHeaderLine('Accept')->willReturn('text/html');
        $exception = new TestAsset\RuntimeException('Thrown!', 507);
        $delegate  = $this->prophesize(DelegateInterface::class);
        $delegate
            ->{HANDLER_METHOD}(Argument::that([$this->request, 'reveal']))
            ->willThrow($exception);

        $middleware = new ProblemDetailsMiddleware();

        $this->expectException(TestAsset\RuntimeException::class);
        $this->expectExceptionMessage('Thrown!');
        $this->expectExceptionCode(507);
        $middleware->process($this->request->reveal(), $delegate->reveal());
    }
}
