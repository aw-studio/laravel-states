<?php

namespace AwStudio\States;

use AwStudio\States\Contracts\Stateful;
use AwStudio\States\Exceptions\TransitionException;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
     *dd($item->ticket_state->current());.
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
     * Get states from where the given transition can be executed.
     *
     * @param  string $transition
     * @return array
     */
    public static function whereCan($transition)
    {
        return collect(static::all())
            ->filter(function ($state) use ($transition) {
                return static::canTransitionFrom($state, $transition);
            })
            ->values()
            ->toArray();
    }

    /**
     * Determines wether the given transition can be executed for the given
     * state.
     *
     * @param  string $state
     * @param  string $transition
     * @return bool
     */
    public static function canTransitionFrom($state, $transition)
    {
        return (bool) collect(static::getTransitions())
            ->first(function (Transition $t) use ($state, $transition) {
                return $t->from == $state
                    && $t->name == $transition;
            });
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
     * Determines wether a transition exists.
     *
     * @param  string $transition
     * @return bool
     */
    public function transitionExists($transition)
    {
        foreach ($this->getTransitions() as $t) {
            if ($t->name == $transition) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lock the current state for update.
     *
     * @return $this
     */
    public function lockForUpdate()
    {
        $this->stateful
            ->states()
            ->where('type', $this->type)
            ->lockForUpdate()
            ->count();

        return $this;
    }

    /**
     * Execute transition.
     *
     * @param  string $transition
     * @param  string $fail
     * @param  string $reason
     * @return void
     *
     * @throws TransitionException
     */
    public function transition($name, $fail = true, $reason = null)
    {
        [$state, $transition] = DB::transaction(function () use ($name, $fail, $reason) {
            $this->reload()->lockForUpdate();
            if (! $this->can($name)) {
                if ($this->transitionExists($name)) {
                    Log::warning('Unallowed transition.', [
                        'transition' => $name,
                        'type'       => $this->type,
                        'current'    => $this->current(),
                    ]);
                }

                if (! $fail) {
                    return;
                }

                throw new TransitionException(
                    "Transition [{$name}] to change [{$this->type}] not allowed for [".$this->current().']'
                );
            }

            $transition = $this->getCurrentTransition($name);

            $state = $this->stateful->states()->makeFromTransition(
                $this->getType(),
                $transition,
                $reason
            );
            $state->save();

            return [$state, $transition];
        }, 5);

        if (! $transition) {
            return;
        }

        $this->stateful->setRelation(
            $this->stateful->getCurrentStateRelationName($this->getType()),
            $state
        );

        $this->stateful->fireStateEventsFor($this->getType(), $transition);

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
     * Determine wether state was the given state.
     *
     * @return bool
     */
    public function was($state)
    {
        if ($state == static::INITIAL_STATE) {
            return true;
        }

        return $this->stateful
            ->states($this->getType())
            ->where('state', $state)
            ->exists();
    }

    /**
     * Determine if the current state is the given state.
     *
     * @param  string|array $state
     * @return bool
     */
    public function is($state)
    {
        return in_array($this->current(), Arr::wrap($state));
    }

    /**
     * Determine if the current state is any of the given states.
     *
     * @param  array $states
     * @return bool
     */
    public function isAnyOf($states)
    {
        return in_array($this->current(), $states);
    }

    /**
     * Reload the state.
     *
     * @return $this
     */
    public function reload()
    {
        $this->stateful->reloadState($this->getType());

        return $this;
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
