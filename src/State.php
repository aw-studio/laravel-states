<?php

namespace AwStudio\States;

use AwStudio\States\Contracts\Stateful;
use AwStudio\States\Exceptions\TransitionException;
use Illuminate\Support\Str;

abstract class State
{
    /**
     * Possible transitions.
     *
     * @var array
     */
    protected static $transitions = [];

    /**
     * Stateful instance.
     *
     * @var Stateful
     */
    protected $stateful;

    /**
     * State type.
     *
     * @var string
     */
    protected $type;

    /**
     * Configure the state.
     *
     * @return void
     */
    abstract public static function config();

    /**
     * Allow transition.
     *
     * @param  string     $transition
     * @return Transition
     */
    public static function set($transition)
    {
        $transition = new Transition($transition);

        static::$transitions[] = $transition;

        return $transition;
    }

    /**
     * Get transitions.
     *
     * @return array
     */
    public static function getTransitions()
    {
        if (empty(static::$transitions)) {
            static::config();
        }

        return static::$transitions;
    }

    /**
     * Create new State instance.
     *
     * @param  Stateful $stateful
     * @param  string   $type
     * @return void
     */
    public function __construct(Stateful $stateful, $type)
    {
        $this->stateful = $stateful;
        $this->type = $type;
    }

    /**
     * Get the state type.
     *
     * @return string
     */
    public function getType()
    {
        if (property_exists($this, 'type')) {
            return $this->type;
        }

        return $this->getTypeFromNamespace();
    }

    /**
     * Get type from namespace.
     *
     * @return string
     */
    protected function getTypeFromNamespace()
    {
        return Str::snake(class_basename(static::class));
    }

    /**
     * Determines if a transition can be executed.
     *
     * @param  string $transition
     * @return bool
     */
    public function can($transition)
    {
        return (bool) $this->getCurrentTransition($transition);
    }

    /**
     * Get current transition.
     *
     * @param  string          $transition
     * @return Transition|void
     */
    public function getCurrentTransition($transition)
    {
        foreach ($this->getTransitions() as $t) {
            if ($t->name == $transition && $this->current() == $t->from) {
                return $t;
            }
        }
    }

    /**
     * Execute transition.
     *
     * @param  string $transition
     * @return void
     *
     * @throws TransitionException
     */
    public function transition($transition)
    {
        if (! $this->can($transition)) {
            throw new TransitionException(
                "Transition [{$transition}] to change [{$this->type}] not allowed for [".$this->current().']'
            );
        }

        $transition = $this->getCurrentTransition($transition);

        $state = $this->stateful->states()->makeFromTransition(
            $this->getType(), $transition
        );
        $state->save();

        return $state;
    }

    /**
     * Get current state.
     *
     * @return string
     */
    public function current()
    {
        return $this->stateful->getState(
            $this->getType()
        );
    }

    /**
     * Format to string.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->current();
    }
}
