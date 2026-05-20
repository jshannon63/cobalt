<?php

declare(strict_types=1);

namespace Jshannon63\Cobalt;

use ArrayAccess;
use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * Cobalt Service Container Interface.
 *
 * Extends PSR-11 with binding/aliasing operations and ArrayAccess so
 * implementations behave as both a standards-compliant container
 * and a familiar array-like registry of services.
 *
 * @author Jim Shannon (jim@hltky.com)
 *
 * License: MIT
 *
 * @extends ArrayAccess<string, mixed>
 */
interface CobaltContainerInterface extends PsrContainerInterface, ArrayAccess
{
    /**
     * Bind a class into the container.
     *
     * $concrete may be a class-string, a Closure, or an existing object
     * instance. When $concrete is an object, the binding is forced to
     * singleton (a pre-built instance is by definition shared).
     *
     * @throws ContainerException
     */
    public function bind(string $abstract, mixed $concrete = null, bool $singleton = false): void;

    /**
     * Resolve a binding out of the container. Should only be called when
     * you expect the binding to exist; missing bindings raise
     * NotFoundException per the PSR-11 contract.
     *
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function resolve(string $id): mixed;

    /**
     * Bind and immediately resolve in a single call.
     *
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function make(string $id, mixed ...$args): mixed;

    /**
     * Create an alias to an existing cached binding. After this call,
     * `$container[$alias]` resolves to the same instance as the
     * target binding.
     *
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function alias(string $alias, string $binding): void;

    /**
     * Get the most recently constructed container instance. Useful for
     * code that needs to reach the active container without having
     * it injected.
     */
    public static function getContainer(): self;

    /**
     * Return the internal record for a single binding. Useful for
     * debugging and introspection.
     *
     * @return array<string, mixed>
     */
    public function getBinding(string $id): array;

    /**
     * Return the entire binding registry. Sometimes you are just curious.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getBindings(): array;
}
