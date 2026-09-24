<?php
namespace Vanqard\JsonMiddleware\Tests;

use PHPUnit\Framework\TestCase;
use Vanqard\JsonMiddleware\JsonViewData;

class JsonViewDataTest extends TestCase
{
    public function testJsonSerializeReturnsDefaultEnvelopeShape(): void
    {
        $viewData = new JsonViewData();

        $this->assertSame([
            'meta' => [
                'status' => 200,
                'code' => 0,
                'messages' => [],
                'flash' => []
            ],
            'payload' => []
        ], $viewData->jsonSerialize());
    }

    public function testAddMetaIsFluentAndMergesIntoMeta(): void
    {
        $viewData = new JsonViewData();

        $result = $viewData->addMeta('code', 42);

        $this->assertSame($viewData, $result);
        $this->assertSame(42, $viewData->getMeta()['code']);
    }

    public function testAddFlashAppendsStructuredEntryToMetaFlash(): void
    {
        $viewData = new JsonViewData();

        $viewData->addFlash('Saved successfully');
        $viewData->addFlash('Something broke', 'error');

        $this->assertSame([
            ['text' => 'Saved successfully', 'type' => 'confirm'],
            ['text' => 'Something broke', 'type' => 'error']
        ], $viewData->getMeta()['flash']);
    }

    public function testAddPayloadIsFluentAndMergesIntoPayload(): void
    {
        $viewData = new JsonViewData();

        $result = $viewData->addPayload('id', 7);

        $this->assertSame($viewData, $result);
        $this->assertSame(['id' => 7], $viewData->getPayload());
    }

    public function testSetPayloadOverwritesEntirePayload(): void
    {
        $viewData = new JsonViewData(['id' => 7]);

        $viewData->setPayload(['name' => 'test']);

        $this->assertSame(['name' => 'test'], $viewData->getPayload());
    }

    public function testConstructorAcceptsInitialPayloadAndMeta(): void
    {
        $viewData = new JsonViewData(['id' => 1], ['code' => 5]);

        $this->assertSame(['id' => 1], $viewData->getPayload());
        $this->assertSame(5, $viewData->getMeta()['code']);
    }
}
