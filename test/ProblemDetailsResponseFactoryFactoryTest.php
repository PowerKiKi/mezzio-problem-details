<?php

/**
 * @see       https://github.com/mezzio/mezzio-problem-details for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-problem-details/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-problem-details/blob/master/LICENSE.md New BSD License
 */

namespace MezzioTest\ProblemDetails;

use Closure;
use Laminas\Diactoros\Response;
use Mezzio\ProblemDetails\ProblemDetailsResponseFactory;
use Mezzio\ProblemDetails\ProblemDetailsResponseFactoryFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

class ProblemDetailsResponseFactoryFactoryTest extends TestCase
{
    protected function setUp() : void
    {
        $this->container = $this->prophesize(ContainerInterface::class);
    }

    public function testLackOfOptionalServicesResultsInFactoryUsingDefaults() : void
    {
        $this->container->has('config')->willReturn(false);
        $this->container->has(ResponseInterface::class)->willReturn(false);
        $this->container->has('Mezzio\ProblemDetails\StreamFactory')->willReturn(false);

        $factoryFactory = new ProblemDetailsResponseFactoryFactory();
        $factory = $factoryFactory($this->container->reveal());

        $this->assertInstanceOf(ProblemDetailsResponseFactory::class, $factory);
        $this->assertAttributeSame(ProblemDetailsResponseFactory::EXCLUDE_THROWABLE_DETAILS, 'isDebug', $factory);
        $this->assertAttributeSame(
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            'jsonFlags',
            $factory
        );

        $this->assertAttributeInstanceOf(Response::class, 'response', $factory);
        $this->assertAttributeInstanceOf(Closure::class, 'bodyFactory', $factory);
    }

    public function testUsesDebugSettingFromConfigWhenPresent() : void
    {
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn(['debug' => true]);

        $this->container->has(ResponseInterface::class)->willReturn(false);
        $this->container->has('Mezzio\ProblemDetails\StreamFactory')->willReturn(false);

        $factoryFactory = new ProblemDetailsResponseFactoryFactory();
        $factory = $factoryFactory($this->container->reveal());

        $this->assertInstanceOf(ProblemDetailsResponseFactory::class, $factory);
        $this->assertAttributeSame(ProblemDetailsResponseFactory::INCLUDE_THROWABLE_DETAILS, 'isDebug', $factory);
        $this->assertAttributeSame(true, 'exceptionDetailsInResponse', $factory);
    }

    public function testUsesJsonFlagsSettingFromConfigWhenPresent() : void
    {
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn(['problem-details' => ['json_flags' => JSON_PRETTY_PRINT]]);

        $this->container->has(ResponseInterface::class)->willReturn(false);
        $this->container->has('Mezzio\ProblemDetails\StreamFactory')->willReturn(false);

        $factoryFactory = new ProblemDetailsResponseFactoryFactory();
        $factory = $factoryFactory($this->container->reveal());

        $this->assertInstanceOf(ProblemDetailsResponseFactory::class, $factory);
        $this->assertAttributeSame(JSON_PRETTY_PRINT, 'jsonFlags', $factory);
    }

    public function testUsesResponseServiceFromContainerWhenPresent() : void
    {
        $response = $this->prophesize(ResponseInterface::class)->reveal();

        $this->container->has('config')->willReturn(false);
        $this->container->has(ResponseInterface::class)->willReturn(true);
        $this->container->get(ResponseInterface::class)->willReturn($response);
        $this->container->has('Mezzio\ProblemDetails\StreamFactory')->willReturn(false);

        $factoryFactory = new ProblemDetailsResponseFactoryFactory();
        $factory = $factoryFactory($this->container->reveal());

        $this->assertInstanceOf(ProblemDetailsResponseFactory::class, $factory);
        $this->assertAttributeSame($response, 'response', $factory);
    }

    public function testUsesStreamFactoryServiceFromContainerWhenPresent() : void
    {
        // @codingStandardsIgnoreStart
        $streamFactory = function () { };
        // @codingStandardsIgnoreEnd

        $this->container->has('config')->willReturn(false);
        $this->container->has(ResponseInterface::class)->willReturn(false);
        $this->container->has('Mezzio\ProblemDetails\StreamFactory')->willReturn(true);
        $this->container->get('Mezzio\ProblemDetails\StreamFactory')->willReturn($streamFactory);

        $factoryFactory = new ProblemDetailsResponseFactoryFactory();
        $factory = $factoryFactory($this->container->reveal());

        $this->assertInstanceOf(ProblemDetailsResponseFactory::class, $factory);
        $this->assertAttributeSame($streamFactory, 'bodyFactory', $factory);
    }
}
