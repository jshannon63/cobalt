<?php

declare(strict_types=1);

namespace Jshannon63\Cobalt\Tests\Unit;

use Jshannon63\Cobalt\Container;
use Jshannon63\Cobalt\ContainerException;
use Jshannon63\Cobalt\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

// test interface for Foo
interface FooInterface
{
}

// non-instantiable class — used to verify the container surfaces a
// ContainerException when reflection can't construct the target.
class FooNotInstantiable implements FooInterface
{
    private function __construct()
    {
    }
}

// test class Foo — depends on Bar to exercise constructor autowiring.
class Foo implements FooInterface
{
    public function __construct(private readonly Bar $bar)
    {
    }

    public function bar(): Bar
    {
        return $this->bar;
    }
}

// test class Foo2 — interchangeable implementation of FooInterface,
// used to prove the concrete behind an interface can be swapped.
class Foo2 implements FooInterface
{
    public function __construct(private readonly Bar $bar)
    {
    }

    public function bar(): Bar
    {
        return $this->bar;
    }
}

// test class Bar — middle of the dependency chain Foo → Bar → Baz.
class Bar
{
    public function __construct(private readonly Baz $baz)
    {
    }

    public function baz(): Baz
    {
        return $this->baz;
    }
}

// test class Baz — leaf of the dependency chain. Demonstrates default
// scalar parameter handling during autowiring.
class Baz
{
    public function __construct(private readonly string $words = 'default words')
    {
    }

    public function sayWords(): string
    {
        return $this->words;
    }
}

// test class Fiz — accepts the Container itself, used to prove that
// type-hinting Container resolves to the active instance.
class Fiz
{
    public function __construct(public readonly Container $app)
    {
    }
}

// test class Yaz — no constructor, exercises the "no-constructor"
// fast path through the resolution pipeline.
class Yaz
{
    public function sayHello(): string
    {
        return 'Hello';
    }
}

// test class Zaz — non-class constructor parameter with no default,
// used to prove autowiring refuses to guess scalar values.
class Zaz
{
    public function __construct(private readonly string $value)
    {
    }

    public function value(): string
    {
        return $this->value;
    }
}

// Service whose interface dependency has NO default — used to prove that an
// unbound, non-instantiable type still surfaces the canonical "can not be
// instantiated" error when there is nothing to fall back on.
class ServiceWithRequiredInterfaceDep
{
    public function __construct(public readonly FooInterface $foo)
    {
    }
}

// Service that demonstrates union-type constructor parameters cannot be
// autowired — the container has no way to pick a member.
class ServiceWithUnionTypeDep
{
    public function __construct(public readonly Foo|Bar $either)
    {
    }
}

// Service with an interface parameter that defaults to null. Verifies that
// when the type is not instantiable (interface/abstract) AND a default is
// provided, the default is used rather than trying — and failing — to
// autowire the interface.
class ServiceWithOptionalInterfaceDep
{
    public function __construct(public readonly ?FooInterface $foo = null)
    {
    }
}

// test class Yib — variadic constructor combined with __invoke so we
// can prove that closure bindings can supply variadic arguments.
class Yib
{
    /** @var array<array-key, mixed> */
    private array $parms;

    public function __construct(mixed ...$parms)
    {
        $this->parms = $parms;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function __invoke(): array
    {
        return $this->parms;
    }
}

#[CoversClass(Container::class)]
final class ContainerTest extends TestCase
{
    // Verify the container self-binds on construction so that
    // type-hinting Container::class returns the active instance.
    public function test_container_self_binds_on_construction(): void
    {
        $app = new Container();

        // ArrayAccess lookup reaches the self-binding.
        self::assertInstanceOf(Container::class, $app[Container::class]);

        // The self-binding is shared — the same instance every time.
        self::assertSame($app, $app->resolve(Container::class));
    }

    // A singleton binding must return the same instance on every resolve.
    public function test_singleton_resolution_returns_same_instance(): void
    {
        $app = new Container('shared');
        $app->bind('Foo', Foo::class, true);

        self::assertSame($app->resolve('Foo'), $app->resolve('Foo'));
    }

    // Verify the concrete behind an interface binding can be swapped
    // out in-place — central to the program-to-interface workflow.
    public function test_concrete_implementation_can_be_swapped_on_interface(): void
    {
        $app = new Container();

        $app->bind('FooInterface', Foo::class);
        self::assertInstanceOf(Foo::class, $app['FooInterface']);

        $app->bind('FooInterface', Foo2::class);
        self::assertInstanceOf(Foo2::class, $app['FooInterface']);
    }

    // Recursive autowiring: Foo → Bar → Baz is resolved without hints.
    public function test_recursive_autowiring(): void
    {
        $app = new Container();
        $app->bind('Foo', Foo::class);

        $foo = $app['Foo'];
        self::assertInstanceOf(Foo::class, $foo);
        self::assertStringContainsString('default words', $foo->bar()->baz()->sayWords());
    }

    // Binding a class without instantiating registers it for later use.
    public function test_simple_binding_registers_in_container(): void
    {
        $app = new Container();
        $app->bind(Fiz::class);

        self::assertTrue($app->has(Fiz::class));
    }

    // Exercise the "no-constructor" fast path through resolution.
    public function test_binding_a_class_without_constructor(): void
    {
        $app = new Container();
        $app->bind(Yaz::class);

        self::assertTrue($app->has(Yaz::class));
        self::assertInstanceOf(Yaz::class, $app[Yaz::class]);
    }

    // Dependency injection through a Closure — fresh graph each resolve.
    public function test_closure_binding_produces_fresh_instances_per_resolve(): void
    {
        $app = new Container();
        $app->bind('Foo', static fn () => new Foo(new Bar(new Baz('Dependency Injection Rocks!'))));

        $first = $app->resolve('Foo');
        $second = $app->resolve('Foo');

        self::assertInstanceOf(Foo::class, $first);
        self::assertStringContainsString('Dependency Injection Rocks!', $first->bar()->baz()->sayWords());
        self::assertNotSame($first, $second);
    }

    // Closure binding flagged as singleton — same instance every time.
    public function test_singleton_closure_binding_returns_same_instance(): void
    {
        $app = new Container();
        $app->bind(
            'Foo',
            static fn () => new Foo(new Bar(new Baz('Dependency Injection Rocks!'))),
            true,
        );

        $first = $app->resolve('Foo');
        $second = $app->resolve('Foo');

        self::assertInstanceOf(Foo::class, $first);
        self::assertStringContainsString('Dependency Injection Rocks!', $first->bar()->baz()->sayWords());
        self::assertSame($first, $second);
    }

    // Store a new binding through ArrayAccess and read it back via both
    // ArrayAccess and the PSR-11 get() path.
    public function test_array_access_set_and_get(): void
    {
        $app = new Container();

        // ArrayAccess set with an existing instance — forced to singleton.
        $app['YogiBear'] = new Baz();

        self::assertInstanceOf(Baz::class, $app['YogiBear']);
        self::assertInstanceOf(Baz::class, $app->get('YogiBear'));
        // For direct-object bindings the stored concrete is the object itself
        // (object identity is what makes the binding meaningfully a singleton).
        self::assertInstanceOf(Baz::class, $app->getBindings()['YogiBear']['concrete']);
    }

    // If we register Fiz (which depends on Container) it must receive the
    // original container instance — not a new one — proving the self
    // binding remains stable across resolution.
    public function test_app_binding_remains_original_instance(): void
    {
        $app = new Container();
        $app->bind('Fiz', Fiz::class);

        $fiz = $app['Fiz'];
        self::assertInstanceOf(Fiz::class, $fiz);
        self::assertSame($app::getContainer(), $fiz->app);
    }

    // getBindings() exposes the registry — including the self binding.
    public function test_get_bindings_includes_self_binding(): void
    {
        $app = new Container();

        self::assertTrue($app->has(Container::class));
        self::assertArrayHasKey(Container::class, $app->getBindings());
    }

    // get() and offsetGet() should both surface the same self-binding.
    public function test_get_methods(): void
    {
        $app = new Container();

        self::assertSame($app, $app->get(Container::class));
        self::assertSame($app, $app->offsetGet(Container::class));
    }

    // Round-trip a binding through the ArrayAccess setter / unsetter.
    public function test_offset_setter_and_unsetter(): void
    {
        $app = new Container();
        $app->offsetSet('Fiz', Fiz::class);

        self::assertInstanceOf(Fiz::class, $app['Fiz']);

        $app->offsetUnset('Fiz');

        self::assertFalse($app->has('Fiz'));
        self::assertFalse($app->offsetExists('Fiz'));
    }

    // The static accessor returns the most recently constructed container.
    public function test_get_container_static(): void
    {
        $app = new Container();

        self::assertSame($app, $app::getContainer());
    }

    // ArrayAccess get on a missing binding raises NotFoundException
    // (required to satisfy the PSR-11 contract).
    public function test_offset_get_throws_when_missing(): void
    {
        $app = new Container();

        $this->expectException(NotFoundException::class);
        $app->offsetGet('abc');
    }

    // resolve() on a missing binding also raises NotFoundException.
    public function test_resolve_throws_when_missing(): void
    {
        $app = new Container();

        $this->expectException(NotFoundException::class);
        $app->resolve('abc');
    }

    // Binding a non-class string surfaces a ContainerException — the
    // container refuses to silently accept garbage concretes.
    public function test_binding_invalid_class_throws(): void
    {
        $app = new Container();

        $this->expectException(ContainerException::class);
        $app->bind('abc', '123');
    }

    // 'shared' mode at the container level overrides per-binding settings.
    public function test_shared_mode_forces_singleton(): void
    {
        $app = new Container('shared');
        $app->bind('Foo', Foo::class, false);

        self::assertSame($app['Foo'], $app['Foo']);
    }

    // Prototype mode caches the resolution closure but produces a brand
    // new object graph on every invocation.
    public function test_prototype_cache_produces_fresh_object_graph(): void
    {
        $app = new Container();
        $app->bind(Foo::class);

        $first = $app->resolve(Foo::class);
        $second = $app->resolve(Foo::class);

        self::assertInstanceOf(Foo::class, $first);
        self::assertInstanceOf(Foo::class, $second);
        self::assertNotSame($first, $second);
        self::assertNotSame($first->bar(), $second->bar());
        self::assertNotSame($first->bar()->baz(), $second->bar()->baz());
    }

    // A class that can't be instantiated (private constructor, abstract,
    // interface) surfaces a ContainerException with a clear message.
    public function test_non_instantiable_class_throws(): void
    {
        $app = new Container();

        $this->expectException(ContainerException::class);
        $app->bind(FooNotInstantiable::class);
        $app->resolve(FooNotInstantiable::class);
    }

    // Scalar / built-in parameters with no default value can't be
    // autowired — the container raises rather than guessing.
    public function test_non_class_parameter_without_default_throws(): void
    {
        $app = new Container();

        $this->expectException(ContainerException::class);
        $app->bind(Zaz::class);
        $app->resolve(Zaz::class);
    }

    // The singleton flag on a per-binding basis works for no-constructor
    // classes too — proves the cached singleton/prototype switch.
    public function test_factory_vs_singleton_for_no_constructor_class(): void
    {
        $app = new Container();
        $app->bind('YazFactory', Yaz::class, false);
        $app->bind('YazSingleton', Yaz::class, true);

        $factoryOne = $app->resolve('YazFactory');
        $singletonOne = $app->resolve('YazSingleton');
        $singletonTwo = $app->resolve('YazSingleton');

        self::assertNotSame($factoryOne, $singletonOne);
        self::assertSame($singletonOne, $singletonTwo);
    }

    // Closure bindings can supply variadic arguments — the closure
    // owns construction so reflection isn't required.
    public function test_variadic_constructor_via_closure(): void
    {
        $app = new Container();
        $app->bind('Yib', static fn () => new Yib('I', 'am', 'variadic', new Yaz(), static fn () => 'Closure'));

        // Yib is invokable: $app['Yib'] resolves the instance, () invokes it.
        $yib = $app['Yib'];
        self::assertInstanceOf(Yib::class, $yib);
        $value = $yib();

        self::assertSame(['I', 'am', 'variadic'], array_slice($value, 0, 3));
        self::assertInstanceOf(Yaz::class, $value[3]);
        self::assertSame('Hello', $value[3]->sayHello());
        self::assertInstanceOf(\Closure::class, $value[4]);
        self::assertSame('Closure', ($value[4])());
    }

    // Direct object binding always behaves as a singleton — a pre-built
    // instance is by definition shared.
    public function test_direct_object_binding_is_singleton(): void
    {
        $app = new Container();
        $app->bind('Baz', new Baz('Peace on Earth'));

        $baz = $app['Baz'];
        self::assertInstanceOf(Baz::class, $baz);
        self::assertSame('Peace on Earth', $baz->sayWords());
        self::assertTrue($app->getBinding('Baz')['singleton']);
    }

    // make() is the bind-and-resolve convenience wrapper.
    public function test_make_binds_and_resolves(): void
    {
        $app = new Container();
        $baz = $app->make(Baz::class);

        self::assertInstanceOf(Baz::class, $baz);
        self::assertSame('default words', $baz->sayWords());
    }

    // Aliases add an additional lookup key for an existing binding.
    public function test_alias_creates_additional_lookup_key(): void
    {
        $app = new Container();
        $app->make(Baz::class);
        $app->alias('aliased_test', Baz::class);

        $aliased = $app['aliased_test'];
        self::assertInstanceOf(Baz::class, $aliased);
        self::assertSame('default words', $aliased->sayWords());
    }

    // Non-string offsets aren't tracked — offsetExists must say so.
    public function test_offset_exists_returns_false_for_non_string_offset(): void
    {
        $app = new Container();

        // Calling with a non-string offset is invalid under the declared
        // ArrayAccess<string, mixed> contract, but the runtime is defensive.
        // @phpstan-ignore argument.type
        self::assertFalse($app->offsetExists(42));
    }

    // Non-string offsets aren't tracked either — offsetGet raises.
    public function test_offset_get_throws_for_non_string_offset(): void
    {
        $app = new Container();

        $this->expectException(NotFoundException::class);
        // @phpstan-ignore argument.type
        $app->offsetGet(42);
    }

    // An interface (or abstract) constructor dependency with a default value
    // should fall back to the default when no concrete is bound — rather
    // than throwing on the un-instantiable interface.
    public function test_optional_interface_dependency_uses_default_when_unbound(): void
    {
        $app = new Container();
        $app->bind(ServiceWithOptionalInterfaceDep::class);

        $svc = $app->resolve(ServiceWithOptionalInterfaceDep::class);

        self::assertInstanceOf(ServiceWithOptionalInterfaceDep::class, $svc);
        self::assertNull($svc->foo);
    }

    // Once an interface IS bound, the binding takes precedence over the
    // default — the default is only a fallback.
    public function test_bound_interface_dependency_overrides_default(): void
    {
        $app = new Container();
        $app->bind(FooInterface::class, static fn () => new Foo(new Bar(new Baz('bound!'))));
        $app->bind(ServiceWithOptionalInterfaceDep::class);

        $svc = $app->resolve(ServiceWithOptionalInterfaceDep::class);

        self::assertInstanceOf(ServiceWithOptionalInterfaceDep::class, $svc);
        self::assertInstanceOf(Foo::class, $svc->foo);
    }

    // A non-string, non-Closure, non-object concrete is rejected up front
    // with an intelligible error rather than misbehaving downstream.
    public function test_binding_a_non_string_non_closure_non_object_concrete_throws(): void
    {
        $app = new Container();

        $this->expectException(ContainerException::class);
        $app->bind('foo', 42);
    }

    // A union-typed constructor parameter cannot be autowired — there's no
    // sensible way for the container to choose a member.
    public function test_union_type_constructor_parameter_throws(): void
    {
        $app = new Container();

        $this->expectException(ContainerException::class);
        $app->bind(ServiceWithUnionTypeDep::class);
    }

    // An interface dependency with no default and no binding produces the
    // canonical "can not be instantiated" error at resolve time.
    public function test_required_interface_dependency_without_binding_throws(): void
    {
        $app = new Container();
        $app->bind(ServiceWithRequiredInterfaceDep::class);

        $this->expectException(ContainerException::class);
        $app->resolve(ServiceWithRequiredInterfaceDep::class);
    }

    // ArrayAccess set with a non-string offset is rejected — bindings are
    // keyed by string IDs.
    public function test_offset_set_with_non_string_offset_throws(): void
    {
        $app = new Container();

        $this->expectException(ContainerException::class);
        // @phpstan-ignore argument.type
        $app->offsetSet(42, new Baz());
    }

    // Calling getContainer() before any container has been constructed
    // raises rather than handing back stale or null state.
    public function test_get_container_throws_when_never_constructed(): void
    {
        // Reset the static state via reflection so this test is
        // independent of ordering with the other tests.
        $ref = new \ReflectionClass(Container::class);
        $prop = $ref->getProperty('container');
        $prop->setValue(null, null);

        $this->expectException(\LogicException::class);
        Container::getContainer();
    }
}
