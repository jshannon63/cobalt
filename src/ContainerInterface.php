<?php

declare(strict_types=1);

namespace Jshannon63\Cobalt;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use ArrayAccess;

/**
 * Cobalt Service Container Interface.
 *
 * @author Jim Shannon (jim@hltky.com)
 * @link https://jimshannon.me
 * Date: 9/19/17
 * License: MIT
 */
interface ContainerInterface extends PsrContainerInterface, ArrayAccess
{
    /**
     * Bind a class into the container.
     *
     * @param  string $abstract
     * @param  mixed $concrete
     * @param  bool $singleton
     * @return  void
     * @throws ContainerException
     */
    public function bind($abstract, $concrete = null, $singleton = false);

    /**
     * Resolve binding.
     *
     * @param  string $id
     * @return object
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function resolve($id);

    /**
     * Bind and then resolve to return an instantiated binding.
     *
     * @param  $id
     * @param  $args
     * @return object
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function make($id, ...$args);

    /**
     * Deprecated: Hold for backward compatibility.
     *
     * @param  string $abstract
     * @param  object $instance
     * @return object
     * @throws ContainerException
     */
    public function instance($abstract, $instance);

    /**
     * Create an alias to an existing cached binding
     *
     * @param  string $alias
     * @param  string $binding
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function alias($alias, $binding);

    /**
     * Get the global instance of the container.
     *
     * @return Container
     */
    public static function getContainer();

    /**
     * Return and array containing a the requested binding information.
     *
     * @param  string $id
     * @return array
     */
    public function getBinding($id);

    /**
     * Return and array containing all the bindings.
     * Sometimes you are just curious.
     *
     * @return array
     */
    public function getBindings();

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
     *
     */
    public function get($id);

    /**
     * Interface method for ContainerInterface.
     * Check if binding with $id exists.
     *
     * @param  string $id
     * @return bool
     */
    public function has($id);

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
    public function offsetExists($offset);

    /**
     * Interface method for ArrayAccess.
     * Returns instance identified by $offset binding.
     *
     * @param  string $offset
     * @return object
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function offsetGet($offset);

    /**
     * Interface method for ArrayAccess.
     * Set binding at $offset (abstract) with $value (concrete).
     *
     * @param  string $offset
     * @param  mixed $value
     * @throws ContainerException
     */
    public function offsetSet($offset, $value);

    /**
     * Interface method for ArrayAccess.
     * Remove the binding at $offset.
     *
     * @param  string $offset
     */
    public function offsetUnset($offset);
}

