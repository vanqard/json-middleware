<?php
namespace Vanqard\JsonMiddleware\Tests\Fixtures;

use Exception;
use Vanqard\Common\Interfaces\NotifiableExceptionInterface;

class NotifiableOnlyException extends Exception implements NotifiableExceptionInterface
{
}
