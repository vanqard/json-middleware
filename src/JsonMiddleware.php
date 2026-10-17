<?php
namespace Vanqard\JsonMiddleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Throwable;
use Vanqard\Common\Interfaces\NotifiableExceptionInterface;
use Vanqard\Common\Interfaces\PublishableExceptionInterface;
use Vanqard\Common\Interfaces\TrackerInterface;

class JsonMiddleware implements MiddlewareInterface
{
    /**
     * @var JsonViewData
     */
    protected JsonViewData $jsonViewData;

    /**
     * @var TrackerInterface
     */
    protected TrackerInterface $tracker;

    /**
     * @var ResponseFactoryInterface
     */
    private ResponseFactoryInterface $responseFactory;

    /**
     * @param JsonViewData $jsonViewData
     * @param TrackerInterface $tracker
     * @param ResponseFactoryInterface $responseFactory
     */
    public function __construct(
        JsonViewData $jsonViewData,
        TrackerInterface $tracker,
        ResponseFactoryInterface $responseFactory
    ) {
        $this->jsonViewData = $jsonViewData;
        $this->tracker = $tracker;
        $this->responseFactory = $responseFactory;
    }

    public const GENERIC_ERROR_MESSAGE = "A technical error has occurred. Please try again later";

    /**
     * Renders JsonViewData as the response body. Anything thrown while handling
     * the request becomes a JSON error envelope, except in dev, where it's rethrown.
     *
     * @param Request $request
     * @param RequestHandler $handler
     * @return Response
     * @throws \Throwable only when APP_ENV is 'dev'
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        try {
            $this->validateRequestPayload($request);

            $response = $handler->handle($request);
            $json = json_encode(
                $this->jsonViewData->jsonSerialize(),
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $response->getBody()->write($json);
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (Throwable $e) {
            if (($_ENV['APP_ENV'] ?? null) === 'dev') {
                throw $e;
            }

            return $this->renderError($e);
        }
    }

    /**
     * Never throws: whatever happens here, the client gets a JSON envelope.
     */
    private function renderError(Throwable $e): Response
    {
        $isPublishable = $e instanceof PublishableExceptionInterface;

        // Report anything not meant for the client, as well as anything marked notifiable:
        // an unexpected exception is exactly the one someone needs to hear about
        if ($e instanceof NotifiableExceptionInterface || !$isPublishable) {
            try {
                $this->tracker->notify($e);
            } catch (Throwable) {
                // A broken tracker must not stop the error response
            }
        }

        $statusCode = (int) $e->getCode();
        if ($statusCode < 400 || $statusCode >= 600) {
            // A message written for the client describes a client problem; anything else is ours
            $statusCode = $isPublishable ? 400 : 500;
        }

        // Drop whatever the handler set before it failed, so partial data can't leak
        $this->jsonViewData->setPayload([]);
        $this->jsonViewData->addMeta(JsonViewData::META_FLASH_KEY, []);
        $this->jsonViewData->addFlash($isPublishable ? $e->getMessage() : self::GENERIC_ERROR_MESSAGE, 'error');
        $this->jsonViewData->addMeta('status', $statusCode);

        try {
            $json = json_encode(
                $this->jsonViewData->jsonSerialize(),
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (Throwable) {
            $json = json_encode([
                'meta' => ['status' => $statusCode, 'code' => 0, 'messages' => [], 'flash' => [
                    ['text' => self::GENERIC_ERROR_MESSAGE, 'type' => 'error'],
                ]],
                'payload' => [],
            ]);
        }

        $response = $this->responseFactory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write((string) $json);

        return $response;
    }

    /**
     * @param Request $request
     * @return bool
     * @throws InvalidJsonPayloadException
     */
    private function validateRequestPayload(Request $request): bool
    {
        // Read the whole body wherever the stream is, then rewind it for the handler
        $body = $request->getBody();
        $rawPayload = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $parsedPayload = $request->getParsedBody();

        $isProblem = is_null($parsedPayload) && !empty($rawPayload);
        if ($isProblem) {
            throw new InvalidJsonPayloadException("The request payload is malformed and cannot be parsed", 400);
        }

        return true;
    }
}
