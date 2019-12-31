<?php

/**
 * @see       https://github.com/mezzio/mezzio-problem-details for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-problem-details/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-problem-details/blob/master/LICENSE.md New BSD License
 */

namespace Mezzio\ProblemDetails;

use Laminas\Stratigility\Delegate\CallableDelegateDecorator;
use Negotiation\Negotiator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Webimpress\HttpMiddlewareCompatibility\HandlerInterface as DelegateInterface;
use Webimpress\HttpMiddlewareCompatibility\MiddlewareInterface as ServerMiddlewareInterface;

use const Webimpress\HttpMiddlewareCompatibility\HANDLER_METHOD;

class ProblemDetailsNotFoundHandler implements ServerMiddlewareInterface
{
    /**
     * @var ProblemDetailsResponseFactory
     */
    private $responseFactory;

    /**
     * @param null|ProblemDetailsResponseFactory $responseFactory Factory to create a response to
     *     update and return when returning an 404 response.
     */
    public function __construct(ProblemDetailsResponseFactory $responseFactory = null)
    {
        $this->responseFactory = $responseFactory ?: new ProblemDetailsResponseFactory();
    }

    /**
     * Creates and returns a 404 response.
     */
    public function process(ServerRequestInterface $request, DelegateInterface $delegate) : ResponseInterface
    {
        // If we cannot provide a representation, act as a no-op.
        if (! $this->canActAsErrorHandler($request)) {
            return $delegate->{HANDLER_METHOD}($request);
        }

        return $this->responseFactory->createResponse(
            $request,
            404,
            sprintf('Cannot %s %s!', $request->getMethod(), (string) $request->getUri())
        );
    }

    /**
     * Can the middleware act as an error handler?
     */
    private function canActAsErrorHandler(ServerRequestInterface $request) : bool
    {
        $accept = $request->getHeaderLine('Accept') ?: '*/*';

        return null !== (new Negotiator())
            ->getBest($accept, ProblemDetailsResponseFactory::NEGOTIATION_PRIORITIES);
    }
}
