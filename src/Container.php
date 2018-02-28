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

    // Mode options are 'cache' and 'shared'. invalid options are ignored.
    protected $mode;

    // Array of container bindings.
    protected $bindings = [];

    /**
     * DerviceContainer constructor. Set global static container. Register first
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
        // If this binding is being updated and other classes dependent on
        // it, then clear the dependency caches of the upstream bindings.
        if (isset($this->bindings[$abstract]['depender'])) {
            foreach ($this->bindings[$abstract]['depender'] as $depender) {
                $this->bindings[$depender]['cached'] = false;
                unset($this->bindings[$depender]['dependencies']);
            }
        }

        // Start fresh... remove the current binding if it already exists.
        unset($this->bindings[$abstract]);

        // If a $concrete class was not passed then set to $abstract.
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // set instance to false until we check concrete to make sure it
        // is not a closure.
        $instance = false;

        // If the concrete class is not a closure, then check if concrete is an
        // object. If so, set instance and singleton mode, then use reflection
        // on the class, cache it in the binding and set the full concrete name.
        if (!$concrete instanceof Closure) {
            try {
                if (is_object($concrete)) {
                    $instance = $concrete;
                    $singleton = true;
                } else {
                    $this->bindings[$abstract]['reflect'] = (new ReflectionClass($concrete));
                    $concrete = $this->bindings[$abstract]['reflect']->getName();
                }
            } catch (Exception $e) {
                throw new ContainerException($concrete.' does not appear to be a valid class.');
            }
        }

        // If the container was initialized in shared mode, we must force singletons.
        if ($this->mode == 'shared') {
            $singleton = true;
        }

        // Initialize the binding array elements.
        $this->bindings[$abstract]['instance'] = $instance;
        $this->bindings[$abstract]['cached'] = false;
        $this->bindings[$abstract]['concrete'] = $concrete;
        $this->bindings[$abstract]['singleton'] = $singleton;

        // If reflection was run on the binding and we are in cached mode, them go ahead
        // and pre-load the dependencies.
        if(isset($this->bindings[$abstract]['reflect']) && $this->mode == 'cached'){
            $this->getDependencies($abstract);
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
        if (!$this->has($id)) {
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
     * Make or return an instance of the binding. If the binding does not exist
     * then create it first. This allows calling of make() directly without
     * first binding() in case one needs a quick instance of a class.
     *
     * @param  string  $id
     * @return object
     */
    public function make(string $id)
    {
        $dependencies = [];

       // Check if the binding already exists. Just in case make() was called
        // directly.
        if (!$this->has($id)) {
            $this->bind($id);
        }

        // If it is a stored singleton instance, return it. Just in case make()
        // was called directly.
        if ($this->bindings[$id]['instance']) {
            return $this->bindings[$id]['instance'];
        }

        // If the concrete implementation is a closure then let's run it and
        // return it. If it is a singleton then store it first.
        if ($this->bindings[$id]['concrete'] instanceof Closure) {
            if ($this->bindings[$id]['singleton']) {
                return $this->bindings[$id]['instance'] = $this->bindings[$id]['concrete']();
            }
            return $this->bindings[$id]['concrete']();
        }

        // If we are not in cached mode, then we must discover the dependencies
        // each time we make the binding.
        if($this->mode != 'cached'){
            $this->getDependencies($id);
        }

        // If there are no contructor dependencies then build it and return it. If
        // it is a singleton then we can store the instance for later use.
        if ($this->bindings[$id]['dependencies'] == []) {
            if ($this->bindings[$id]['singleton']) {
                return $this->bindings[$id]['instance'] = new $this->bindings[$id]['concrete'];
            }
            return new $this->bindings[$id]['concrete'];
        }

        // Now we can recursively dive through all the dependencies... for as deep
        // as they run in the graph. We will make new every dependency based on
        // the dependency informaiton saved earlier.
        foreach($this->bindings[$id]['dependencies'] as $type => $dependency){
            // die(var_dump($dependency));
            if($dependency['type'] == 'class'){
                $dependencies[] = $this->make($dependency['value']);
                $this->bindings[$dependency['value']]['depender'][] = $id;
            }
            elseif($dependency['type'] == 'default'){
                $dependencies[] = $dependency['value'];
            }
        }
        // We've reached the bottom on the dependency chain for this binding and
        // all its' dependencies are hydrated. If it is a singleton, let's 
        // store the instance and return it.
        if ($this->bindings[$id]['singleton']) {
            return $this->bindings[$id]['instance'] = $this->bindings[$id]['reflect']->newInstanceArgs($dependencies);
        }            

        // Otherwise we return a newly instantiated class with all its' dependencies
        // resolved.
        return $this->bindings[$id]['reflect']->newInstanceArgs($dependencies);
        
    }

    /**
     * Get all dependency information for the binding. Do not hydrate the 
     * the dependencies, but store the data to the binding registry for
     * later use.
     * 
     * @param  string  $id
     * @return void
     * @throws ContainerException
     */
    private function getDependencies($id): void
    {
        // this will hold our dependencies information
        $dependencies = [];

        // Let's retrieve the ReflectionClass object previously generated
        // during binding. Store it to a $class variable for ease of 
        // reading.
        $class = $this->bindings[$id]['reflect'];

        // If it's not instantiable, then we can do nothing... throw exception.
        if (!$class->isInstantiable()) {
            throw new ContainerException($this->bindings[$id]['concrete'].' can not be instantiated.');
        }

        // Get the class constructor and see what we have.
        $constructor = $class->getConstructor();

        // If there is no constructor, return an emoty array of dependencies
        if (!$constructor) {
            $this->bindings[$id]['cached'] = true;
            $this->bindings[$id]['dependencies'] = [];
            return;
        }

        // Otherwise, get all the constructors' parameters.
        $parameters = $constructor->getParameters();

        // Then loop through the parameters to see what is in the constructor.
        foreach ($parameters as $key => $parameter) {
            // Extract the class name to a dependency.
            $dependency = $parameter->getClass();

            // If it is null, then it is not a class, so we need to see if we've
            // been given a default value. If so, store the value for now. if
            // not, or if we have been passed a variadic, we can do nothing... throw an exception.
            if (is_null($dependency)) {
                if($parameter->isVariadic()){
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
        $this->bindings[$id]['cached'] = true;
        $this->bindings[$id]['dependencies'] = $dependencies;
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
        $this->bind($abstract, get_class($instance), true);

        // then return the instance for posterity.
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
