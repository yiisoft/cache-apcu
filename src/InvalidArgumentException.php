<?php

declare(strict_types=1);

namespace Yiisoft\Cache\Apcu;

use RuntimeException;

final class InvalidArgumentException extends RuntimeException implements \Psr\SimpleCache\InvalidArgumentException {}
