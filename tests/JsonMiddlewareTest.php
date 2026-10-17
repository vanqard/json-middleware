<?php
namespace Vanqard\JsonMiddleware\Tests;

use Error;
use Exception;
use PDOException;
use Throwable;
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

    private function handlerThrowing(Throwable $exception): RequestHandlerInterface
    {
        return new class ($exception) implements RequestHandlerInterface {
            public function __construct(private Throwable $exception)
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

    public function testProcessRendersMalformedBodyAs400Envelope(): void
    {
        $viewData = new JsonViewData();
        $tracker = $this->createMock(TrackerInterface::class);
        $tracker->expects($this->once())->method('notify')->with($this->isInstanceOf(InvalidJsonPayloadException::class));
        $middleware = new JsonMiddleware($viewData, $tracker, $this->factory);

        $request = $this->factory->createServerRequest('POST', '/')
            ->withBody($this->factory->createStream('not json'))
            ->withParsedBody(null);

        $response = $middleware->process($request, $this->handlerReturning($this->factory->createResponse(200)));

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('The request payload is malformed and cannot be parsed', $body['meta']['flash'][0]['text']);
    }

    public function testProcessRethrowsMalformedBodyInDevEnvironment(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        $middleware = new JsonMiddleware(new JsonViewData(), $this->createMock(TrackerInterface::class), $this->factory);

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

    public function testProcessReportsUnclassifiedExceptionAsGeneric500(): void
    {
        $viewData = new JsonViewData();
        $tracker = $this->createMock(TrackerInterface::class);
        $exception = new Exception('some internal detail');
        $tracker->expects($this->once())->method('notify')->with($exception);

        $middleware = new JsonMiddleware($viewData, $tracker, $this->factory);
        $handler = $this->handlerThrowing($exception);

        $response = $middleware->process($this->request(), $handler);

        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(500, $body['meta']['status']);
        $this->assertSame(
            'A technical error has occurred. Please try again later',
            $body['meta']['flash'][0]['text']
        );
    }

    public function testProcessCatchesPhpErrors(): void
    {
        $tracker = $this->createMock(TrackerInterface::class);
        $error = new Error('Call to a member function on null');
        $tracker->expects($this->once())->method('notify')->with($error);
        $middleware = new JsonMiddleware(new JsonViewData(), $tracker, $this->factory);

        $response = $middleware->process($this->request(), $this->handlerThrowing($error));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(JsonMiddleware::GENERIC_ERROR_MESSAGE, $body['meta']['flash'][0]['text']);
    }

    public function testProcessRethrowsPhpErrorsInDevEnvironment(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        $middleware = new JsonMiddleware(new JsonViewData(), $this->createMock(TrackerInterface::class), $this->factory);
        $error = new Error('boom');

        $this->expectException(Error::class);
        $this->expectExceptionMessage('boom');

        $middleware->process($this->request(), $this->handlerThrowing($error));
    }

    public function testProcessDropsPayloadAndFlashesSetBeforeTheFailure(): void
    {
        $viewData = new JsonViewData();
        $viewData->setPayload(['email' => 'private@example.org']);
        $viewData->addFlash('Saved!');
        $middleware = new JsonMiddleware($viewData, $this->createMock(TrackerInterface::class), $this->factory);

        $response = $middleware->process($this->request(), $this->handlerThrowing(new NotifiableOnlyException('boom', 500)));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame([], $body['payload']);
        $this->assertCount(1, $body['meta']['flash']);
        $this->assertSame('error', $body['meta']['flash'][0]['type']);
    }

    public function testProcessSubstitutesInvalidUtf8InsteadOfFailing(): void
    {
        $viewData = new JsonViewData(['summary' => "caf\xE9"]);
        $middleware = new JsonMiddleware($viewData, $this->createMock(TrackerInterface::class), $this->factory);

        $response = $middleware->process($this->request(), $this->handlerReturning($this->factory->createResponse(200)));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame("caf\u{FFFD}", json_decode((string) $response->getBody(), true)['payload']['summary']);
    }

    public function testProcessStillRendersEnvelopeWhenPayloadCannotBeEncoded(): void
    {
        $viewData = new JsonViewData(['ratio' => NAN]);
        $tracker = $this->createMock(TrackerInterface::class);
        $tracker->expects($this->once())->method('notify')->with($this->isInstanceOf(\JsonException::class));
        $middleware = new JsonMiddleware($viewData, $tracker, $this->factory);

        $response = $middleware->process($this->request(), $this->handlerReturning($this->factory->createResponse(200)));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([], json_decode((string) $response->getBody(), true)['payload']);
    }

    public function testProcessCopesWithUnsetAppEnvUnderStrictErrorHandler(): void
    {
        unset($_ENV['APP_ENV']);
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $middleware = new JsonMiddleware(new JsonViewData(), $this->createMock(TrackerInterface::class), $this->factory);
            $response = $middleware->process($this->request(), $this->handlerThrowing(new PublishableOnlyException('nope', 422)));
        } finally {
            restore_error_handler();
        }

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testProcessStillRespondsWhenTheTrackerFails(): void
    {
        $tracker = $this->createMock(TrackerInterface::class);
        $tracker->method('notify')->willThrowException(new Exception('bugsnag unreachable'));
        $middleware = new JsonMiddleware(new JsonViewData(), $tracker, $this->factory);

        $response = $middleware->process($this->request(), $this->handlerThrowing(new NotifiableOnlyException('boom', 503)));

        $this->assertSame(503, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{Throwable, int}>
     */
    public static function statusCodes(): iterable
    {
        yield 'publishable without a code' => [new PublishableOnlyException('fix this'), 400];
        yield 'notifiable without a code' => [new NotifiableOnlyException('disk full'), 500];
        yield 'PDO string SQLSTATE code' => [self::pdoException('23000'), 500];
        yield 'real HTTP code kept' => [new NotifiableOnlyException('gone', 410), 410];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusCodes')]
    public function testProcessChoosesStatusCode(Throwable $exception, int $expected): void
    {
        $middleware = new JsonMiddleware(new JsonViewData(), $this->createMock(TrackerInterface::class), $this->factory);

        $response = $middleware->process($this->request(), $this->handlerThrowing($exception));

        $this->assertSame($expected, $response->getStatusCode());
    }

    public function testProcessLeavesRequestBodyReadableForTheHandler(): void
    {
        $stream = $this->factory->createStream('{"title":"x"}');
        $request = $this->factory->createServerRequest('POST', '/')->withBody($stream)->withParsedBody(['title' => 'x']);
        $handler = new class ($this->factory) implements RequestHandlerInterface {
            public string $seen = '';

            public function __construct(private Psr17Factory $factory)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request->getBody()->getContents();
                return $this->factory->createResponse(200);
            }
        };

        (new JsonMiddleware(new JsonViewData(), $this->createMock(TrackerInterface::class), $this->factory))->process($request, $handler);

        $this->assertSame('{"title":"x"}', $handler->seen);
    }

    private static function pdoException(string $sqlState): PDOException
    {
        $exception = new PDOException('SQLSTATE[' . $sqlState . ']');
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($exception, $sqlState);
        return $exception;
    }
}
