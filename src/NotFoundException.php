<?php

declare(strict_types=1);

/**
 * Author: Jim Shannon (@jshannon63)
 *
 * @link    https://jimshannon.me
 *
 * License: MIT
 */

namespace Jshannon63\Cobalt;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Raised when a binding cannot be located in the container. Implements
 * PSR-11 NotFoundExceptionInterface so it satisfies "no entry was
 * found for **this** identifier" semantics for PSR-11 consumers.
 */
final class NotFoundException extends Exception implements NotFoundExceptionInterface
{
}
