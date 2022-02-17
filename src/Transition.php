<?php

namespace AwStudio\States;

class Transition
{
    /**
     * Transition name.
     *
     * @var string
     */
    public $name;

    /**
     * From state.
     *
     * @var string
     */
    public $from;

    /**
     * To state.
     *
     * @var string
     */
    public $to;

    /**
     * Create new Transition instance.
     *
     * @param  string  $transition
     * @return void
     */
    public function __construct($transition)
    {
        $this->name = $transition;
    }

    /**
     * Set from state.
     *
     * @param  string  $state
     * @return $this
     */
    public function from($state)
    {
        $this->from = $state;

        return $this;
    }

    /**
     * Set to state.
     *
     * @param  string  $state
     * @return $this
     */
    public function to($state)
    {
        $this->to = $state;

        return $this;
    }
}
