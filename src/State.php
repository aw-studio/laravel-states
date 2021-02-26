<?php

namespace AwStudio\States;

use AwStudio\States\Contracts\Stateful;
use AwStudio\States\Exceptions\TransitionException;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ReflectionClass;

abstract class State implements Jsonable
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
     * @var Stateful|Model
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
     * Returns an array of all types.
     *
     * @return array
     */
    public static function all()
    {
        $reflector = new ReflectionClass(static::class);

        return collect($reflector->getConstants())
            ->filter(fn ($value, $key) => ! in_array($key, [
                'INITIAL_STATE', 'FINAL_STATES',
            ]))
            ->values()
            ->toArray();
    }

    /**
     * Returns an array fo all transitions.
     *
     * @return array
     */
    public static function uniqueTransitions()
    {
        $transitions = [];

        foreach (static::getTransitions() as $transition) {
            if (! in_array($transition->name, $transitions)) {
                $transitions[] = $transition->name;
            }
        }

        return $transitions;
    }

    /**
     * Allow transition.
     *
     * @param  string     $transition
     * @return Transition
     */
    public static function set($transition)
    {
        if (! array_key_exists(static::class, static::$transitions)) {
            static::$transitions[static::class] = [];
        }

        $transition = new Transition($transition);

        static::$transitions[static::class][] = $transition;

        return $transition;
    }

    /**
     * Get transitions.
     *
     * @return array
     */
    public static function getTransitions()
    {
        if (! array_key_exists(static::class, static::$transitions)) {
            static::config();
        }

        return static::$transitions[static::class];
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
     * @param  string $fail
     * @return void
     *
     * @throws TransitionException
     */
    public function transition($transition, $fail = true)
    {
        if (! $this->can($transition)) {
            Log::warning('Unallowed transition.', [
                'transition' => $transition,
                'type'       => $this->type,
                'current'    => $this->current(),
            ]);

            if (! $fail) {
                return;
            }

            throw new TransitionException(
                "Transition [{$transition}] to change [{$this->type}] not allowed for [".$this->current().']'
            );
        }

        $transition = $this->getCurrentTransition($transition);

        $this->stateful->fireStateEvent($this->stateful->getTransitionEventName(
            $this->getType(), $transition->name
        ));

        $state = $this->stateful->states()->makeFromTransition(
            $this->getType(), $transition
        );
        $state->save();

        $this->stateful->fireStateEvent($this->stateful->getStateEventName(
            $this->getType(), $transition->to
        ));

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
     * Determine if the current state is the given state.
     *
     * @param  string $state
     * @return bool
     */
    public function is($state)
    {
        return $this->current() == $state;
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

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int    $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->current(), $options);
    }
}
