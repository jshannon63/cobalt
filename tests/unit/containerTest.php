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

class Yib
{
    protected $parms;

    public function __construct(...$parms)
    {
        $this->parms = $parms;
    }

    public function getParms()
    {
        return $this->parms;
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
        $app2 = $app->resolve(Container::class);
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

    public function testBindingOfClassWithoutConstructor(){
        $app = new Container();

        $app->bind('Tests\Yaz');

        $this->assertTrue($app->has('Tests\Yaz'));

        $yaz = $app['Tests\Yaz'];

        $this->assertInstanceOf(Yaz::class,$yaz);
    }

    // check dependency injection through closure
    public function testClosureInjection()
    {
        $app = new Container;

        $app->bind('Foo', function () {
            return new Foo(new Bar(new Baz('Dependency Injection Rocks!')));
        });

        $foo = $app->resolve('Foo');
        $foo2 = $app->resolve('Foo');

        $this->assertContains('Dependency Injection Rocks!', $foo->bar()->baz()->sayWords());

        $this->assertNotSame($foo, $foo2);
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

    // store a new binding through ArrayAccess method and retrieve it
    public function testArrayAccessBinding()
    {
        $app = new Container;

        $app['YogiBear'] = new Baz();

        $this->assertInstanceOf('Tests\Baz', $app['YogiBear']);

        $this->assertInstanceOf('Tests\Baz', $app->get('YogiBear'));

        $this->assertContains('Tests\Baz', $app->getBindings()['YogiBear']);
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

    public function testCacheCreatesFreshObjectGraph()
    {
        $app = new Container('cached');

        $app->bind(Foo::class);

        $foo = $app->resolve(Foo::class);
        $foo2 = $app->resolve(Foo::class);

        $this->assertNotSame($foo, $foo2);
        $this->assertNotSame($foo->bar(), $foo2->bar());
        $this->assertNotSame($foo->bar()->baz(), $foo2->bar()->baz());
    }

    public function testClassNotInstantiableCasuesException()
    {
        $app = new Container();

        $this->expectException(ContainerException::class);

        $app->bind(FooNotInstantiable::class);

        $app->resolve(FooNotInstantiable::class);
    }

    public function testNonClassWithoutDefaultValueCausesException()
    {
        $app = new Container();

        $this->expectException(ContainerException::class);

        $app->bind(Zaz::class);

        $app->resolve(Zaz::class);
    }

    public function testNonConstructorClasses()
    {
        $app = new Container();

        $app->bind('YazFactory', Yaz::class, false);
        $app->bind('YazSingleton', Yaz::class, true);

        $yaz = $app->resolve('YazFactory');
        $yaz1 = $app->resolve('YazSingleton');
        $yaz2 = $app->resolve('YazSingleton');

        $this->assertNotSame($yaz, $yaz1);
        $this->assertSame($yaz1, $yaz2);
    }

    public function testVariadicConstructorCausesException()
    {
        $app = new Container('cached');

        $this->expectException(ContainerException::class);

        $app->bind('Yib', Yib::class);
        ;
    }

    public function testDirectBindingOfObject()
    {
        $app = new Container('cached');

        $app->bind('Baz', new Baz('Peace on Earth'));

        $this->assertEquals('Peace on Earth', $app['Baz']->sayWords());
        $this->assertEquals(true, $app->getBinding('Baz')[$app::SINGLETON]);
    }

    public function testTimeToCreate()
    {
        $mode = 'shared';

        $timer['start'] = microtime(true);
        
        $app = new Container($mode);
        $timer['create'] = microtime(true)-$timer['start'];

        $app->bind(Foo::class);
        $timer['bind'] = ((microtime(true)-$timer['start'])-$timer['create']);

        $foo = $app->resolve(Foo::class);
        $timer['resolve'] = ((microtime(true)-$timer['start'])-$timer['bind']);

        $foo2 = $app->resolve(Foo::class);
        $timer['resolve2'] = ((microtime(true)-$timer['start'])-$timer['resolve']);

        $foo3 = $app->resolve(Foo::class);
        $timer['resolve3'] = ((microtime(true)-$timer['start'])-$timer['resolve2']);

        $foo4 = $app->resolve(Foo::class);
        $timer['resolve4'] = ((microtime(true)-$timer['start'])-$timer['resolve3']);

        for($cnt=0;$cnt<100000;$cnt++){
            $fooX = $app->resolve(Foo::class);
        }
        $timer['resolveX'] = ((microtime(true)-$timer['start'])-$timer['resolve4']);

        $timer['total'] = (microtime(true)-$timer['start']);

        unset($timer['start']);

        foreach($timer as $key=>$entry){
            $timer[$key]=number_format(1e6*$entry,2);
        }

        $timer['memory'] = ((memory_get_peak_usage()/1000)."Kbytes");

        var_dump($timer);

        if($mode == 'shared'){
            $this->assertSame($foo->bar()->baz(), $foo2->bar()->baz());
            $this->assertSame($foo2->bar()->baz(), $foo3->bar()->baz());
            $this->assertSame($foo3->bar()->baz(), $foo4->bar()->baz());
            $this->assertSame($foo4->bar()->baz(), $fooX->bar()->baz());
        } else{
            $this->assertNotSame($foo->bar()->baz(), $foo2->bar()->baz());
            $this->assertNotSame($foo2->bar()->baz(), $foo3->bar()->baz());
            $this->assertNotSame($foo3->bar()->baz(), $foo4->bar()->baz());
            $this->assertNotSame($foo4->bar()->baz(), $fooX->bar()->baz());
        }
    }

    public function testMakeCommandPointsToResolve(){
        $app = new Container();

        $app->bind('Baz', new Baz('Peace on Earth'));

        $test = $app->make('Baz');

        $this->assertEquals('Peace on Earth', $test->sayWords());
        $this->assertEquals(true, $app->getBinding('Baz')[$app::SINGLETON]);
    }
}
