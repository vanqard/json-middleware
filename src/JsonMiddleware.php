<?php
namespace Vanqard\JsonMiddleware;

use Exception;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
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

    /**
     * @param Request $request
     * @param RequestHandler $handler
     * @return Response
     * @throws InvalidJsonPayloadException
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        $this->validateRequestPayload($request);

        try {
            $response = $handler->handle($request);
            $content = $this->jsonViewData->jsonSerialize();
            $json = json_encode($content, JSON_THROW_ON_ERROR);
            $response->getBody()->write($json);
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (Exception $e) {

            if ($_ENV['APP_ENV'] === 'dev') {
                throw $e;
            }

            if ($e instanceof NotifiableExceptionInterface) {
                $this->tracker->notify($e);
            }

            $statusCode = (int) $e->getCode();

            if ($statusCode < 400 || $statusCode >= 600) {
                $statusCode = 400;
            }

            $response = $this->responseFactory->createResponse($statusCode);
            $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');

            if ($e instanceof PublishableExceptionInterface) {
                $this->jsonViewData->addFlash($e->getMessage(), 'error');
            } else {
                $this->jsonViewData->addFlash("A technical error has occurred. Please try again later", 'error');
            }

            $this->jsonViewData->addMeta('status', $statusCode);
            $content = $this->jsonViewData->jsonSerialize();
            $response->getBody()->write(json_encode($content));
        }

        return $response;
    }

    /**
     * @param Request $request
     * @return bool
     * @throws InvalidJsonPayloadException
     */
    private function validateRequestPayload(Request $request): bool
    {
        $rawPayload = $request->getBody()->getContents();
        $parsedPayload = $request->getParsedBody();

        $isProblem = is_null($parsedPayload) && !empty($rawPayload);
        if ($isProblem) {
            throw new InvalidJsonPayloadException("The request payload is malformed and cannot be parsed", 400);
        }

        return true;
    }
}
