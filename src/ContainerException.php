<?php

declare(strict_types=1);

/**
 * Author: Jim Shannon (@jshannon63)
 *
 * @link    mailto:jim@hltky.com
 *
 * License: MIT
 */

namespace Jshannon63\Cobalt;

use Exception;
use Psr\Container\ContainerExceptionInterface;

/**
 * Raised for container resolution failures — binding a non-instantiable
 * class, autowiring an un-resolvable parameter, etc. Implements the
 * PSR-11 ContainerExceptionInterface so callers can catch any
 * container error uniformly.
 */
final class ContainerException extends Exception implements ContainerExceptionInterface
{
}
