<?php

namespace Cerbero\Dto;

use Cerbero\Dto\Exceptions\DtoNotFoundException;
use Cerbero\Dto\Exceptions\InvalidDocCommentException;
use Cerbero\Dto\Exceptions\MissingValueException;
use Cerbero\Dto\Exceptions\UnknownDtoPropertyException;
use Cerbero\Dto\Manipulators\ArrayConverter;
use ReflectionClass;
use ReflectionException;

/**
 * The DTO properties mapper.
 *
 */
class DtoPropertiesMapper
{
    /**
     * The property doc comment pattern
     *
     * - Start with "@property" or "@property-read"
     * - Capture property type with possible "[]" suffix
     * - Capture variable name "$foo" or "foo"
     */
    protected const RE_PROPERTY = '/@property(?:-read)?\s+((?:[\w\\\_]+(?:\[])?\|?)+)\s+\$?([\w_]+)/';

    /**
     * The "use" statement pattern
     *
     * - Capture fully qualified class name
     * - Capture possible class alias after "as"
     */
    protected const RE_USE_STATEMENT = '/([\w\\\_]+)(?:\s+as\s+([\w_]+))?;/i';

    /**
     * The DTO properties mapper instances.
     *
     * @var DtoPropertiesMapper[]
     */
    protected static $instances = [];

    /**
     * The DTO class to map properties for.
     *
     * @var string
     */
    protected $dtoClass;

    /**
     * The reflection of the DTO class.
     *
     * @var ReflectionClass
     */
    protected $reflection;

    /**
     * The cached raw properties.
     *
     * @var array
     */
    protected $rawProperties;

    /**
     * The cached use statements.
     *
     * @var array
     */
    protected $useStatements;

    /**
     * The cached modify methods.
     *
     * @var \ReflectionMethod[]
     */
    protected $modifyMethods;

    /**
     * The cached mapped properties.
     *
     * @var array
     */
    protected $mappedProperties;

    /**
     * Instantiate the class.
     *
     * @param  string  $dtoClass
     * @throws DtoNotFoundException
     */
    protected function __construct(string $dtoClass)
    {
        $this->dtoClass = $dtoClass;
        $this->reflection = $this->reflectDto();
    }

    /**
     * Retrieve the reflection of the given DTO class
     *
     * @return ReflectionClass
     * @throws DtoNotFoundException
     */
    protected function reflectDto(): ReflectionClass
    {
        try {
            return new ReflectionClass($this->dtoClass);
        } catch (ReflectionException $e) {
            throw new DtoNotFoundException($this->dtoClass);
        }
    }

    /**
     * Retrieve the mapper instance for the given DTO class
     *
     * @param  string  $dtoClass
     * @return DtoPropertiesMapper
     * @throws DtoNotFoundException
     */
    public static function for(string $dtoClass): DtoPropertiesMapper
    {
        return static::$instances[$dtoClass] = static::$instances[$dtoClass] ?? new static($dtoClass);
    }

    /**
     * Retrieve the mapped property names
     *
     * @return array
     * @throws InvalidDocCommentException
     */
    public function getNames(): array
    {
        $rawProperties = $this->cacheRawProperties();

        return array_keys($rawProperties);
    }

    /**
     * Retrieve and cache the raw properties to map
     *
     * @return array
     * @throws InvalidDocCommentException
     */
    protected function cacheRawProperties(): array
    {
        if (isset($this->rawProperties)) {
            return $this->rawProperties;
        }
        $reflection = $this->reflection;

        if (false === $docComment = $reflection->getDocComment()) {
            throw new InvalidDocCommentException($this->dtoClass);
        }

        $this->rawProperties = [];

        do {
            if ($docComment = $reflection->getDocComment()) {
                if (preg_match_all(static::RE_PROPERTY, $docComment, $matches, PREG_SET_ORDER) !== 0) {
                    foreach ($matches as $match) {
                        [, $rawTypes, $name] = $match;
                        $this->rawProperties[$name] = $rawTypes;
                    }
                }
            }
            $parent = $reflection->getParentClass();
            $reflection = $parent && $parent->name != Dto::class ? $parent : false;
        } while ($reflection);

        return $this->rawProperties;
    }

    /**
     * Retrieve the mapped DTO properties
     *
     * @param  array  $data
     * @param  int  $flags
     * @param  array  $remaped
     * @return array
     * @throws InvalidDocCommentException
     * @throws MissingValueException
     * @throws UnexpectedValueException
     * @throws UnknownDtoPropertyException
     */
    public function map(array $data, int $flags, array $remap = []): array
    {
        $mappedProperties = [];
        $rawProperties = $this->cacheRawProperties();
        $useStatements = $this->cacheUseStatements();
        $modifyMethods = $this->cacheModifyMethods();

        foreach ($rawProperties as $name => $rawTypes) {
            $cachedProperty = $this->mappedProperties[$name] ?? null;

            $types = $cachedProperty ? $cachedProperty->getTypes() : $this->parseTypes($rawTypes, $useStatements);

            if (array_key_exists($name, $remap)) {
                $key = $this->getPropertyKeyFromData($remap[$name], $data);
            } else {
                $key = $this->getPropertyKeyFromData($name, $data);
            }

            $closure = array_key_exists($name, $modifyMethods);

            if (!array_key_exists($key, $data) && !$closure) {
                if ($flags & PARTIAL) {
                    continue;
                }

                throw new MissingValueException($this->dtoClass, $name);
            }

            $value = $data[$key] ?? null ;

            $mappedProperties[$name] = DtoProperty::create($name, $value, $types, $flags, $closure);
            unset($data[$key]);
        }

        $this->checkUnknownProperties($data, $flags);

        return $this->mappedProperties = $mappedProperties;
    }

    /**
     * Retrieve and cache the DTO "use" statements
     *
     * @return array
     */
    protected function cacheUseStatements(): array
    {
        if (isset($this->useStatements)) {
            return $this->useStatements;
        }

        $this->useStatements = [];
        $rawStatements = null;
        $handle = fopen($this->reflection->getFileName(), 'rb');

        do {
            $line = trim(fgets($handle, 120));
            $begin = substr($line, 0, 3);
            $rawStatements .= $begin == 'use' ? $line : null;
        } while ($begin != '/**');

        fclose($handle);

        preg_match_all(static::RE_USE_STATEMENT, $rawStatements, $useMatches, PREG_SET_ORDER);

        foreach ($useMatches as $match) {
            $segments = explode('\\', $match[1]);
            $name = $match[2] ?? end($segments);
            $this->useStatements[$name] = $match[1];
        }

        return $this->useStatements;
    }

    /**
     * Parse the given raw property types
     *
     * @param  string  $rawTypes
     * @param  array  $useStatements
     * @return DtoPropertyTypes
     */
    protected function parseTypes(string $rawTypes, array $useStatements): DtoPropertyTypes
    {
        return array_reduce(explode('|', $rawTypes), function (DtoPropertyTypes $types, $rawType) use ($useStatements) {
            $name = str_replace('[]', '', $rawType, $count);
            $isCollection = $count > 0;

            // fully qualified class name exists
            if (strpos($rawType, '\\') === 0 && class_exists($name)) {
                return $types->addType(new DtoPropertyType(substr($name, 1), $isCollection));
            }

            if (isset($useStatements[$name])) {
                return $types->addType(new DtoPropertyType($useStatements[$name], $isCollection));
            }

            // class in DTO namespace exists
            if (class_exists($class = $this->reflection->getNamespaceName() . '\\' . $name)) {
                return $types->addType(new DtoPropertyType($class, $isCollection));
            }

            return $types->addType(new DtoPropertyType($name, $isCollection));
        }, new DtoPropertyTypes());
    }

    /**
     * Retrieve the key for the given property in the provided data
     *
     * @param  string  $property
     * @param  array  $data
     * @return string
     */
    protected function getPropertyKeyFromData(string $property, array $data): string
    {
        if (array_key_exists($property, $data)) {
            return $property;
        }

        return ArrayConverter::instance()->formatKey($property, true);
    }

    /**
     * Check whether the given data contains unknown properties
     *
     * @param  array  $data
     * @param  int  $flags
     * @return void
     * @throws UnknownDtoPropertyException
     */
    protected function checkUnknownProperties(array $data, int $flags): void
    {
        if ($data && !($flags & IGNORE_UNKNOWN_PROPERTIES)) {
            throw new UnknownDtoPropertyException($this->dtoClass, key($data));
        }
    }

    /**
     * Retrieve and cache the DTO "use" statements
     *
     * @return array
     */
    protected function cacheModifyMethods(): array
    {
        if (isset($this->modifyMethods)) {
            return $this->modifyMethods;
        }
        $this->modifyMethods = [];
        $methods = $this->reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            /** @var \ReflectionMethod $method */
            if(str_ends_with($method->getName(),'Attribute')){
                $this->modifyMethods[substr($method->getName(),0,-9)] = $method;
            }
        }
        return $this->modifyMethods;
    }
}
