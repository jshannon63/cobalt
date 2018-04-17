<?php

declare(strict_types=1);

namespace Jshannon63\Cobalt;

use Closure;
use Exception;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * Cobalt Service Container.
 *
 * A PSR-11 derived IoC container that provides dependency injection. Supports
 * dependency injection through a bind() method closure. It also supports
 * container binding access by ArrayAccess. Fully auto-wired with
 * dependency caching. Supports binding of existing instances.
 * Provides for use of singleton (shared) instances. Also
 * supports Interface binding with a specified default
 * concrete implementation which encourages program
 * to interface methodology and reusable object
 * oriented design.
 *
 * @author Jim Shannon (jim@hltky.com)
 * @link https://jimshannon.me
 * Date: 9/19/17
 * License: MIT
 */
class Container implements CobaltContainerInterface
{
    /**
     * Global container instance.
     * @var Container
     */
    protected static $container;

    /**
     * Binding ID.
     * @var int
     */
    protected $bindingId = 0;

    /**
     * For now, the only mode option is 'shared'. invalid options are ignored.
     * @var null|string
     */
    protected $mode;

    /**
     * Array registry of container bindings.
     *
     * @var array
     */
    protected $bindings = [];

    /**
     * Array of cached prototype closures.
     *
     * @var array
     */
    protected $cache = [];

    /**
     * Array of aliased cached bindings keyed by alias.
     *
     * @var array
     */
    protected $aliases = [];

    /**
     * Container constructor.
     * @param null $mode
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function __construct($mode = null)
    {
        $this->mode = $mode;
        static::$container = $this;
        $this->bind(self::class, $this);
        $this->alias(ContainerInterface::class, self::class);
        $this->alias(CobaltContainerInterface::class, self::class);
    }

    /**
     * Bind a class into the container. Binding does not instantiate. That is
     * performed when the object is requested. If an interface and a
     * concrete class are both provided, then we bind the abstract
     * interface to the container. A subsequent call to resolve
     * or make the abstract class will give an instance of
     * $concrete. This allows interface type-hinting
     * throughout your code and easy swap-out of
     * concrete implementations.
     *
     * @param  string $abstract
     * @param  mixed $concrete
     * @param  bool $singleton
     * @return  void
     * @throws ContainerException
     */
    public function bind($abstract, $concrete = null, $singleton = false): void
    {
        // Start fresh... if this is a rebound, remove the binding.
        $this->destroyBinding($abstract);

        // Initialize binding and pointer by reference for easy reading
        $this->initializeBinding($abstract);
        $binding = &$this->bindings[$abstract];

        // Initialize the binding array elements. Set $concrete to $abstract if not
        // provided. If the container was initialized in shared mode, then force
        // singletons.
        $binding['concrete'] = (is_null($concrete)) ? $abstract : $concrete;
        $binding['singleton'] = ($this->mode == 'shared') ? true : $singleton;

        // If the concrete class is not a closure, then check if concrete is an object.
        // If so, set instance and singleton mode, then use reflection on the class,
        // cache it in the binding and set the full concrete name.
        if (! $binding['concrete'] instanceof Closure) {
            if (is_object($binding['concrete'])) {
                $binding['singleton'] = true;
                $this->prepareBindingClosure($abstract, $binding['concrete']);
            } else {
                try {
                    $binding['reflector'] = (new ReflectionClass($binding['concrete']));
                    $binding['concrete'] = $binding['reflector']->getName();
                    $this->processDependencies($binding);
                } catch (Exception $e) {
                    throw new ContainerException($binding['concrete'].' does not appear to be a valid class.');
                }
            }
        }
    }

    /**
     * Resolve binding by first checking to make sure the binding exists and
     * then calling make() to provide an instance out of the container.
     * Resolve should only be called if you know the binding exists.
     *
     * @param  string $id
     * @return object
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function resolve($id)
    {
        // make sure the binding exists
        if (! isset($this->bindings[$id])) {
            throw new NotFoundException('Binding '.$id.' not found.');
        }

        // If it is a cached binding, instantiate and return it.
        if (isset($this->cache[$id])) {
            return $this->cache[$id]();
        }

        // it's not that simple, so let's run create. We get the
        // closure and execute it we'll get our instance.
        return $this->create($id)();
    }

    /**
     * Bind and then resolve to return an instantiated binding.
     *
     * @param  $id
     * @param  $args
     * @return object
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function make($id, ...$args)
    {
        $this->bind($id, ...$args);

        return $this->resolve($id);
    }

    /**
     * Make or return an instance of the binding. If the binding does not exist
     * then create it first. This allows calling of make() directly without
     * first binding() in case one needs a quick instance of a class.
     *
     * @param  string $id
     * @return object
     * @throws ContainerException
     */
    private function create($id)
    {
        /* @var null|array */
        $dependencies = [];

        // Check if the binding already exists. Forces a bind
        // whenever create is called internally.
        if (! isset($this->bindings[$id])) {
            $this->bind($id);
        }

        // create reference for ease of reading
        $binding = &$this->bindings[$id];

        // If it is a cached binding, instantiate and return it.
        if (isset($this->cache[$id])) {
            return $this->cache[$id]();
        }

        // If the concrete implementation is a closure or class without a
        // constructor, then we have all the information we need so we
        // can build the blueprint and return it.
        if ($binding['concrete'] instanceof Closure || $binding['dependencies'] == []) {
            return $this->prepareBindingClosure($id, $binding['concrete']);
        }

        // Now we can recursively dive through all the dependencies... for as deep
        // as they run in the graph. We will make new every dependency based on
        // the dependency information saved earlier.
        foreach ($binding['dependencies'] as $type => $dependency) {
            if ($dependency['type'] == 'class') {
                $dependencies[] = $this->create($dependency['value']);
            } elseif ($dependency['type'] == 'default') {
                $dependencies[] = $dependency['value'];
            }
        }

        // We've reached the bottom on the dependency chain for this binding and
        // all it's dependencies are hydrated. We return a closure blueprint
        // for the class with all it's dependencies resolved.
        return $this->prepareBindingClosure($id, $binding['reflector'], $dependencies);
    }

    /**
     * Get all dependency information for the binding. Do not hydrate the
     * the dependencies, but store the data to the binding registry for
     * later use.
     *
     * @param  array $binding
     * @return void
     * @throws ContainerException
     */
    private function processDependencies(&$binding): void
    {
        // Let's use the ReflectionClass object previously generated during binding.
        // If it's not instantiable, then we can do nothing... throw exception.
        if (! $binding['reflector']->isInstantiable()) {
            throw new ContainerException($binding['concrete'].' can not be instantiated.');
        }

        // Get the class constructor and see what we have. If there is no constructor,
        // then return an empty array of dependencies.
        if (! $binding['reflector']->getConstructor()) {
            $binding['dependencies'] = [];

            return;
        }

        // Otherwise get the constructor parameters and resolve them.
        $binding['dependencies'] = $this->resolveParameters($binding['reflector']->getConstructor()->getParameters());
    }

    /**
     * Process the constructor parameter list that was obtained from
     * the ReflectionClass and return the resolved dependencies.
     *
     * @param  array $parameters
     * @return array
     * @throws ContainerException
     */
    private function resolveParameters($parameters): array
    {
        // this will hold our dependencies information
        $dependencies = [];

        // Then loop through the parameters to see what is in the constructor.
        foreach ($parameters as $key => $parameter) {
            // Extract the class name to a dependency.
            $dependency = $parameter->getClass();

            // If it is null, then it is not a class, so we need to see if we've been
            // given a default value. If so, store the value for now.
            if (is_null($dependency)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[$key]['type'] = 'default';
                    $dependencies[$key]['value'] = $parameter->getDefaultValue();
                } else {
                    throw new ContainerException('Non class dependency ('.$parameter->name.') requires default value.');
                }
            }
            // Otherwise, it's a class dependency. We will store the class name
            // so that we can create it later.
            else {
                $dependencies[$key]['type'] = 'class';
                $dependencies[$key]['value'] = $dependency->name;
            }
        }

        return $dependencies;
    }

    /**
     * Make a binding closure method to store in the cache.
     *
     * @param  string $id
     * @param  mixed $blueprint
     * @param  array $dependencies
     * @return Closure
     */
    private function prepareBindingClosure($id, $blueprint, $dependencies = null): Closure
    {
        if ($this->bindings[$id]['singleton']) {
            return $this->prepareSingletonBindingClosure($id, $blueprint, $dependencies);
        }

        return $this->preparePrototypeBindingClosure($id, $blueprint, $dependencies);
    }

    /**
     * Make a prototype binding closure method to store in the cache.
     *
     * @param  string $id
     * @param  mixed $blueprint
     * @param  array $dependencies
     * @return Closure
     */
    private function preparePrototypeBindingClosure($id, $blueprint, $dependencies): Closure
    {
        if ($blueprint instanceof ReflectionClass) {
            return $this->cache[$id] = function () use ($blueprint, $dependencies) {
                foreach ($dependencies as $key => $dependency) {
                    if ($dependency instanceof Closure) {
                        $dependencies[$key] = $dependency();
                    }
                }

                return $blueprint->newInstanceArgs($dependencies);
            };
        }
        if ($blueprint instanceof Closure) {
            return $this->cache[$id] = $blueprint;
        }

        return $this->cache[$id] = function () use ($blueprint) {
            return new $blueprint;
        };
    }

    /**
     * Make a singleton binding closure method to store in the cache.
     *
     * @param  string $id
     * @param  mixed $blueprint
     * @param  array $dependencies
     * @return Closure
     */
    private function prepareSingletonBindingClosure($id, $blueprint, $dependencies): Closure
    {
        $binding = $this->bindings[$id];
        $instance = $blueprint;

        if ($blueprint instanceof ReflectionClass) {
            foreach ($dependencies as $key => $dependency) {
                if ($dependency instanceof Closure) {
                    $dependencies[$key] = $dependency();
                }
            }
            $instance = $blueprint->newInstanceArgs($dependencies);
        }
        if ($blueprint instanceof Closure) {
            $instance = $blueprint();
        }
        // If blueprint is supplied as a class name.
        if (is_string($blueprint)) {
            $instance = new $blueprint;
        }
        // Singletons won't need these any longer so free up the memory.
        unset($binding['dependencies'], $binding['reflector']);

        return $this->cache[$id] = function () use ($instance) {
            return $instance;
        };
    }

    /**
     * Deprecated: Hold for backward compatibility.
     *
     * @param  string $abstract
     * @param  object $instance
     * @return object
     * @throws ContainerException
     */
    public function instance($abstract, $instance)
    {
        // bind the key and instance to the container.
        $this->bind($abstract, $instance);

        // then return the instance for posterity.
        return $this->cache[$abstract]();
    }

    /**
     * Create an alias to an existing cached binding.
     *
     * @param  string $alias
     * @param  string $binding
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function alias($alias, $binding)
    {
        if ($this->resolve($binding)) {
            $this->bindings[$alias] = $this->bindings[$binding];
            $this->cache[$alias] = $this->cache[$binding];
            $this->aliases[$alias] = $binding;
        }
    }

    /**
     * Create empty binding in the registry and set ID.
     *
     * @param  string $id
     */
    private function initializeBinding($id)
    {
        $this->bindings[$id]['ID'] = $this->bindingId++;
    }

    /**
     * Remove all traces of the specified binding.
     *
     * @param  string $id
     */
    private function destroyBinding($id)
    {
        unset($this->cache[$id], $this->bindings[$id], $this->aliases[$id]);
    }

    /**
     * Get the global instance of the container.
     *
     * @return Container
     */
    public static function getContainer(): self
    {
        return static::$container;
    }

    /**
     * Return and array containing a the requested binding information.
     *
     * @param  string $id
     * @return array
     */
    public function getBinding($id): array
    {
        return $this->bindings[$id];
    }

    /**
     * Return and array containing all the bindings.
     * Sometimes you are just curious.
     *
     * @return array
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /********************************************
     * ContainerInterface Methods
     ********************************************/

    /**
     * Interface method for ContainerInterface.
     * Get the binding with the given $id.
     *
     * @param  string $id
     * @return object
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function get($id)
    {
        return $this->resolve($id);
    }

    /**
     * Interface method for ContainerInterface.
     * Check if binding with $id exists.
     *
     * @param  string $id
     * @return bool
     */
    public function has($id): bool
    {
        return isset($this->bindings[$id]);
    }

    /********************************************
     * ArrayAccess Methods
     ********************************************/

    /**
     * Interface method for ArrayAccess.
     * Checks if binding at $offset exists.
     *
     * @param  string $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Interface method for ArrayAccess.
     * Returns instance identified by $offset binding.
     *
     * @param  string $offset
     * @return object
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function offsetGet($offset)
    {
        // if the binding does not exist then throw exception.
        if (! $this->has($offset)) {
            throw new NotFoundException('Binding '.$offset.' not found.');
        }

        return $this->resolve($offset);
    }

    /**
     * Interface method for ArrayAccess.
     * Set binding at $offset (abstract) with $value (concrete).
     *
     * @param  string $offset
     * @param  mixed $value
     * @throws ContainerException
     */
    public function offsetSet($offset, $value): void
    {
        $this->bind($offset, $value);
    }

    /**
     * Interface method for ArrayAccess.
     * Remove the binding at $offset.
     *
     * @param  string $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->bindings[$offset]);
    }
}
