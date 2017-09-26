[![Build Status](https://travis-ci.org/jshannon63/container.svg?branch=master)](https://travis-ci.org/jshannon63/container)
[![StyleCI](https://styleci.io/repos/104802764/shield?branch=master)](https://styleci.io/repos/104802764)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)


# Dependency Injection Container with Recursive Reflection 

This container class was created during the preparation of a blog article to help users understand the 
Laravel Service Container. The Container::class used in this exercise implements a PSR-11 container interface 
and provides some of the basic features found in the Laravel 5 Service Container. This project is not 
nearly as sophisticated as Taylor Otwell's Illuminate container, however the simplistic code lends itself well to a 
screencast presentation on understanding his work.

This container has the following features:  

1. Single class container implementing the PSR-11 Interface
2. Support for ArrayAccess methods on the container bindings.
3. Dependency injection through a bind method Closure.
5. Recursive dependency resolution of typehinted classes using Reflection.
6. Support for shared instances (singletons).
7. Abstract name support allows resolving of a specified concrete 
implementation while programing to a bound interface name.
8. Ability to bind existing instances into the container.
9. A self-binding global container instance.

This package also contains a number of tests to show/confirm operation using the class.

## Installation

composer require jshannon63\container  

(NOTE: This package is not meant to be loaded into an existing framework)  

## Usage


#### Creating the container
```php

$app = new Container;

```

#### Binding into the container
Binding does not intantiate the class. The bind method accepts 3 parameters... 
the abstract name, the concrete implementation and a true or false for defining as a singleton.

**bind($abstract,$concrete,$singleton)**

```php
$app->bind('FooInterface', 'Foo');
// or
$app['FooInterface'] = new Foo;

```
#### Resolving out of the container
**$instance = resolve($abstract);**  (resolve checks for existing binding before instantiating)  
**$instance = make($abstract);**  (make will bind class as singleton if not already bound)
```php
$foo = $app->resolve('myfoo');
// or
$foo = $app['FooInterface']; 
// or
$foo = $app->make('FooInterface);
```

#### Binding an existing instance
**$instance = instance($abstract, $instance)**
```php

$instance = instance('Foo', new Foo);

```  

#### Checking if a binding exists
**$bool = has($abstract)**
```php

$bool = has('Foo');

```  

#### Getting a list of bindings
**$array = getBindings()**
```php

$array = getBindings();

```  

