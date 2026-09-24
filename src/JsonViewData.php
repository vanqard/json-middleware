<?php
namespace Vanqard\JsonMiddleware;

/**
 * Class JsonViewData
 * @package Vanqard\JsonMiddleware
 */
class JsonViewData
{
    public const META_FLASH_KEY = 'flash';

    private $meta = [
        'status' => 200,
        'code' => 0,
        'messages' => [],
        'flash' => []
    ];

    /**
     * @var array
     */
    private mixed $payload;

    /**
     * @var mixed
     */
    private mixed $data;

    /**
     * JsonViewData constructor.
     */
    public function __construct(array $payload = [], array $meta = [])
    {
        $this->payload = $payload;
        $this->meta = array_merge($this->meta, $meta);
    }

    /**
     * @param string $key
     * @param $value
     * @return JsonViewData
     */
    public function addMeta(string $key, $value) : JsonViewData
    {
        $this->meta[$key] = $value;
        return $this;
    }

    /**
     * @return array
     */
    public function getMeta() : array
    {
        return $this->meta;
    }

    /**
     * @param string $msg
     * @return JsonViewData
     */
    public function addFlash(string $msg, string $type = 'confirm') : JsonViewData
    {
        $this->meta[static::META_FLASH_KEY][] = [
            'text' => $msg,
            'type' => $type
        ];

        return $this;
    }

    /**
     * @param string $key
     * @param $value
     * @return JsonViewData
     */
    public function addPayload(string $key, $value) : JsonViewData
    {
        $this->payload[$key] = $value;
        return  $this;
    }

    /**
     * Overwrites current payload data with the explicit content of $value
     *
     * @param mixed $value
     * @return JsonViewData
     */
    public function setPayload($value) : JsonViewData
    {
        $this->payload = $value;
        return $this;
    }

    /**
     * @return array
     */
    public function getPayload() : array
    {
        return $this->payload;
    }

    /**
     * Returns a standardised structure for an API response document
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            "meta" => (array)$this->meta,
            "payload" => $this->payload
        ];
    }

    /**
     * @param mixed $data
     * @return JsonViewData
     */
    public function setData(mixed $data): JsonViewData
    {
        $this->data = $data;
        return $this;
    }
}
