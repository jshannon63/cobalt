<?php

declare(strict_types=1);

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
 * Author: Jim Shannon (@jshannon63)
 * https://jimshannon.me
 * Date: 9/19/17
 * License: MIT
 */

namespace Jshannon63\Cobalt;

use Closure;
use Exception;
use ArrayAccess;
use ReflectionClass;
use Psr\Container\ContainerInterface;

class Container implements ContainerInterface, ArrayAccess
{
    // this.
    protected static $container;

    // The only mode option is 'shared'. invalid options are ignored.
    protected $mode;

    // Array of container bindings.
    protected $bindings = [];

    /**
     * Service Container constructor. Set global static container. Register first
     * binding of the container instance itself. Allow the service container
     * to resolve itself. also register a base binding representing the
     * container.
     */
    public function __construct($mode = null)
    {
        $this->mode = $mode;
        static::$container = $this;
        $this->instance(self::class, $this);
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
     * @param  string  $abstract
     * @param  mixed  $concrete
     * @param  bool  $singleton
     * @return  void
     * @throws ContainerException
     */
    public function bind(string $abstract, $concrete = null, bool $singleton = false): void
    {
        // If this binding is being updated and other classes are dependent on
        // it, then clear the dependency caches of the upstream bindings.
        $this->clearDependerCaches($abstract);

        // Start fresh... remove the current binding if it already exists.
        unset($this->bindings[$abstract]);

        // Set timestamp and pointer by reference for easy reading
        $this->bindings[$abstract]['timestamp'] = microtime(true);
        $binding = &$this->bindings[$abstract];

        // Initialize the binding array elements.
        // Set $concrete to $abstract if not provided.
        // If the container was initialized in shared mode, we must force singletons.
        $binding['cached'] = false;
        $binding['concrete'] = (is_null($concrete)) ? $abstract : $concrete;
        $binding['singleton'] = ($this->mode == 'shared') ? true : $singleton;

        // If the concrete class is not a closure, then check if concrete is an
        // object. If so, set instance and singleton mode, then use reflection
        // on the class, cache it in the binding and set the full concrete name.
        if (!$binding['concrete'] instanceof Closure) {
            if (is_object($binding['concrete'])) {
                $binding['instance'] = $binding['concrete'];
                $binding['singleton'] = true;
            } else {
                try {
                    $binding['reflect'] = (new ReflectionClass($binding['concrete']));
                    $binding['concrete'] = $binding['reflect']->getName();
                    $this->getDependencies($binding);
                } catch (Exception $e){
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
     * @param  string  $id
     * @return object
     * @throws NotFoundException
     */
    public function resolve(string $id)
    {
        // make sure the binding exists
        if (!isset($this->bindings[$id])) {
            throw new NotFoundException('Binding '.$id.' not found.');
        }

        // if it is a stored instance, just return it
        if (isset($this->bindings[$id]['instance'])) {
            return $this->bindings[$id]['instance'];
        }

        // it's not that simple, so let's run make to get more details
        return $this->make($id);
    }

    /**
     * Make or return an instance of the binding. If the binding does not exist
     * then create it first. This allows calling of make() directly without
     * first binding() in case one needs a quick instance of a class.
     *
     * @param  string  $id
     * @return object
     */
    public function make(string $id)
    {
        // Check if the binding already exists. Just in case make() was called
        // directly.
        if (!isset($this->bindings[$id])) {
            $this->bind($id);
        }

        // create reference for ease of reading
        $binding = &$this->bindings[$id];

        // If it is a stored instance, return it.
        if (isset($binding['instance'])) {
            return $binding['instance'];
        }

        // If the concrete implementation is a closure then let's run it and
        // return it. If it is a singleton then store it first.
        if ($binding['concrete'] instanceof Closure) {
            if ($binding['singleton']) {
                $binding['instance'] = $binding['concrete']();
                return $binding['instance'];
            }
            return $binding['concrete']();
        }

        // If this binding's cache was busted then reload the dependencies.
        if (!$binding['cached'] && isset($binding['reflect'])) {
            $this->getDependencies($binding);
        }

        // If there are no constructor dependencies then build it and return it. If
        // it is a singleton then we can store the instance for later use.
        if ($binding['dependencies'] == []) {
            if ($binding['singleton']) {
                unset($binding['dependencies']);
                unset($binding['reflect']);
                $binding['cached'] = false;
                $binding['instance'] = new $binding['concrete'];
                return $binding['instance'];
            }
            return new $binding['concrete'];
        }

        $dependencies = [];

        // Now we can recursively dive through all the dependencies... for as deep
        // as they run in the graph. We will make new every dependency based on
        // the dependency information saved earlier.
        foreach ($binding['dependencies'] as $type => $dependency) {
            if ($dependency['type'] == 'class') {
                $dependencies[] = $this->make($dependency['value']);
                $this->bindings[$dependency['value']]['depender'][] = $id;
                array_unique($this->bindings[$dependency['value']]['depender']);
            } elseif ($dependency['type'] == 'default') {
                $dependencies[] = $dependency['value'];
            }
        }
        // We've reached the bottom on the dependency chain for this binding and
        // all its dependencies are hydrated. If it is a singleton, let's
        // store the instance and return it.
        if ($binding['singleton']) {
            $binding['instance'] = $binding['reflect']->newInstanceArgs($dependencies);
            unset($binding['dependencies']);
            unset($binding['reflect']);
            $binding['cached'] = false;
            return $binding['instance'];
        }

        // Otherwise we return a newly instantiated class with all its' dependencies
        // resolved.
        return $binding['reflect']->newInstanceArgs($dependencies);
    }

    /**
     * Get all dependency information for the binding. Do not hydrate the
     * the dependencies, but store the data to the binding registry for
     * later use.
     *
     * @param  array  $binding
     * @return void
     * @throws ContainerException
     */
    private function getDependencies(&$binding): void
    {
        // Let's retrieve the ReflectionClass object previously generated
        // during binding. Store it to a $class variable for ease of
        // reading.
        $class = $binding['reflect'];

        // If it's not instantiable, then we can do nothing... throw exception.
        if (!$class->isInstantiable()) {
            throw new ContainerException($binding['concrete'].' can not be instantiated.');
        }

        // Get the class constructor and see what we have.
        $constructor = $class->getConstructor();

        // If there is no constructor, return an empty array of dependencies
        if (!$constructor) {
            $binding['cached'] = true;
            $binding['dependencies'] = [];
            return;
        }

        // Otherwise, get all the constructors' parameters.
        $parameters = $constructor->getParameters();

        // this will hold our dependencies information
        $dependencies = [];

        // Then loop through the parameters to see what is in the constructor.
        foreach ($parameters as $key => $parameter) {
            // Extract the class name to a dependency.
            $dependency = $parameter->getClass();

            // If it is null, then it is not a class, so we need to see if we've
            // been given a default value. If so, store the value for now. if
            // not, or if we have been passed a variadic, we can do nothing... throw an exception.
            if (is_null($dependency)) {
                if ($parameter->isVariadic()) {
                    throw new ContainerException('Variadic constructor argument ('.$parameter->name.') not supported. Suggest Closure Binding.');
                }
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

        // Getting to this point means the binding is fully defined. So
        // we will cache the values in the registry and mark it as
        // cached.
        $binding['cached'] = true;
        $binding['dependencies'] = $dependencies;
    }

    /**
     * Register an existing instance into the container.
     * Instance will be treated as a singleton.
     *
     * @param  string  $abstract
     * @param  object  $instance
     * @return object
     */
    public function instance(string $abstract, $instance)
    {
        // bind the key and instance to the container and mark as singleton.
        $this->bind($abstract, $instance, true);

        // then return the instance for posterity.
        return $this->bindings[$abstract]['instance'] = $instance;
    }

    /**
     * Clear caches for all dependers of provided
     * binding.
     *
     * @param  string  $id
     */
    private function clearDependerCaches($id): void
    {
        if(!isset($this->bindings[$id]['depender'])){
            return;
        }
        foreach ($this->bindings[$id]['depender'] as $depender) {
            $this->bindings[$depender]['cached'] = false;
            unset($this->bindings[$depender]['dependencies']);
        }
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
     * Return and array containing a the requested binding.
     *
     * @param  string  $id
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
     * @param  string  $id
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
     * @param  string  $id
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
     * @param  string  $offset
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
     * @param  string  $offset
     * @return object
     * @throws NotFoundException
     */
    public function offsetGet($offset)
    {
        // if the binding does not exist then throw exception.
        if (!$this->has($offset)) {
            throw new NotFoundException('Binding '.$offset.' not found.');
        }

        return $this->resolve($offset);
    }

    /**
     * Interface method for ArrayAccess.
     * Set binding at $offset (abstract) with $value (concrete).
     *
     * @param  string  $offset
     * @param  mixed  $value
     */
    public function offsetSet($offset, $value): void
    {
        $this->bind($offset, $value);
    }

    /**
     * Interface method for ArrayAccess.
     * Remove the binding at $offset.
     *
     * @param  string  $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->bindings[$offset]);
    }
}
