[![Build Status](https://travis-ci.org/jshannon63/container.svg?branch=master)](https://travis-ci.org/jshannon63/container)
[![StyleCI](https://styleci.io/repos/104802764/shield?branch=master)](https://styleci.io/repos/104802764)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)


# Autowired Dependency Injection Container with Reflection Based IoC
  
  __Realized in less than 140 lines of PHP code__
  
  __Well documented source perfect for learning/building__


This DI container class was created during the preparation of a blog article on understanding DI/IoC
Application Containers. This Container::class implements a PSR-11 container interface 
and provides many of the features found in more notable container projects. This container and its 
simplistic code lends itself well to training and use within projects.

This container has the following features:  

1. Single class container implementing the PSR-11 Interface
2. Support for ArrayAccess methods on container bindings.
3. Automatic constructor injection of dependencies.
4. Dependency injection through a bind method Closure.
5. Autowired dependency resolution using Reflection.
6. Supports for full top down inversion of control (IoC).
7. Shared instances (singletons).
8. Abstract class support allows resolving of a specified concrete 
implementation while programing to a bound interface name.
9. Ability to bind existing instances into the container.
10. A self-binding global container instance.

This package also contains a number of tests to show/confirm operation.

## Installation
```
composer require jshannon63/container  
```

## Usage


### Creating the container
```php

$app = new Jshannon63\Container\Container;

```

### Binding into the container
Binding does not intantiate the class. Instantiation is deferred until requested from the container.
The bind method accepts 3 parameters... the abstract name, the concrete implementation name and a 
true or false for defining as a singleton. Notice in all three versions we use different abstract 
names. This is to show that the abstract name is free-form and is used as the "key" for array storage 
of bindings.

**bind($abstract, $concrete=null, $singleton=false)**

```php

$app->bind('Foo::class', 'Foo');
// or
$app->bind('FooInterface', function(){
    return new Foo;
};
// or
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

### Getting a list of bindings
**$array = getBindings()**  // returns an array of the abstract name string keys
```php

$array = $app->getBindings();

```  

