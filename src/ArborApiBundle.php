<?php
/*
 * This file is part of the Arbor API Bundle.
 *
 * Copyright 2022-2024 Robert Woodward
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Robwdwd\ArborApiBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Arbor API Symfony Bundle.
 */
class ArborApiBundle extends Bundle
{
    /**
     * Get path for bundle.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
