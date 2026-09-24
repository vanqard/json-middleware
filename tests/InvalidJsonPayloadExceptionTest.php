<?php
namespace Vanqard\JsonMiddleware\Tests;

use PHPUnit\Framework\TestCase;
use Vanqard\Common\Interfaces\NotifiableExceptionInterface;
use Vanqard\Common\Interfaces\PublishableExceptionInterface;
use Vanqard\JsonMiddleware\InvalidJsonPayloadException;

class InvalidJsonPayloadExceptionTest extends TestCase
{
    public function testImplementsBothCommonExceptionInterfaces(): void
    {
        $exception = new InvalidJsonPayloadException('malformed payload', 400);

        $this->assertInstanceOf(NotifiableExceptionInterface::class, $exception);
        $this->assertInstanceOf(PublishableExceptionInterface::class, $exception);
    }
}
