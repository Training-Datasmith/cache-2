<?php

declare (strict_types=1);
namespace Doctrine\Common\Cache\Psr6;

use InvalidArgumentException;
use Psr\Cache\InvalidArgumentException as PsrInvalidArgumentException;
/**
 * @internal
 */
final class Invalid_Argument extends InvalidArgumentException implements Psr_Invalid_Argument_Exception
{
}