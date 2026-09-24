<?php
namespace Vanqard\JsonMiddleware\Tests;

use Exception;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Vanqard\Common\Interfaces\TrackerInterface;
use Vanqard\JsonMiddleware\InvalidJsonPayloadException;
use Vanqard\JsonMiddleware\JsonMiddleware;
use Vanqard\JsonMiddleware\JsonViewData;
use Vanqard\JsonMiddleware\Tests\Fixtures\NotifiableOnlyException;
use Vanqard\JsonMiddleware\Tests\Fixtures\PublishableOnlyException;

class JsonMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $_ENV['APP_ENV'] = 'production';
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_ENV']);
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    private function handlerThrowing(Exception $exception): RequestHandlerInterface
    {
        return new class ($exception) implements RequestHandlerInterface {
            public function __construct(private Exception $exception)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->exception;
            }
        };
    }

    private function request(): ServerRequestInterface
    {
        return $this->factory->createServerRequest('GET', '/')->withParsedBody([]);
    }

    public function testProcessWritesJsonEnvelopeAndContentTypeOnHappyPath(): void
    {
        $viewData = new JsonViewData(['hello' => 'world']);
        $tracker = $this->createMock(TrackerInterface::class);
        $tracker->expects($this->never())->method('notify');

        $middleware = new JsonMiddleware($viewData, $tracker, $this->factory);
        $handler = $this->handlerReturning($this->factory->createResponse(200));

        $response = $middleware->process($this->request(), $handler);

        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame([
            'meta' => ['status' => 200, 'code' => 0, 'messages' => [], 'flash' => []],
            'payload' => ['hello' => 'world']
        ], json_decode((string) $response->getBody(), true));
    }

    public function testProcessThrowsInvalidJsonPayloadExceptionForMalformedBody(): void
    {
        $viewData = new JsonViewData();
        $tracker = $this->createMock(TrackerInterface::class);
        $middleware = new JsonMiddleware($viewData, $tracker, $this->factory);

        $request = $this->factory->createServerRequest('POST', '/')
            ->withBody($this->factory->createStream('not json'))
            ->withParsedBody(null);

        $this->expectException(InvalidJsonPayloadException::class);

        $middleware->process($request, $this->handlerReturning($this->factory->createResponse(200)));
    }

    public function testProcessRethrowsInDevEnvironment(): void
    {
        $_ENV['APP_ENV'] = 'dev';

        $viewData = new JsonViewData();
        $tracker = $this->createMock(TrackerInterface::class);
        $middleware = new JsonMiddleware($viewData, $tracker, $this->factory);

        $exception = new Exception('boom');
        $handler = $this->handlerThrowing($exception);

        $this->expectExceptionObject($exception);

        $middleware->process($this->request(), $handler);
    }

    public function testProcessNotifiesTrackerForNotifiableException(): void
    {
        $viewData = new JsonViewData();
        $tracker = $this->createMock(TrackerInterface::class);
        $exception = new NotifiableOnlyException('tracked issue');
        $tracker->expects($this->once())->method('notify')->with($exception);

        $middleware = new JsonMiddleware($viewData, $tracker, $this->factory);
        $handler = $this->handlerThrowing($exception);

        $response = $middleware->process($this->request(), $handler);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(
            'A technical error has occurred. Please try again later',
            $body['meta']['flash'][0]['text']
        );
    }

    public function testProcessAddsFlashAndStatusMetaForPublishableException(): void
    {
        $viewData = new JsonViewData();
        $tracker = $this->createMock(TrackerInterface::class);
        $tracker->expects($this->never())->method('notify');

        $exception = new PublishableOnlyException('please fix your input', 422);
        $middleware = new JsonMiddleware($viewData, $tracker, $this->factory);
        $handler = $this->handlerThrowing($exception);

        $response = $middleware->process($this->request(), $handler);

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(422, $body['meta']['status']);
        $this->assertSame('please fix your input', $body['meta']['flash'][0]['text']);
        $this->assertSame('error', $body['meta']['flash'][0]['type']);
    }

    public function testProcessUsesGenericFlashForNonPublishableNonNotifiableException(): void
    {
        $viewData = new JsonViewData();
        $tracker = $this->createMock(TrackerInterface::class);
        $tracker->expects($this->never())->method('notify');

        $exception = new Exception('some internal detail');
        $middleware = new JsonMiddleware($viewData, $tracker, $this->factory);
        $handler = $this->handlerThrowing($exception);

        $response = $middleware->process($this->request(), $handler);

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(400, $body['meta']['status']);
        $this->assertSame(
            'A technical error has occurred. Please try again later',
            $body['meta']['flash'][0]['text']
        );
    }
}
