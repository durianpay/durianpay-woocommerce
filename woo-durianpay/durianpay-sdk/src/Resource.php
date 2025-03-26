<?php

namespace Durianpay\Api;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use Traversable;

class Resource implements ArrayAccess, IteratorAggregate
{
    protected array $attributes = [];

    // Implementing IteratorAggregate correctly
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->attributes);
    }

    // Fixing ArrayAccess method signatures
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

    // Keeping existing magic methods for flexibility
    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }
}