<?php

namespace AwStudio\States\Contracts;

use AwStudio\States\State;
use AwStudio\States\States;

interface Stateful
{
    /**
     * Update the current state.
     *
     * @param string $value
     * @param string $type
     *
     * @return void
     */
    public function setState($value, $type = null);

    /**
     * Get the current state.
     *
     * @param string $type
     *
     * @return State
     */
    public function getState($type = null);

    /**
     * States relationship.
     *
     * @return States
     */
    public function states(): States;
}
