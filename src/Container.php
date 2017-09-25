<?php
/**
 * Service Container Class for Training Purposes.
 * A PSR-11 derived IoC container that provides dependency injection (DI) and full recursive
 * reflection of class dependencies. It also supports container binding access by ArrayAccess.
 * Allows for auto-binding of dependent classes. Supports binding of existing instances.
 * Provides for use of singleton (shared) instances. Also supports Interface binding with a
 * specified default concrete implementation.
 *
 * Author: Jim Shannon (@jshannon63)
 * Date: 9/19/17
 * License: MIT
 */

namespace Jshannon63\Container;

/*
 * PSR-11 Container Interface & Exception Handlers.
 */

use Closure;
use Exception;
use ArrayAccess;
use ReflectionClass;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

class Container implements ArrayAccess, ContainerInterface, ContainerExceptionInterface, NotFoundExceptionInterface
{
    protected static $container;
    protected $bindings = [];

    /**
     * ServiceContainer constructor. Set global static container. Register first binding of
     * the container instance itself. Allow the service container to resolve itself.
     * Initialize the Facade engine and give it access to the container.
     */
    public function __construct()
    {
        static::$container = $this;
        $this->instance('Container', $this);
    }

    /**
     * Bind a class into the container. Binding does not instantiate. That is performed
     * when the object is requested. If an interface and a concrete class are both
     * provided, then we bind the abstract interface to the container. A subsequent call
     * to resolve or make the abstract class will give an instance of $concrete. This
     * allows interface type-hinting throughout your code and easy swap-out of concrete
     * implementations.
     *
     * @param string $abstract
     * @param mixed $concrete
     * @param bool $singleton
     */
    public function bind($abstract, $concrete = null, $singleton = false)
    {
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        if (! $concrete instanceof Closure) {
            try {
                $concrete = (new ReflectionClass($concrete))->getName();
            } catch (Exception $e) {
                throw $this->ContainerException('Class '.$concrete.' does not exist');
            }
        }

        unset($this->bindings[$abstract]);

        $this->bindings[$abstract] = compact('concrete', 'singleton');
    }

    /**
     * Resolve a binding by first checking to make sure the binding exists and
     * then calling make() to provide a new/existing instance out of the container.
     *
     * @param string $id
     * @return mixed
     */
    public function resolve($id)
    {
        if (! $this->has($id)) {
            throw $this->NotFoundException($id);
        }

        return $this->make($id);
    }

    /**
     * Make or return an instance of the binding. Fully support dependency injection and
     * reflection on dependencies. If binding does not exist, then create it first. This
     * allows calling of make() directly without first binding() in case one needs a quick
     * instance of a class.
     *
     * @param string $id
     * @return object
     */
    public function make($id)
    {
        $dependencies = [];

        if (! $this->has($id)) {
            $this->bind($id);
        }

        if (array_key_exists('instance', $this->bindings[$id])) {
            return $this->bindings[$id]['instance'];
        }

        if ($this->bindings[$id]['concrete'] instanceof Closure) {
            if ($this->bindings[$id]['singleton']) {
                return $this->bindings[$id]['instance'] = $this->bindings[$id]['concrete']();
            }

            return $this->bindings[$id]['concrete']();
        }

        $class = new ReflectionClass($this->bindings[$id]['concrete']);

        if (! $class->isInstantiable()) {
            throw $this->ContainerException($this->bindings[$id]['concrete'].' can not be instantiated.');
        }

        $constructor = $class->getConstructor();

        if (! $constructor) {
            if ($this->bindings[$id]['singleton']) {
                return $this->bindings[$id]['instance'] = new $this->bindings[$id]['concrete'];
            }

            return new $this->bindings[$id]['concrete'];
        }

        $parameters = $constructor->getParameters();

        foreach ($parameters as $parameter) {
            $dependency = $parameter->getClass();
            if (is_null($dependency)) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw $this->ContainerException('Non class dependency ('.$parameter->name.') requires default value.');
                }
            } else {
                if (! $this->has($dependency->name)) {
                    $this->bind($dependency->name);
                }
                $dependencies[] = $this->make($dependency->name);   // recursive call
            }
        }

        if ($this->bindings[$id]['singleton']) {
            return $this->bindings[$id]['instance'] = $class->newInstanceArgs($dependencies);
        }

        return $class->newInstanceArgs($dependencies);
    }

    /**
     * Register an existing instance into the container.
     * Instance will be treated as a singleton.
     *
     * @param string $abstract
     * @param object $instance
     * @return object
     */
    public function instance($abstract, $instance)
    {
        $this->bind($abstract, (new ReflectionClass($instance))->getName(), true);

        return $this->bindings[$abstract]['instance'] = $instance;
    }

    /**
     * Get the global instance of the container.
     *
     * @return static
     */
    public static function getContainer()
    {
        return static::$container;
    }

    /**
     * Return and array containing all the bindings.
     * Sometimes you are just curious.
     *
     * @return array
     */
    public function getBindings()
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
     * @return array
     */
    public function get($id)
    {
        if ($this->has($id)) {
            if (! $this->bindings[$id]) {
                throw $this->ContainerException('Error retrieving '.$id.' from container bindings.');
            }
        } else {
            $this->NotFoundException($id);
        }

        return $this->bindings[$id];
    }

    /**
     * Tnterface method for ContainerInterface.
     * Check if binding with $id exists.
     *
     * @param string $id
     * @return bool
     */
    public function has($id)
    {
        return array_key_exists($id, $this->bindings);
    }

    /********************************************
     * NotFoundExceptionInterface Method
     ********************************************/

    /**
     * Exception handler for bindings which cannot be found.
     *
     * @param string $id
     * @throws Exception
     */
    protected function NotFoundException($id)
    {
        throw new Exception('Binding Not Found Exception: '.$id.' is not bound to this container.');
    }

    /********************************************
     * ContainerExceptionInterface Method
     ********************************************/

    /**
     * General binding exception handler for class.
     *
     * @param string $message
     * @throws Exception
     */
    protected function ContainerException($message)
    {
        throw new Exception('Binding Exception: '.$message);
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
    public function offsetExists($offset)
    {
        return $this->has($offset);
    }

    /**
     * Interface method for ArrayAccess.
     * Returns instance identified by $offset binding.
     *
     * @param string $offset
     * @return object
     */
    public function offsetGet($offset)
    {
        if (! $this->has($offset)) {
            throw $this->NotFoundException($offset);
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
    public function offsetSet($offset, $value)
    {
        $this->bind($offset, $value);
    }

    /**
     * Interface method for ArrayAccess.
     * Remove the binding at $offset.
     *
     * @param string $offset
     */
    public function offsetUnset($offset)
    {
        unset($this->bindings[$offset]);
    }
}
