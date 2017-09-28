<?php
/**
 * Author: Jim Shannon (@jshannon63)
 * Date: 9/19/17
 * License: MIT.
 */

namespace Jshannon63\Container;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

class NotFoundException extends Exception implements NotFoundExceptionInterface
{
}