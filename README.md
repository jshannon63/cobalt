[![CI](https://github.com/jshannon63/cobalt/actions/workflows/ci.yml/badge.svg)](https://github.com/jshannon63/cobalt/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/jshannon63/cobalt/branch/master/graph/badge.svg)](https://codecov.io/gh/jshannon63/cobalt)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg?style=flat)](phpstan.neon)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg?style=flat)](composer.json)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/jshannon63/cobalt.svg?style=flat)](https://packagist.org/packages/jshannon63/cobalt)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat)](LICENSE.md)


# Cobalt — An Autowired Dependency Injection Container for PHP

  __Realized in fewer than 200 lines of source.__

  __Well documented, perfect for building/learning.__

  __100% line and method coverage on PHP 8.2, 8.3, and 8.4.__

  __One of the fastest PHP dynamic autowired containers available.__

Cobalt was created to push the performance limits of what a PHP-based dynamic
autowired DI container can achieve. `Container::class` implements the PSR-11
`ContainerInterface` and provides many of the features found in more notable
container projects. Resolution closures are cached after the first build, so
subsequent lookups skip all reflection work. Cobalt and its simple, heavily
commented source are perfect for learning, or for embedding inside projects
and frameworks.

The Cobalt service container has the following features:

1. Single class container implementing the PSR-11 `ContainerInterface` v2.
2. `ArrayAccess` methods for container bindings.
3. Constructor injection of type-hinted dependencies.
4. Dependency injection through `bind()` method closures.
5. Autowired dependency resolution using Reflection.
6. Top-down inversion of control (IoC).
7. Shared mode option (singleton only).
8. Bind existing instances into the container.
9. A self-binding global container instance.


## Installation
```
composer require jshannon63/cobalt
```

## Usage


### Creating the container
```php
use Jshannon63\Cobalt\Container;

// create a default (prototype) container
$app = new Container();

// or, create a singleton-only services container
$app = new Container('shared');
```

### Binding into the container
Binding does not instantiate the class. Instantiation is deferred until the
binding is requested from the container. `bind()` accepts three parameters:
the abstract name, the concrete implementation, and a boolean for whether
the binding is a singleton. The abstract name is free-form and acts as the
key in the binding registry.

**`bind(string $abstract, mixed $concrete = null, bool $singleton = false): void`**

```php
// a simple binding using only the class name
$app->bind(Foo::class);

// or, bind an interface with a desired concrete implementation —
// you can swap the concrete out in one place in your code.
$app->bind(FooInterface::class, Foo::class);

// or, bind an interface or other label to a closure to directly
// control dependency injection.
$app->bind(FooInterface::class, fn () => new Foo('123-456-7890'));

// or, use array access to bind a new instance directly.
$app['Foo'] = new Foo();
```

### Resolving out of the container

**`$instance = resolve(string $id): mixed`** (resolve checks for an existing binding before instantiating)

```php
$foo = $app->resolve(FooInterface::class);

// or
$foo = $app[FooInterface::class];

// or
$foo = $app->get(FooInterface::class);
```

Trying to resolve a missing binding throws `NotFoundException` per PSR-11.

### Using the `make()` method
`make()` is `bind()` then `resolve()` — useful for one-shot instantiation.

**`$instance = make(string $abstract, mixed ...$args): mixed`**

```php
$foo = $app->make(FooInterface::class, Foo::class);
```

### Creating an alias to a binding
**`alias(string $alias, string $binding): void`**

Allows creating additional string IDs for accessing existing container bindings.

```php
$app->alias('myfoo', FooInterface::class);
```

### Binding an existing instance
Pass an object to `bind()` and Cobalt registers it as a singleton automatically
(a pre-built instance is by definition shared).

```php
$app->bind('Foo', new Foo);
```

### Checking if a binding exists
**`$bool = has(string $abstract): bool`**

```php
$bool = $app->has('Foo');
```

### Getting the values of a single binding
**`$array = getBinding(string $abstract): array`**

```php
$array = $app->getBinding($abstract);
```

### Getting a list of bindings
**`$array = getBindings(): array`**

```php
$array = $app->getBindings();
```

## Development

Run the full quality bar locally:

```bash
composer install
composer check       # lint + analyse + test
```

Or individually:

```bash
composer test         # PHPUnit
composer lint         # Laravel Pint (PSR-12 + opinionated)
composer analyse      # PHPStan level 9
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
