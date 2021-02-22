<?php

namespace AwStudio\States\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a transition cannot be made because the current state is final.
 */
class FinalStateException extends InvalidArgumentException
{
    //
}
