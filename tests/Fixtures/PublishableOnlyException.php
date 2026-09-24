<?php
namespace Vanqard\JsonMiddleware\Tests\Fixtures;

use Exception;
use Vanqard\Common\Interfaces\PublishableExceptionInterface;

class PublishableOnlyException extends Exception implements PublishableExceptionInterface
{
}
