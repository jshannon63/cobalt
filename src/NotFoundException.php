<?php
/**
 * Created by PhpStorm.
 * User: jim
 * Date: 9/27/17
 * Time: 9:52 PM
 */

namespace Jshannon63\Container;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

class NotFoundException extends Exception implements NotFoundExceptionInterface
{

}