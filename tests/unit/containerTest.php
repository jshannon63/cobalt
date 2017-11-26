<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Jshannon63\Cobalt\Container;
use Jshannon63\Cobalt\NotFoundException;
use Jshannon63\Cobalt\ContainerException;

// test interface for Foo
interface FooInterface
{
}

class FooNotInstantiable implements FooInterface
{
    private function __construct()
    {
    }
}

// test class Foo
class Foo implements FooInterface
{
    protected $bar;

    public function __construct(Bar $bar)
    {
        $this->bar = $bar;
    }

    public function bar()
    {
        return $this->bar;
    }
}

// test class Foo2
class Foo2 implements FooInterface
{
    protected $bar;

    public function __construct(Bar $bar)
    {
        $this->bar = $bar;
    }

    public function bar()
    {
        return $this->bar;
    }
}

// test class Bar
class Bar
{
    protected $baz;

    public function __construct(Baz $baz)
    {
        $this->baz = $baz;
    }

    public function baz()
    {
        return $this->baz;
    }
}

// test class Baz
class Baz
{
    protected $words;

    public function __construct($words = 'default words')
    {
        $this->words = $words;
    }

    public function sayWords()
    {
        return $this->words;
    }
}

// test class Fiz
class Fiz
{
    public $app;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }
}

class Yaz
{
}

class Zaz
{
    protected $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }
}

class containerTest extends TestCase
{
    // check all aspects of the container creation.
    public function testContainerInitialization()
    {
        // new up container as app
        $app = new Container;

        // check for self binding using array access functionality
        $this->assertTrue($app[Container::class] instanceof Container);

        // check Container instance is bound as singleton
        $app2 = $app->make(Container::class);
        $this->assertTrue($app === $app2);
    }

    // make sure a singleton binding returns the same instance each time.
    public function testSingletonResolution()
    {
        $app = new Container;

        $app->bind('Foo', 'Tests\Foo', true);
        $instance1 = $app->resolve('Foo');
        $instance2 = $app->resolve('Foo');
        $this->assertTrue($instance1 === $instance2);
    }

    // verify concrete implementation switchout on interface binding
    public function testConcreteFromInterface()
    {
        $app = new Container;

        $app->bind('FooInterface', 'Tests\Foo');
        $foo = $app['FooInterface'];
        $this->assertTrue($foo instanceof Foo);

        $app->bind('FooInterface', 'Tests\Foo2');
        $foo2 = $app['FooInterface'];
        $this->assertTrue($foo2 instanceof Foo2);
    }

    // check recursive binding and dependency injection
    public function testRecursiveDIandReflection()
    {
        $app = new Container;

        $app->bind('Foo', Foo::class);

        $this->assertContains('default words', $app['Foo']->bar()->baz()->sayWords());
    }

    // check binding of class without dependencies or constructor.
    public function testSimpleBinding()
    {
        $app = new Container;

        $app->bind('Tests\Fiz');

        $this->assertTrue($app->has('Tests\Fiz'));
    }

    // check dependency injection through closure
    public function testClosureInjection()
    {
        $app = new Container;

        $app->bind('Foo', function () {
            return new Foo(new Bar(new Baz('Dependency Injection Rocks!')));
        });

        $foo = $app->resolve('Foo');

        $this->assertContains('Dependency Injection Rocks!', $foo->bar()->baz()->sayWords());
    }

    // check dependency injection through closure as singleton
    public function testSingletonClosureInjection()
    {
        $app = new Container;

        $app->bind('Foo', function () {
            return new Foo(new Bar(new Baz('Dependency Injection Rocks!')));
        }, true);

        $foo = $app->resolve('Foo');
        $foo2 = $app->resolve('Foo');

        $this->assertContains('Dependency Injection Rocks!', $foo->bar()->baz()->sayWords());

        $this->assertSame($foo, $foo2);
    }

    // store a new binding through ArrayAccess method and retrieve it with ge
    public function testArrayAccessBinding()
    {
        $app = new Container;

        $app['YogiBear'] = new Baz();

        $this->assertInstanceOf('Tests\Baz', $app['YogiBear']);

        $this->assertInstanceOf('Tests\Baz', $app->get('YogiBear'));

        $this->assertContains('Tests\Baz', $app->getBindings()['YogiBear']['concrete']);
    }

    // verify if we register our newly created $app back into the container,
    // it must reference the original container instance. in this case Fiz
    // has a dependency of Container which needs injection.
    public function testAppBindingRemainsOriginal()
    {
        $app = new Container;

        $app->bind('Fiz', Fiz::class);

        $this->assertTrue(($app->getContainer() === $app['Fiz']->app));
    }

    public function testCachedBindings()
    {
        $before = microtime(true);

        $app = new Container();

        $app->bind('Foo', Foo::class, false);
        $instance1 = $app->make('Foo');
        for ($i = 0; $i < 50000; $i++) {
            $instance2 = $app->make('Foo');
        }
        $this->assertFalse($instance1 === $instance2);

        $between = microtime(true);

        $app2 = new Container('cached');

        $app2->bind('Foo2', Foo::class, false);
        $instance3 = $app2->make('Foo2');
        for ($i = 0; $i < 50000; $i++) {
            $instance4 = $app2->make('Foo2');
        }
        $this->assertFalse($instance3 === $instance4);

        $after = microtime(true);

        // cached mode should be at least 5x as fast.
        // echo($between - $before) / (($after - $between));
        $this->assertGreaterThan(($after - $between) * 5, $between - $before);
    }

    public function testBindingsListing()
    {
        $app = new Container();

        $this->assertTrue($app->has(Container::class));

        $this->assertArrayHasKey(Container::class, $app->getBindings());
    }

    public function testInstanceBinding()
    {
        $app = new Container();

        $fiz = $app->instance('Fiz', new Fiz($app));

        $this->assertInstanceOf(Fiz::class, $fiz);
    }

    public function testGetMethods()
    {
        $app = new Container();

        $myapp = $app->get(Container::class);
        $myapp2 = $app->offsetGet(Container::class);

        $this->assertSame($app, $myapp);
        $this->assertSame($app, $myapp2);
    }

    public function testSetterUnsetter()
    {
        $app = new Container();

        $app->offsetSet('Fiz', Fiz::class);

        $this->assertInstanceOf(Fiz::class, $app['Fiz']);

        $app->offsetUnset('Fiz');

        $this->assertFalse($app->has('Fiz'));

        $this->assertFalse($app->offsetExists('Fiz'));
    }

    public function testGetContainer()
    {
        $app = new Container();

        $this->assertSame($app, $app::getContainer());
    }

    public function testOffsetGetException()
    {
        $app = new Container();

        $this->expectException(NotFoundException::class);

        $app->offsetGet('abc');
    }

    public function testResolveException()
    {
        $app = new Container();

        $this->expectException(NotFoundException::class);

        $app->resolve('abc');
    }

    public function testBindingException()
    {
        $app = new Container();

        $this->expectException(ContainerException::class);

        $app->bind('abc', '123');
    }

    public function testSharedModeForcesSingleton()
    {
        $app = new Container('shared');

        $app->bind('Foo', Foo::class, false);

        $instance1 = $app['Foo'];
        $instance2 = $app['Foo'];

        $this->assertSame($instance1, $instance2);
    }

    public function testRebindingBustsDependerCache()
    {
        $app = new Container('cached');

        $app->bind(Foo::class);

        $foo = $app->resolve(Foo::class);

        $this->assertContains(Foo::class, $app->getBinding(Bar::class)['depender']);
        $this->assertTrue($app->getBinding(Foo::class)['cached']);

        $app->bind(Bar::class);

        $bar = $app->resolve(Bar::class);

        $this->assertFalse($app->getBinding(Foo::class)['cached']);
    }

    public function testClassNotInstantiable()
    {
        $app = new Container();

        $this->expectException(ContainerException::class);

        $app->make(FooNotInstantiable::class);
    }

    public function testNonClassWithoutDefaultValue()
    {
        $app = new Container();

        $this->expectException(ContainerException::class);

        $app->make(Zaz::class);
    }

    public function testNonConstructorClasses()
    {
        $app = new Container();

        $app->bind('YazFactory', Yaz::class, false);
        $app->bind('YazSingleton', Yaz::class, true);

        $yaz = $app->make('YazFactory');
        $yaz1 = $app->make('YazSingleton');
        $yaz2 = $app->make('YazSingleton');

        $this->assertNotSame($yaz, $yaz1);
        $this->assertSame($yaz1, $yaz2);
    }

    public function testPerformanceBenchmark()
    {
        $app = new Container();

        // get base time for standard
        $before = microtime(true);
        for ($i = 0; $i < 100000; $i++) {
            $a[$i] = $i;
            unset($a[$i]);
        }
        $base = microtime(true) - $before;

        // run test and get the time
        $before = microtime(true);
        for ($i = 0; $i < 100000; $i++) {
            $app->bind(Foo::class);
            $a[$i] = $app->make(Foo::class);
            unset($a[$i]);
        }
        $test = microtime(true) - $before;

        $this->assertGreaterThan(200, $test / $base);
    }
}
