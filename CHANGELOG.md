# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] — Unreleased

### Added
- PSR-11 `ContainerInterface` v2 compatibility.
- GitHub Actions matrix workflow covering PHP 8.2, 8.3, and 8.4 (plus a
  `prefer-lowest` cross-check on 8.2).
- Laravel Pint configuration (PSR-12 preset).
- PHPStan level 9 configuration.
- Codecov coverage upload.
- Regression coverage for optional interface and abstract dependencies, union
  and intersection type rejection, and non-string ArrayAccess offsets.

### Changed
- Minimum PHP version is now 8.2.
- Internal parameter resolution rewritten to use `ReflectionParameter::getType()`
  in place of the PHP 8.1-removed `getClass()`.
- Constructor parameters typed as an interface or abstract class with a default
  value now resolve to the default when no concrete is bound. Explicit bindings
  for those types continue to take precedence.
- README badges, examples, and Development section updated to reflect the new
  toolchain.

### Removed
- The deprecated `instance()` method. Use `bind($abstract, $instance)` to
  register an existing object instance.
- Travis CI and StyleCI configuration files.

### Security
- `phpunit/phpunit` pinned to `^11.5.50` to exclude
  [CVE-2026-24765](https://github.com/advisories/GHSA-vvj3-c3rp-c85p) under any
  composer resolution mode, including `--prefer-lowest`.
