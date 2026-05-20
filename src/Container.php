<?php

declare(strict_types=1);

namespace Jshannon63\Cobalt;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Cobalt Service Container.
 *
 * A PSR-11 derived IoC container that provides autowired dependency
 * injection. Supports dependency injection through bind() method
 * closures and container access via ArrayAccess. Fully auto-wired
 * with cached resolution closures. Supports direct binding of
 * existing object instances. Provides singleton (shared) and
 * factory (prototype) modes. Encourages program-to-interface
 * style via interface binding with a swappable concrete
 * implementation.
 *
 * @author  Jim Shannon (jim@hltky.com)
 *
 * License: MIT
 *
 * @phpstan-type DependencyRecord array{type: 'class'|'default', value: mixed, default?: mixed}
 * @phpstan-type BindingRecord    array{
 *     ID: int,
 *     concrete: mixed,
 *     singleton: bool,
 *     reflector?: ReflectionClass<object>,
 *     dependencies?: array<int, DependencyRecord>
 * }
 */
class Container implements CobaltContainerInterface
{
    /**
     * Global container instance, populated by the constructor and returned by
     * the static getContainer() accessor. Allows callers anywhere in the
     * application to reach back into the active container.
     */
    private static ?Container $container = null;

    /**
     * Auto-incrementing binding identifier. Useful for debugging the
     * registration order of bindings via getBindings().
     */
    private int $bindingId = 0;

    /**
     * Array registry of container bindings. Keys are the abstract IDs
     * (class names, interface names, or arbitrary string labels) and
     * values are binding records describing how to resolve them.
     *
     * @var array<string, BindingRecord>
     */
    private array $bindings = [];

    /**
     * Array of cached resolution closures keyed by binding ID. When a binding
     * is hit for the first time we build a tightly-scoped closure that knows
     * exactly how to produce an instance, then store it here so subsequent
     * resolves skip all reflection work and just invoke the closure.
     *
     * @var array<string, Closure>
     */
    private array $cache = [];

    /**
     * Aliases mapped to the underlying binding ID they point at. Aliases are
     * convenience labels — both the binding record and the cached closure
     * are duplicated under the alias key so resolution is direct.
     *
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * Container constructor.
     *
     * Stores this instance as the global container, self-binds Container::class
     * (so `$app[Container::class]` returns the active container), and aliases
     * both PSR-11 and the Cobalt interface to that self-binding — meaning
     * type-hinting any of the three resolves to the same singleton.
     *
     * @param  string|null  $mode  Pass 'shared' to force every binding to a singleton.
     *                             Any other value is ignored.
     *
     * @throws ContainerException
     * @throws NotFoundException
     */
    public function __construct(private readonly ?string $mode = null)
    {
        self::$container = $this;
        $this->bind(self::class, $this);
        $this->alias(ContainerInterface::class, self::class);
        $this->alias(CobaltContainerInterface::class, self::class);
    }

    /**
     * Bind a class into the container. Binding does not instantiate. That is
     * performed when the object is requested. If an interface and a
     * concrete class are both provided, then we bind the abstract
     * interface to the container. A subsequent call to resolve()
     * or make() against the abstract will return an instance of
     * the concrete. This allows interface type-hinting throughout
     * your code with easy swap-out of concrete implementations.
     *
     * @param  mixed  $concrete  A class-string, a Closure, or an existing object instance.
     *
     * @throws ContainerException
     */
    public function bind(string $abstract, mixed $concrete = null, bool $singleton = false): void
    {
        // Start fresh — if this is a rebound abstract, drop any prior record.
        $this->destroyBinding($abstract);

        // Initialise the binding record. If $concrete is omitted we treat the
        // abstract as the concrete (typical when calling bind(Foo::class)).
        // In 'shared' container mode every binding is forced to singleton.
        $this->bindings[$abstract] = [
            'ID' => $this->bindingId++,
            'concrete' => $concrete ?? $abstract,
            'singleton' => $this->mode === 'shared' ? true : $singleton,
        ];

        // Reference into the binding for readability below.
        $binding = &$this->bindings[$abstract];

        // Closure bindings are deferred — we don't run reflection on them and
        // instead build the resolution closure lazily inside create().
        if ($binding['concrete'] instanceof Closure) {
            return;
        }

        // An object was passed directly. Force singleton (a pre-built instance
        // is by definition shared) and cache a closure that simply returns it.
        if (is_object($binding['concrete'])) {
            $binding['singleton'] = true;
            $this->prepareBindingClosure($abstract, $binding['concrete']);

            return;
        }

        // Anything else must be a class-string. Refuse other scalar types here
        // so callers get an immediate, intelligible error.
        $concreteClass = $binding['concrete'];
        if (! is_string($concreteClass)) {
            throw new ContainerException('Concrete binding must be a class-string, Closure, or object instance.');
        }

        // Reflect on the concrete class. If it isn't a real class we surface a
        // clear ContainerException rather than letting the raw Reflection
        // error escape.
        try {
            // ReflectionClass accepts any string at runtime and throws if the
            // class is missing — that throw is exactly what we catch below.
            // @phpstan-ignore argument.type
            $reflector = new ReflectionClass($concreteClass);
        } catch (ReflectionException) {
            throw new ContainerException($concreteClass.' does not appear to be a valid class.');
        }

        $binding['reflector'] = $reflector;
        $binding['concrete'] = $reflector->getName();

        // Walk the constructor signature once now so the dependency graph is
        // cached on the binding record — resolution can then be performed
        // without re-reflecting on every call.
        $this->processDependencies($binding);
    }

    /**
     * Resolve a binding by first making sure it exists, then handing back
     * a freshly produced (or shared) instance. Resolve should only be
     * called when you expect the binding to exist; missing bindings
     * raise NotFoundException to satisfy the PSR-11 contract.
     *
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function resolve(string $id): mixed
    {
        // Make sure the binding exists.
        if (! isset($this->bindings[$id])) {
            throw new NotFoundException('Binding '.$id.' not found.');
        }

        // Fast path — a cached closure exists, invoke it and we're done.
        if (isset($this->cache[$id])) {
            return ($this->cache[$id])();
        }

        // Cold path — build the resolution closure, then invoke it. The closure
        // is stored inside create() so the next call hits the fast path.
        return ($this->create($id))();
    }

    /**
     * Convenience for "bind then resolve" in a single call. Useful when you
     * want a one-shot instantiation without keeping the binding around.
     *
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function make(string $id, mixed ...$args): mixed
    {
        $this->bind($id, ...$args);

        return $this->resolve($id);
    }

    /**
     * Build (and cache) the resolution closure for a binding. The closure
     * encapsulates the entire decision tree for that binding so that
     * subsequent resolves are a single function invocation.
     *
     * @throws ContainerException
     */
    private function create(string $id): Closure
    {
        // Check if the binding already exists. Allow create() to operate on a
        // bare identifier by binding it on the fly — this is what powers the
        // recursive walk further down.
        if (! isset($this->bindings[$id])) {
            $this->bind($id);
        }

        // If a closure has already been cached for this binding, hand it back.
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $binding = &$this->bindings[$id];

        // If the concrete is a Closure (deferred from bind()) or the class has
        // no constructor dependencies, we have everything we need — produce
        // the resolution closure directly.
        if ($binding['concrete'] instanceof Closure || ($binding['dependencies'] ?? []) === []) {
            return $this->prepareBindingClosure($id, $binding['concrete']);
        }

        // Otherwise walk every constructor parameter, recursing into create()
        // for class dependencies (each returns a closure we can invoke later)
        // and copying through default values for scalars.
        //
        // For class deps we consult the live binding registry first: an
        // explicit binding always wins. Without a binding, autowiring only
        // makes sense for instantiable concretes — interfaces and abstract
        // classes fall back to the captured default value if one exists.
        $dependencies = [];
        foreach ($binding['dependencies'] ?? [] as $dependency) {
            if ($dependency['type'] === 'class') {
                /** @var class-string $depClass */
                $depClass = $dependency['value'];

                if (isset($this->bindings[$depClass]) || $this->isInstantiableClass($depClass)) {
                    $dependencies[] = $this->create($depClass);
                } elseif (array_key_exists('default', $dependency)) {
                    $dependencies[] = $dependency['default'];
                } else {
                    // No binding, not instantiable, no default — let create()
                    // surface the canonical "can not be instantiated" error.
                    $dependencies[] = $this->create($depClass);
                }
            } else {
                $dependencies[] = $dependency['value'];
            }
        }

        // By the time we get here a reflector must exist — every binding that
        // produced a non-empty dependency list also stored its ReflectionClass.
        $reflector = $binding['reflector'] ?? null;
        assert($reflector instanceof ReflectionClass);

        // We've reached the bottom of the dependency chain and have closures
        // (or default values) for every constructor argument. Build the
        // resolution closure for this binding with all dependencies wired.
        return $this->prepareBindingClosure($id, $reflector, $dependencies);
    }

    /**
     * Inspect a class's constructor and record everything we need to build it
     * later — without actually constructing anything. The resulting record
     * is stored on the binding so subsequent resolves skip reflection.
     *
     * @param  BindingRecord  $binding
     *
     * @throws ContainerException
     *
     * @param-out BindingRecord $binding
     */
    private function processDependencies(array &$binding): void
    {
        $reflector = $binding['reflector'] ?? null;

        // Abstract classes, interfaces, and classes with a private constructor
        // can't be instantiated — bail out early with a clear message.
        if ($reflector === null || ! $reflector->isInstantiable()) {
            $name = $reflector?->getName() ?? 'binding';
            throw new ContainerException($name.' can not be instantiated.');
        }

        // No constructor? Then there's nothing to wire — store an empty
        // dependency list and we're done.
        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            $binding['dependencies'] = [];

            return;
        }

        // Otherwise extract a description of each parameter for later use.
        $binding['dependencies'] = $this->resolveParameters($constructor->getParameters());
    }

    /**
     * Inspect each constructor parameter and emit a dependency record. We do
     * NOT instantiate dependencies here — we only describe them so the
     * actual construction can be deferred and cached.
     *
     * Union and intersection types cannot be sensibly autowired (the
     * container has no way to pick a member), so they raise an
     * exception rather than guessing.
     *
     * @param  array<int, ReflectionParameter>  $parameters
     * @return array<int, DependencyRecord>
     *
     * @throws ContainerException
     */
    private function resolveParameters(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $key => $parameter) {
            $type = $parameter->getType();

            // Union/intersection types are ambiguous for autowiring — caller
            // must bind explicitly via a Closure.
            if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
                throw new ContainerException(
                    'Cannot autowire union or intersection type for parameter ('.$parameter->getName().').',
                );
            }

            // Single class type-hint — record the class name so create() can
            // recurse and produce an instance later. If the parameter also
            // carries a default value we tuck it onto the record; the real
            // decision (use binding vs use default) is deferred to resolve
            // time so an explicit binding can still override the default
            // even when bindings are registered out of order.
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $className = $type->getName();

                // Narrow string → class-string. For a non-builtin named type
                // this should always succeed against one of the three; the
                // throw is a defensive guardrail rather than a real code path.
                if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className)) {
                    // @codeCoverageIgnoreStart
                    throw new ContainerException(
                        'Type '.$className.' for parameter '.$parameter->getName().' is not a known class, interface, or trait.',
                    );
                    // @codeCoverageIgnoreEnd
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[$key] = [
                        'type' => 'class',
                        'value' => $className,
                        'default' => $parameter->getDefaultValue(),
                    ];
                } else {
                    $dependencies[$key] = ['type' => 'class', 'value' => $className];
                }

                continue;
            }

            // Anything else (built-in scalar, no type, nullable scalar) must
            // come from a default value — we have nothing else to fall back on.
            if (! $parameter->isDefaultValueAvailable()) {
                throw new ContainerException(
                    'Non class dependency ('.$parameter->getName().') requires default value.',
                );
            }

            try {
                $default = $parameter->getDefaultValue();
                // @codeCoverageIgnoreStart
            } catch (ReflectionException $e) {
                // getDefaultValue() only throws for unsupported default-value
                // expressions, which the engine refuses to compile against
                // anyway — kept as a belt-and-braces translation to our own
                // exception type.
                throw new ContainerException($e->getMessage(), 0, $e);
            }
            // @codeCoverageIgnoreEnd

            $dependencies[$key] = ['type' => 'default', 'value' => $default];
        }

        return $dependencies;
    }

    /**
     * Report whether the given class name refers to a concrete, instantiable
     * class — used to decide whether a default value should win over
     * autowiring for interface/abstract dependencies.
     */
    private function isInstantiableClass(string $className): bool
    {
        // class_exists narrows to class-string and excludes interfaces.
        // Abstract classes pass class_exists but fail isInstantiable.
        return class_exists($className) && (new ReflectionClass($className))->isInstantiable();
    }

    /**
     * Build a resolution closure for a binding and store it in the cache.
     * Dispatches to a singleton- or prototype-flavour closure depending
     * on the binding's mode.
     *
     * @param  array<int, mixed>|null  $dependencies
     */
    private function prepareBindingClosure(string $id, mixed $blueprint, ?array $dependencies = null): Closure
    {
        return $this->bindings[$id]['singleton']
            ? $this->prepareSingletonBindingClosure($id, $blueprint, $dependencies)
            : $this->preparePrototypeBindingClosure($id, $blueprint, $dependencies);
    }

    /**
     * Build a prototype (factory) resolution closure. Each invocation of the
     * stored closure produces a brand-new object graph by invoking the
     * dependency closures and constructing a fresh instance.
     *
     * @param  array<int, mixed>|null  $dependencies
     */
    private function preparePrototypeBindingClosure(string $id, mixed $blueprint, ?array $dependencies): Closure
    {
        // Class with constructor — invoke the dependency closures every time
        // so each call produces a freshly-built object graph.
        if ($blueprint instanceof ReflectionClass) {
            $deps = $dependencies ?? [];

            return $this->cache[$id] = static function () use ($blueprint, $deps): object {
                foreach ($deps as $key => $dependency) {
                    if ($dependency instanceof Closure) {
                        $deps[$key] = $dependency();
                    }
                }

                return $blueprint->newInstanceArgs($deps);
            };
        }

        // The caller supplied a Closure directly — they are responsible for
        // returning whatever they want each time it's invoked.
        if ($blueprint instanceof Closure) {
            return $this->cache[$id] = $blueprint;
        }

        // No-constructor class — just `new` it each time.
        if (is_string($blueprint)) {
            return $this->cache[$id] = static fn (): object => new $blueprint();
        }

        // Object-instance fallback. bind() forces singletons for object
        // concretes, so this branch is unreachable through the public API
        // — kept only to make the closure unconditionally safe to invoke.
        // @codeCoverageIgnoreStart
        return $this->cache[$id] = static fn (): mixed => $blueprint;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Build a singleton resolution closure. We materialise the instance once,
     * up front, and the cached closure simply hands it back forever after.
     *
     * @param  array<int, mixed>|null  $dependencies
     */
    private function prepareSingletonBindingClosure(string $id, mixed $blueprint, ?array $dependencies): Closure
    {
        $instance = $blueprint;

        if ($blueprint instanceof ReflectionClass) {
            // Resolve each dependency closure to a real value, then construct
            // the singleton instance exactly once.
            $deps = $dependencies ?? [];
            foreach ($deps as $key => $dependency) {
                if ($dependency instanceof Closure) {
                    $deps[$key] = $dependency();
                }
            }
            $instance = $blueprint->newInstanceArgs($deps);
        } elseif ($blueprint instanceof Closure) {
            // User-supplied factory — invoke once to capture the singleton.
            $instance = $blueprint();
        } elseif (is_string($blueprint)) {
            // No-constructor class — single `new` is enough.
            $instance = new $blueprint();
        }

        // The singleton is now hydrated — drop reflection metadata so we
        // aren't holding the ReflectionClass and dependency descriptors
        // alive for the life of the container.
        unset($this->bindings[$id]['dependencies'], $this->bindings[$id]['reflector']);

        return $this->cache[$id] = static fn (): mixed => $instance;
    }

    /**
     * Register an alias pointing at an existing binding. After this call,
     * `$app[$alias]` and `$app[$binding]` return the same resolved value.
     *
     * Implementation note: we call resolve() first to validate the target
     * exists (and to ensure its cache entry is populated), then copy the
     * binding record and cached closure under the alias key so lookups
     * are direct rather than going through an indirection table.
     *
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function alias(string $alias, string $binding): void
    {
        // This both validates the target exists and warms its cache.
        $this->resolve($binding);

        $this->bindings[$alias] = $this->bindings[$binding];
        $this->cache[$alias] = $this->cache[$binding];
        $this->aliases[$alias] = $binding;
    }

    /**
     * Remove all traces of a binding — record, cached closure, and alias.
     */
    private function destroyBinding(string $id): void
    {
        unset($this->cache[$id], $this->bindings[$id], $this->aliases[$id]);
    }

    /**
     * Return the most recently constructed container instance. Throws if
     * called before any container has been constructed — there is no
     * sensible default to hand back in that case.
     */
    public static function getContainer(): self
    {
        if (self::$container === null) {
            throw new \LogicException('No container has been constructed yet.');
        }

        return self::$container;
    }

    /**
     * Return the internal record for a single binding. Useful for debugging.
     *
     * @return BindingRecord
     */
    public function getBinding(string $id): array
    {
        return $this->bindings[$id];
    }

    /**
     * Return the full binding registry. Sometimes you are just curious.
     *
     * @return array<string, BindingRecord>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /* --------------------------------------------------------------------- *
     * PSR-11 ContainerInterface
     * --------------------------------------------------------------------- */

    /**
     * PSR-11: get the binding with the given $id.
     *
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    /**
     * PSR-11: report whether a binding exists for the given $id.
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    /* --------------------------------------------------------------------- *
     * ArrayAccess
     * --------------------------------------------------------------------- */

    /**
     * ArrayAccess: report whether a binding exists at $offset. Non-string
     * offsets are never registered, so always report false for them.
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->has($offset);
    }

    /**
     * ArrayAccess: resolve the instance for the binding at $offset. Throws
     * NotFoundException if the offset is missing or not a string.
     *
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (! is_string($offset) || ! $this->has($offset)) {
            throw new NotFoundException('Binding '.(is_scalar($offset) ? (string) $offset : '?').' not found.');
        }

        return $this->resolve($offset);
    }

    /**
     * ArrayAccess: bind $value as the concrete for $offset (the abstract).
     * Non-string offsets are rejected — bindings are keyed by string IDs.
     *
     * @throws ContainerException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (! is_string($offset)) {
            throw new ContainerException('Container offsets must be strings.');
        }

        $this->bind($offset, $value);
    }

    /**
     * ArrayAccess: remove the binding at $offset (if any).
     */
    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset)) {
            $this->destroyBinding($offset);
        }
    }
}
