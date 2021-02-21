<?php

namespace Cerbero\Dto;

use Cerbero\Dto\Exceptions\UnexpectedValueException;

/**
 * The DTO property.
 *
 */
class DtoProperty
{
    /**
     * The property name.
     *
     * @var string
     */
    protected $name;

    /**
     * The property raw value.
     *
     * @var mixed
     */
    protected $rawValue;

    /**
     * The property types.
     *
     * @var DtoPropertyTypes
     */
    protected $types;

    /**
     * The DTO flags.
     *
     * @var int
     */
    protected $flags;

    /**
     * @var \Closure;
     */
    protected $closure = null;

    /**
     * @var bool
     */
    protected $expectClosure = false;


    /**
     * The property value processor.
     *
     * @var DtoPropertyValueProcessor
     */
    protected $valueProcessor;

    /**
     * The processed value.
     *
     * @var mixed
     */
    protected $processedValue;

    /**
     * Whether the value has been processed.
     *
     * @var bool
     */
    protected $valueIsProcessed = false;

    /**
     * Instantiate the class.
     *
     * @param string $name
     * @param mixed $rawValue
     * @param DtoPropertyTypes $types
     * @param int $flags
     */
    protected function __construct(string $name, $rawValue, DtoPropertyTypes $types, int $flags, bool $closure)
    {
        $this->name = $name;
        $this->rawValue = $rawValue;
        $this->types = $types;
        $this->flags = $flags;
        $this->expectClosure = $closure;
        $this->valueProcessor = new DtoPropertyValueProcessor($this);
    }

    /**
     * Retrieve a DTO property instance after validating it
     *
     * @param string $name
     * @param mixed $rawValue
     * @param DtoPropertyTypes $types
     * @param int $flags
     * @param bool $closure
     * @return self
     * @throws UnexpectedValueException
     */
    public static function create(string $name, $rawValue, DtoPropertyTypes $types, int $flags, bool $closure): self
    {
        $instance = new static($name, $rawValue, $types, $flags, $closure);

        return $instance->validate();
    }

    /**
     * Validate the current property value depending on types and flags
     *
     * @return self
     * @throws UnexpectedValueException
     * @todo Change Validation (validate after Closure);
     */
    public function validate(): self
    {
        $canBeDto = $this->rawValue instanceof Dto || is_array($this->rawValue);

        switch (true) {
            case $this->types->expectedDto && $canBeDto:
            case $this->rawValue === null && $this->types->includeNull:
            case $this->types->expectCollection && is_iterable($this->rawValue):
            case $this->hasClosure():
            case $this->types->match($this->value()):
                return $this;
        }

        throw new UnexpectedValueException($this);
    }

    /**
     * Retrieve the processed value
     *
     * @return void
     * @todo remove null validation after changes in validate() method
     */
    public function value()
    {
        if (!$this->valueIsProcessed) {
            $this->processedValue = $this->valueProcessor->process();
            $this->valueIsProcessed = true;

            if ($this->processedValue === null && !$this->types->includeNull) {
                throw new UnexpectedValueException($this);
            }
        }

        return $this->processedValue;
    }

    /**
     * Retrieve the property name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Retrieve the property raw value
     *
     * @return mixed
     */
    public function getRawValue()
    {
        return $this->rawValue;
    }

    /**
     * Retrieve the property types
     *
     * @return DtoPropertyTypes
     */
    public function getTypes(): DtoPropertyTypes
    {
        return $this->types;
    }

    /**
     * Retrieve the DTO flags
     *
     * @return int
     */
    public function getFlags(): int
    {
        return $this->flags;
    }

    /**
     * Retrieve closure method
     *
     * @return int
     */
    public function getClosure(): ?\Closure
    {
        return $this->closure;
    }

    /**
     * Retrieve closure method
     *
     * @return int
     */
    public function setClosure($closure): self
    {
        $this->closure = $closure;
        return $this;
    }

    /**
     * Retrieve closure method
     *
     * @return bool
     */
    public function hasClosure(): bool
    {
        return $this->expectClosure;
    }

    /**
     * Retrieve the Origin Values
     *
     * @return int
     */
    public function getOrigins(): ?array
    {
        return $this->originData;
    }

    /**
     * Set a new value to this property and validate it
     *
     * @param mixed $rawValue
     * @param int $flags
     * @return self
     */
    public function setValue($rawValue, int $flags): self
    {
        $this->rawValue = $rawValue;
        $this->flags = $flags;
        $this->valueIsProcessed = false;

        return $this->validate();
    }

    /**
     * Determine how to clone the DTO property
     *
     * @return void
     */
    public function __clone()
    {
        $this->types = clone $this->types;
        $this->valueProcessor = new DtoPropertyValueProcessor($this);
    }
}
