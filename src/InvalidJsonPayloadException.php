<?php
namespace Vanqard\JsonMiddleware;

use Exception;
use Vanqard\Common\Interfaces\NotifiableExceptionInterface;
use Vanqard\Common\Interfaces\PublishableExceptionInterface;

class InvalidJsonPayloadException extends Exception implements
    NotifiableExceptionInterface,
    PublishableExceptionInterface
{
}
