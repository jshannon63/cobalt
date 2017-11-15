<?php

declare(strict_types=1);
/**
 * service container class.
 *
 * a PSR-11 derived IoC container that provides dependency injection and reflection
 * based inversion of control. supports dependency injection through a bind()
 * method closure. It also supports container binding access by ArrayAccess.
 * fully auto-wired with dependency caching. Supports binding of existing
 * instances. Provides for use of singleton (shared) instances. Also
 * supports Interface binding with a specified default concrete
 * implementation.
 *
 * Author: Jim Shannon (@jshannon63)
 * https://jimshannon.me
 * Date: 9/19/17
 * License: MIT
 */

namespace Jshannon63\Container;

use Closure;
use Exception;
use ArrayAccess;
use ReflectionClass;
use Psr\Container\ContainerInterface;

class Container implements ContainerInterface, ArrayAccess
{
    // this container.
    protected static $container;

    // mode options are 'cache' and 'shared'. invalid options are ignored.
    protected $mode;

    // array of container bindings.
    protected $bindings = [];

    /**
     * serviceContainer constructor. Set global static container. Register first binding
     * of the container instance itself. Allow the service container to resolve itself.
     * also register a number of base bindings representing the container.
     */
    public function __construct($mode = null)
    {
        $this->mode = $mode;
        static::$container = $this;
        $this->instance('Container', $this);
        $this->instance(self::class, $this);
    }

    /**
     * Bind a class into the container. Binding does not instantiate. That is performed
     * when the object is requested. If an interface and a concrete class are both
     * provided, then we bind the abstract interface to the container. A
     * subsequent call to resolve or make the abstract class will give
     * an instance of $concrete. This allows interface type-hinting
     * throughout your code and easy swap-out of concrete
     * implementations.
     *
     * @param string $abstract
     * @param mixed $concrete
     * @param bool $singleton
     * @throws ContainerException
     */
    public function bind(string $abstract, $concrete = null, bool $singleton = false): void
    {
        // if this binding is being updated and another class is dependent on
        // it, then clear its dependency cache of the upstream binding.
        if (isset($this->bindings[$abstract]['depender'])) {
            foreach ($this->bindings[$abstract]['depender'] as $depender) {
                $this->bindings[$depender]['cached'] = false;
                unset($this->bindings[$depender]['dependencies']);
            }
        }

        // clear the current binding if it exists
        unset($this->bindings[$abstract]);

        // if value for $concrete was not passed then set to $abstract
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // if the concrete class is not a closure, then get the reflection
        // class, cache it in the binding and set the full concrete name
        if (! $concrete instanceof Closure) {
            try {
                $this->bindings[$abstract]['reflect'] = (new ReflectionClass($concrete));
                $concrete = $this->bindings[$abstract]['reflect']->getName();
            } catch (Exception $e) {
                throw new ContainerException($concrete.' does not appear to be a valid class.');
            }
        }

        // if the container was initialized in shared mode, force singletons
        if ($this->mode == 'shared') {
            $singleton = true;
        }

        // initialize the binding array elements
        $this->bindings[$abstract]['instance'] = false;
        $this->bindings[$abstract]['cached'] = false;
        $this->bindings[$abstract]['concrete'] = $concrete;
        $this->bindings[$abstract]['singleton'] = $singleton;
    }

    /**
     * resolve a binding by first checking to make sure the binding exists and
     * then calling make() to provide a new/existing instance out of the
     * container. resolve should only be called if you know the binding
     * exists.
     *
     * @param string $id
     * @return object
     * @throws NotFoundException
     */
    public function resolve(string $id)
    {
        // make sure the binding exists
        if (! $this->has($id)) {
            throw new NotFoundException('Binding '.$id.' not found.');
        }

        // if it is a stored singleton instance, just return it
        if ($this->bindings[$id]['instance']) {
            return $this->bindings[$id]['instance'];
        }

        // it's not that simple, so let's run make to get more details
        return $this->make($id);
    }

    /**
     * Make or return an instance of the binding. Fully support dependency injection and
     * reflection on dependencies. If binding does not exist, then create it first.
     * This allows calling of make() directly without first binding() in case one
     * needs a quick instance of a class.
     *
     * @param string $id
     * @return object
     * @throws ContainerException
     */
    public function make(string $id)
    {
        $dependencies = [];

        // let's see if the binding is already there...
        // just in case make() was called directly.
        if (! $this->has($id)) {
            $this->bind($id);
        }

        // if it is a stored singleton instance, return it
        // just in case make() was called directly.
        if ($this->bindings[$id]['instance']) {
            return $this->bindings[$id]['instance'];
        }

        // if we're in cached mode and the binding has cached dependencies...
        // let's go ahead and instantiate a new copy and return it.
        if ($this->bindings[$id]['cached'] && $this->mode == 'cached') {
            return $this->bindings[$id]['reflect']->newInstanceArgs($this->bindings[$id]['dependencies']);
        }

        // if the concrete implementation is a closure then let's run it and
        // return it. if it is a singleton then store it first.
        if ($this->bindings[$id]['concrete'] instanceof Closure) {
            if ($this->bindings[$id]['singleton']) {
                return $this->bindings[$id]['instance'] = $this->bindings[$id]['concrete']();
            }

            return $this->bindings[$id]['concrete']();
        }

        // if we have gotten this far, then we are going to dive into the
        // class with our previously cached reflection object and
        // see about its dependencies.
        $class = $this->bindings[$id]['reflect'];

        // if it's not instantiable, then we can do nothing... throw exception.
        if (! $class->isInstantiable()) {
            throw new ContainerException($this->bindings[$id]['concrete'].' can not be instantiated.');
        }

        // let's get the class constructor and see what we have.
        $constructor = $class->getConstructor();

        // if there is no constructor, then just instantiate it and return it.
        // if it is a singleton then store the instance first.
        if (! $constructor) {
            if ($this->bindings[$id]['singleton']) {
                return $this->bindings[$id]['instance'] = new $this->bindings[$id]['concrete'];
            }

            return new $this->bindings[$id]['concrete'];
        }

        // get all the constructor parameters.
        $parameters = $constructor->getParameters();

        // then loop through the parameters to see what is in the constructor.
        foreach ($parameters as $parameter) {

            // extract the class name to a dependency.
            $dependency = $parameter->getClass();

            // if it is null, then it is not a class, so we need to see if we've
            // been given a default value. if so, store the value for now. if
            // not... we can do nothing... throw an exception.
            if (is_null($dependency)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new ContainerException('Non class dependency ('.$parameter->name.') requires default value.');
                }
            }

            // it's a class dependency... we will dive in recursively and make() the
            // dependency we need. we will also save the calling class name to the
            // dependency so if the dependency is rebound later, it can force
            // the upstream bindings to refresh their dependency cache.
            else {
                $dependencies[] = $this->make($dependency->name); // recursive call
                $this->bindings[$dependency->name]['depender'][] = $id;
            }
        }

        // we've reached the bottom on the dependency chain for this binding
        // and it has all its' dependencies. if it is a singleton, let's
        // store the instance and return it.
        if ($this->bindings[$id]['singleton']) {
            return $this->bindings[$id]['instance'] = $class->newInstanceArgs($dependencies);
        }

        // otherwise, we'll set the cache flag to true and cache the dependencies
        // for later use.
        $this->bindings[$id]['cached'] = true;
        $this->bindings[$id]['dependencies'] = $dependencies;

        // return a newly instantiated class with all its' resolved dependencies.
        return $class->newInstanceArgs($dependencies);
    }

    /**
     * Register an existing instance into the container.
     * Instance will be treated as a singleton.
     *
     * @param string $abstract
     * @param object $instance
     * @return object
     * @throws ContainerException
     */
    public function instance(string $abstract, $instance)
    {
        // extract the full class name and store as key.
        // Throw exception if invalid.
        try {
            $concrete = (new ReflectionClass($instance))->getName();
        } catch (Exception $e) {
            throw new ContainerException('The instance passed with '.$abstract.' can not be used.');
        }

        // bind the key and instance to the container and mark as singleton.
        $this->bind($abstract, $concrete, true);

        // return the instance.
        return $this->bindings[$abstract]['instance'] = $instance;
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
     * @param string $id
     * @return object
     */
    public function get($id)
    {
        return $this->resolve($id);
    }

    /**
     * Interface method for ContainerInterface.
     * Check if binding with $id exists.
     *
     * @param string $id
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
     * @param string $offset
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
     * @param string $offset
     * @return object
     * @throws NotFoundException
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
     * @param string $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        $this->bind($offset, $value);
    }

    /**
     * Interface method for ArrayAccess.
     * Remove the binding at $offset.
     *
     * @param string $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->bindings[$offset]);
    }
}
