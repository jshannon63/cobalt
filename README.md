[![Build Status](https://travis-ci.org/jshannon63/cobalt.svg?branch=master)](https://travis-ci.org/jshannon63/cobalt)
[![StyleCI](https://styleci.io/repos/104802764/shield?branch=master)](https://styleci.io/repos/104802764)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)


# Cobalt - An Autowired Dependency Injection Container for PHP with Optional Dependency Caching
  
  __Realized in fewer than 160 lines of code.__
  
  __Well documented, perfect for building/learning.__
   
  __100% PHPUnit test coverage__ 
    
  __Among the fastest PHP autowired containers available__
  
See [kocsismate/php-di-container-benchmarks](https://github.com/kocsismate/php-di-container-benchmarks) test results [here](https://rawgit.com/kocsismate/php-di-container-benchmarks/master/var/benchmark.html)

Cobalt was created to push the performance limits on what a PHP dynamic autowired DI/IoC application container can achieve. The Container::class implements the PSR-11 ContainerInterface and provides many of the features found in more notable container projects. Additionally, dependency caching capabilities make the Cobalt container a great choice for performance intensive applications. Cobalt and its simplistic code are perfect for learning or for use within projects or frameworks.

The Cobalt service container has the following features:  

1. Single class container implementing the PSR-11 ContainerInterface.
2. Support for ArrayAccess methods on container bindings.
3. Automatic constructor injection of type-hinted dependencies.
4. Dependency injection through a bind method Closure.
5. Autowired dependency resolution using Reflection.
6. Full top down inversion of control (IoC).
7. Shared instances (singletons).
8. Abstract class bound to a concrete implementation simplifies code.
9. Bind existing instances into the container.
10. A self-binding global container instance.
11. Optional dependency caching for blazing fast speed.
12. Optional shared only (singleton) mode.
13. Exhaustive source code documentation.

Apology: I realize there are entirely too many source comments. These are meant for new developers who might appreciate them. They will most certainly help anyone looking to fully understand the inner workings of a modern service container.

## Installation
```
composer require jshannon63/cobalt  
```

## Usage


### Creating the container
```php
use Jshannon63\Cobalt\Container;
 
// create a default container 
  
$app = new Container();
  
// or, create a singleton only services container
  
$app = new Container('shared');
    
// or, enable dependency caching for improved performance
  
$app = new Container('cached');
```

### Binding into the container
Binding does not instantiate the class. Instantiation is deferred until requested from the container. The bind method accepts 3 parameters... the abstract name, the concrete implementation name and a true or false for defining as a singleton. Notice in all three versions we use different abstract names. This is to show that the abstract name is free-form and is used as the "key" for array storage of bindings.

**bind($abstract, $concrete=null, $singleton=false)**

```php
// a simple binding using only the class name
  
$app->bind(Foo::class);
  
// or, bind an interface with a desired concrete implementation.
// can be switched out easily on one place in your code.
  
$app->bind('FooInterface', 'Foo');
  
// or, bind an interface or other label to a closure to
// directly control dependency injection.
  
$app->bind('FooInterface', function(){
    return new Foo('123-456-7890');
};
  
// or, use array access to bind a new instance directly.
  
$app['Foo'] = new Foo;
```
### Resolving out of the container
**$instance = resolve($abstract);**  (resolve checks for existing binding before instantiating)  
**$instance = make($abstract);**  (make will bind and instantiate the class if not already)
```php
$foo = $app->resolve('myfoo');
  
// or
  
$foo = $app->make('FooInterface');
  
// or
  
$foo = $app['FooInterface']; 
  
// or
  
$foo = $app->get('Foo');
  
// or if using make and Foo is not yet bound to the container you must supply a valid class name
  
$foo = $app->make('Foo::class');

```
Note: resolve() and get() will throw an exception if the requested binding does not exist.
### Binding an existing instance
**$instance = instance($abstract, $instance)**
```php
$instance = $app->instance('Foo', new Foo);

```  

### Checking if a binding exists
**$bool = has($abstract)**
```php
$bool = $app->has('Foo');

```  

### Get the values of a single binding
**$array = getBinding($abstract)**  // returns an array of the desired bindings' values
```php
$array = $app->getBinding($abstract);

```  

### Getting a list of bindings
**$array = getBindings()**  // returns an array of the abstract name string keys
```php
$array = $app->getBindings();

```  

