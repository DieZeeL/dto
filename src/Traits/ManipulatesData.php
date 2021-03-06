<?php

namespace Cerbero\Dto\Traits;

use Cerbero\Dto\Manipulators\ArrayConverter;

use const Cerbero\Dto\CAMEL_CASE_ARRAY;
use const Cerbero\Dto\MUTABLE;
use const Cerbero\Dto\NONE;
use const Cerbero\Dto\PARTIAL;

/**
 * Trait to manipulate a DTO data.
 *
 */
trait ManipulatesData
{
    /**
     * Merge the given data in the DTO
     *
     * @param iterable $data
     * @param int $flags
     * @return self
     */
    public function merge(iterable $data, int $flags = NONE): self
    {
        $mergedFlags = $this->getFlags() | $flags;
        $snakeCase = !($mergedFlags & CAMEL_CASE_ARRAY);
        $replacements = ArrayConverter::instance()->convert($data, $snakeCase);
        $mergedData = array_replace_recursive($this->toArray(), $replacements);

        if (!($this->getFlags() & MUTABLE)) {
            return new static($mergedData, $mergedFlags);
        }

        $this->flags = $mergedFlags;
        $this->propertiesMap = $this->mapData($mergedData);

        return $this;
    }

    /**
     * Retrieve the DTO including only the given properties
     *
     * @param array $properties
     * @param int $flags
     * @return self
     */
    public function only(array $properties, int $flags = NONE): self
    {
        $data = [];
        $isMutable = $this->getFlags() & MUTABLE;
        $mergedFlags = $this->getFlags() | $flags | PARTIAL;

        foreach ($this->getPropertiesMap() as $name => $property) {
            if (in_array($name, $properties) && !$isMutable) {
                $data[$name] = $property->value();
            } elseif (!in_array($name, $properties) && $isMutable) {
                unset($this->propertiesMap[$name]);
            }
        }

        if ($isMutable) {
            $this->flags = $mergedFlags;
            return $this;
        }

        return new static($data, $mergedFlags);
    }

    /**
     * Retrieve the DTO excluding the given properties
     *
     * @param array $properties
     * @param int $flags
     * @return self
     */
    public function except(array $properties, int $flags = NONE): self
    {
        $propertiesToKeep = array_diff($this->getPropertyNames(), $properties);

        return $this->only($propertiesToKeep, $flags);
    }

    /**
     * Make the DTO mutable while calling the given callback
     *
     * @param callable $callback
     * @return self
     */
    public function mutate(callable $callback): self
    {
        $wasMutable = $this->getFlags() & MUTABLE;
        $this->flags |= MUTABLE;

        $callback($this);

        if (!$wasMutable) {
            $this->flags ^= MUTABLE;
        }

        return $this;
    }
}
