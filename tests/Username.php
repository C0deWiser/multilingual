<?php

namespace Codewiser\Tests;

use Illuminate\Contracts\Support\Arrayable;

class Username implements Arrayable, \JsonSerializable
{
    public string $first_name;
    public string $last_name;

    /**
     * Create a new Username instance.
     */
    public function __construct(array $data)
    {
        $this->first_name = $data['first_name'];
        $this->last_name = $data['last_name'];
    }

    /**
     * Get the instance as an array.
     *
     * @return array{first_name: string, last_name: string}
     */
    public function toArray(): array
    {
        return (array) $this;
    }

    /**
     * Specify the data which should be serialized to JSON.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}