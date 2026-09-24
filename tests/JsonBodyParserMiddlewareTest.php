<?php
namespace Vanqard\JsonMiddleware\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Vanqard\JsonMiddleware\JsonBodyParserMiddleware;

/**
 * Note: the "application/json" body-decoding branch reads directly from
 * php://input rather than $request->getBody(), so it can't be exercised
 * here without a fragile stream-wrapper override. Only the passthrough
 * branch (non-JSON content type) is covered.
 */
class JsonBodyParserMiddlewareTest extends TestCase
{
    public function testProcessSkipsParsingForNonJsonContentType(): void
    {
        $factory = new Psr17Factory();
        $middleware = new JsonBodyParserMiddleware();

        $request = $factory->createServerRequest('POST', '/')
            ->withHeader('Content-Type', 'text/plain')
            ->withParsedBody(['already' => 'set']);

        $handler = new class ($factory) implements RequestHandlerInterface {
            public ?ServerRequestInterface $receivedRequest = null;

            public function __construct(private Psr17Factory $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->receivedRequest = $request;
                return $this->factory->createResponse(200);
            }
        };

        $middleware->process($request, $handler);

        $this->assertSame(['already' => 'set'], $handler->receivedRequest->getParsedBody());
    }
}
