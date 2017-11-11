<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Jshannon63\Container\Container;

// test interface for Foo
interface FooInterface
{
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
}

class containerTest extends TestCase
{
    // check all aspects of the container creation.
    public function testContainerInitialization()
    {
        // new up container as app
        $app = new Container;

        // check for self binding using array access functionality
        $this->assertTrue($app['Container'] instanceof Container);

        // check Container instance is bound as singleton
        $app2 = $app->make('Container');
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

        $app->bind('Foo', 'Tests\Foo');

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

    // store a new binding through ArrayAccess method and retrieve it with ge
    public function testArrayAccessBinding()
    {
        $app = new Container;

        $app['YogiBear'] = new Baz();

        $this->assertInstanceOf('Tests\Baz', $app['YogiBear']);

        $this->assertInstanceOf('Tests\Baz', $app->get('YogiBear'));

        $this->assertContains('Tests\Baz', $app->getBindings()['YogiBear']['concrete']);
    }
}
