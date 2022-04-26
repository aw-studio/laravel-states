<?php

namespace AwStudio\States;

use AwStudio\States\Models\State;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;

trait HasStates
{
    /**
     * The array of initialized models.
     *
     * @var array
     */
    protected static $initialized = [];

    protected $observableStateEvents = [
        'changed' => 'changed',
    ];

    /**
     * Initialize HasStates trait.
     *
     * @return void
     */
    public function initializeHasStates()
    {
        if (! isset(static::$initialized[static::class])) {
            static::$initialized[static::class] = true;

            foreach ($this->states as $type => $state) {
                static::resolveRelationUsing(
                    $this->getCurrentStateRelationName($type),
                    function (Model $stateful) use ($type) {
                        return $this->belongsTo(State::class, "current_{$type}_id");
                    }
                );
            }
        }
    }

    /**
     * Get current state relation name.
     *
     * @param string $type
     *
     * @return string
     */
    public function getCurrentStateRelationName($type = 'state')
    {
        return "current_{$type}";
    }

    /**
     * Get states.
     *
     * @return array
     */
    public function getStateTypes()
    {
        return $this->states;
    }

    /**
     * Get state type.
     *
     * @param string $type
     *
     * @return string|null
     */
    public function getStateType($type)
    {
        return $this->getStateTypes()[$type] ?? null;
    }

    /**
     * Determine wether a state exists.
     *
     * @param string $type
     *
     * @return bool
     */
    public function hasState($type)
    {
        return array_key_exists($type, $this->states);
    }

    /**
     * States relationship.
     *
     * @return States
     */
    public function states($type = null): States
    {
        $morphMany = $this->morphMany(State::class, 'stateful');

        $states = new States(
            $morphMany->getQuery(),
            $morphMany->getParent(),
            $morphMany->getQualifiedMorphType(),
            $morphMany->getQualifiedForeignKeyName(),
            $morphMany->getLocalKeyName()
        );

        if ($type) {
            $states->where('type', $type);
        }

        return $states;
    }

    /**
     * Update the current state.
     *
     * @param string $value
     * @param string $type
     *
     * @return void
     */
    public function setState($value, $type = 'state')
    {
        $this->states()->make([
            'from'  => $this->getState($type),
            'state' => $value,
            'type'  => $type,
        ])->save();
    }

    /**
     * Get the current state.
     *
     * @param string $type
     *
     * @return State
     */
    public function getState($type = 'state')
    {
        $relation = $this->getCurrentStateRelationName($type);

        if (! $this->relationLoaded($relation)) {
            $this->loadCurrentState($type);
        }

        $latest = $this->getRelation($relation);

        if (! $latest) {
            return $this->getStateTypes()[$type]::INITIAL_STATE;
        }

        return $latest->state;
    }

    /**
     * Determine if a get mutator exists for an attribute.
     *
     * @param string $key
     *
     * @return bool
     */
    public function hasGetMutator($key)
    {
        if ($this->hasState($key)) {
            return true;
        }

        return parent::hasGetMutator($key);
    }

    /**
     * Get the value of an attribute using its mutator.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return mixed
     */
    public function mutateAttribute($key, $value)
    {
        if ($this->hasState($key)) {
            return $this->mutatStateAttribute($key);
        }

        return parent::mutateAttribute($key, $value);
    }

    /**
     * Mutate state attribute.
     *
     * @param string $key
     *
     * @return State
     */
    public function mutatStateAttribute($key)
    {
        $type = $this->getStateType($key);

        $state = new $type($this, $key);

        if (parent::hasGetMutator($key)) {
            $state = parent::mutateAttribute($key, $state);
        }

        return $state;
    }

    /**
     * Register a single observer with the model.
     *
     * @param object|string $class
     *
     * @throws \RuntimeException
     *
     * @return void
     */
    protected function registerObserver($class)
    {
        parent::registerObserver($class);
        $className = static::resolveObserverClassName($class);
        $reflector = new ReflectionClass($className);

        foreach ($reflector->getMethods() as $method) {
            foreach ($this->getStateTypes() as $type => $stateClass) {
                foreach ($this->observableStateEvents as $event => $methodPrefix) {
                    $methodName = $methodPrefix.ucfirst(Str::camel($type));
                    if ($method->getName() != $methodName) {
                        continue;
                    }

                    static::registerModelEvent(
                        "{$event}.{$type}",
                        $className.'@'.$method->getName()
                    );
                }
                foreach ($stateClass::all() as $state) {
                    if (! $this->watchesObserverMethodState($method->getName(), $type, $state)) {
                        continue;
                    }

                    static::registerModelEvent(
                        $this->getStateEventName($type, $state),
                        $className.'@'.$method->getName()
                    );
                }
                foreach ($stateClass::uniqueTransitions() as $transition) {
                    if (! $this->watchesObserverMethodStateTransition($method->getName(), $type, $transition)) {
                        continue;
                    }

                    static::registerModelEvent(
                        $this->getTransitionEventName($type, $transition),
                        $className.'@'.$method->getName()
                    );
                }
            }
        }
    }

    /**
     * Resolve the observer's class name from an object or string.
     *
     * @param  object|string $class
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    protected static function resolveObserverClassName($class)
    {
        if (is_object($class)) {
            return get_class($class);
        }

        if (class_exists($class)) {
            return $class;
        }

        throw new InvalidArgumentException('Unable to find observer: '.$class);
    }

    /**
     * Determines wether an observer method watches the given state transition.
     *
     * @param string $method
     * @param string $type
     * @param string $transition
     *
     * @return bool
     */
    protected function watchesObserverMethodStateTransition($method, $type, $transition)
    {
        $start = Str::camel($type).'Transition';

        if (! Str::startsWith($method, $start)) {
            return false;
        }

        $following = str_replace($start, '', $method);

        return $following == ucfirst(Str::camel($transition));
    }

    /**
     * Determines wether an observer method watches the given state.
     *
     * @param string $method
     * @param string $type
     * @param string $state
     *
     * @return bool
     */
    protected function watchesObserverMethodState($method, $type, $state)
    {
        if (! Str::startsWith($method, Str::camel($type))) {
            return false;
        }

        $following = str_replace(Str::camel($type), '', $method);

        if ($following == ucfirst(Str::camel($state))) {
            return true;
        }

        if (! str_contains($method, 'Or')) {
            return false;
        }

        foreach (explode('Or', $following) as $key) {
            if ($key == $state) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get state event name.
     *
     * @param string $type
     * @param string $state
     *
     * @return string
     */
    public function getStateEventName($type, $state)
    {
        return "{$type}.{$state}";
    }

    /**
     * Get state event name.
     *
     * @param string $type
     * @param string $state
     *
     * @return string
     */
    public function getStateEventMethod($type, $state)
    {
        return Str::camel("{$type}_{$state}");
    }

    /**
     * Get transition event name.
     *
     * @param string $type
     * @param string $transition
     *
     * @return string
     */
    public function getTransitionEventName($type, $transition)
    {
        return "{$type}.transition.{$transition}";
    }

    /**
     * Get transition event name.
     *
     * @param string $type
     * @param string $transition
     *
     * @return string
     */
    public function getTransitionEventMethod($type, $transition)
    {
        return Str::camel("{$type}_transition_{$transition}");
    }

    /**
     * Fire state events for the given transition.
     *
     * @param string     $type
     * @param Transition $transition
     *
     * @return void
     */
    public function fireStateEventsFor($type, Transition $transition)
    {
        $this->fireModelEvent($this->getTransitionEventName($type, $transition->name));
        $this->fireModelEvent($this->getStateEventName($type, $transition->to));
        $this->fireModelEvent("changed.{$type}");
    }

    /**
     * `whereDoesntHaveStates` query scope.
     *
     * @param string $type
     *
     * @return void
     */
    public function scopeWhereDoesntHaveStates($query, $type = 'state')
    {
        $query->whereDoesntHave('states', function ($statesQuery) use ($type) {
            $statesQuery->where('type', $type);
        });
    }

    /**
     * `whereDoesntHaveStates` query scope.
     *
     * @param string $type
     *
     * @return void
     */
    public function scopeOrWhereDoesntHaveStates($query, $type = 'state')
    {
        $query->orWhereDoesntHave('states', function ($statesQuery) use ($type) {
            $statesQuery->where('type', $type);
        });
    }

    /**
     * `whereStateWas` query scope.
     *
     * @param Builder $query
     * @param string  $type
     * @param string  $value
     *
     * @return void
     */
    public function scopeWhereStateWas($query, $type, $value)
    {
        if ($this->getStateType($type)::INITIAL_STATE == $value) {
            return;
        }

        $query->whereHas('states', function ($statesQuery) use ($type, $value) {
            $statesQuery
                ->where('type', $type)
                ->where('state', $value);
        });
    }

    /**
     * `whereNotHaveWasNot` query scope.
     *
     * @param Builder $query
     * @param string  $type
     * @param string  $value
     *
     * @return void
     */
    public function scopeWhereNotHaveWasNot($query, $type, $value)
    {
        $query->whereDoesntHave('states', function ($statesQuery) use ($type, $value) {
            $statesQuery
                ->where('type', $type)
                ->where('state', $value);
        });
    }

    /**
     * `whereStateIs` query scope.
     *
     * @param Builder $query
     * @param string  $type
     * @param string  $value
     *
     * @return void
     */
    public function scopeWhereStateIs($query, $type, $value)
    {
        if ($this->getStateType($type)::INITIAL_STATE == $value) {
            return $query->whereDoesntHaveStates($type);
        }

        $query->whereExists(function ($existsQuery) use ($type, $value) {
            $existsQuery
                ->from(DB::raw((new State())->getTable().' as _s'))
                ->where('type', $type)
                ->where('stateful_type', static::class)
                ->whereColumn('stateful_id', $this->getTable().'.id')
                ->where('state', $value)
                ->whereNotExists(function ($notExistsQuery) use ($type) {
                    $notExistsQuery->from('states')
                        ->where('type', $type)
                        ->where('stateful_type', static::class)
                        ->whereColumn('stateful_id', $this->getTable().'.id')
                        ->whereColumn('id', '>', '_s.id');
                });
        });
    }

    /**
     * `orWhereStateIs` query scope.
     *
     * @param Builder $query
     * @param string  $type
     * @param string  $value
     *
     * @return void
     */
    public function scopeOrWhereStateIs($query, $type, $value)
    {
        if ($this->getStateType($type)::INITIAL_STATE == $value) {
            return $query->orWhereDoesntHaveStates($type);
        }

        $query->orWhereExists(function ($existsQuery) use ($type, $value) {
            $existsQuery
                ->from(DB::raw((new State())->getTable().' as _s'))
                ->where('type', $type)
                ->where('stateful_type', static::class)
                ->whereColumn('stateful_id', $this->getTable().'.id')
                ->where('state', $value)
                ->whereNotExists(function ($notExistsQuery) use ($type) {
                    $notExistsQuery->from('states')
                        ->where('type', $type)
                        ->where('stateful_type', static::class)
                        ->whereColumn('stateful_id', $this->getTable().'.id')
                        ->whereColumn('id', '>', '_s.id');
                });
        });
    }

    /**
     * `whereStateIn` query scope.
     *
     * @param Builder $query
     * @param string  $type
     * @param array   $value
     *
     * @return void
     */
    public function scopeWhereStateIsIn($query, $type, array $value)
    {
        if ($this->getStateType($type)::INITIAL_STATE == $value) {
            return $query->whereDoesntHaveStates($type);
        }

        $query->whereExists(function ($existsQuery) use ($type, $value) {
            $existsQuery
                ->from(DB::raw((new State())->getTable().' as _s'))
                ->where('type', $type)
                ->where('stateful_type', static::class)
                ->whereColumn('stateful_id', $this->getTable().'.id')
                ->whereIn('state', $value)
                ->whereNotExists(function ($notExistsQuery) use ($type) {
                    $notExistsQuery->from('states')
                        ->where('type', $type)
                        ->where('stateful_type', static::class)
                        ->whereColumn('stateful_id', $this->getTable().'.id')
                        ->whereColumn('id', '>', '_s.id');
                });
        });
    }

    /**
     * `whereStateIsNot` query scope.
     *
     * @param Builder $query
     * @param string  $type
     * @param string  $value
     *
     * @return void
     */
    public function scopeWhereStateIsNot($query, $type, $value)
    {
        $query->where(function ($query) use ($type, $value) {
            $query->whereExists(function ($existsQuery) use ($type, $value) {
                $existsQuery
                    ->from(DB::raw((new State())->getTable().' as _s'))
                    ->where('type', $type)
                    ->where('stateful_type', static::class)
                    ->whereColumn('stateful_id', $this->getTable().'.id')
                    ->where('state', '!=', $value)
                    ->whereNotExists(function ($notExistsQuery) use ($type) {
                        $notExistsQuery->from('states')
                            ->where('type', $type)
                            ->where('stateful_type', static::class)
                            ->whereColumn('stateful_id', $this->getTable().'.id')
                            ->whereColumn('id', '>', '_s.id');
                    });
            });

            if (! in_array($this->getStateType($type)::INITIAL_STATE, Arr::wrap($value))) {
                return $query->orWhereDoesntHaveStates($type);
            }
        });
    }

    /**
     * `whereStateIsNot` query scope.
     *
     * @param Builder $query
     * @param string  $type
     * @param array   $value
     *
     * @return void
     */
    public function scopeWhereStateIsNotIn($query, $type, array $value)
    {
        $query->where(function ($query) use ($type, $value) {
            $query->whereExists(function ($existsQuery) use ($type, $value) {
                $existsQuery
                    ->from(DB::raw((new State())->getTable().' as _s'))
                    ->where('type', $type)
                    ->where('stateful_type', static::class)
                    ->whereColumn('stateful_id', $this->getTable().'.id')
                    ->whereNotIn('state', $value)
                    ->whereNotExists(function ($notExistsQuery) use ($type) {
                        $notExistsQuery->from('states')
                            ->where('type', $type)
                            ->where('stateful_type', static::class)
                            ->whereColumn('stateful_id', $this->getTable().'.id')
                            ->whereColumn('id', '>', '_s.id');
                    });
            });

            if (! in_array($this->getStateType($type)::INITIAL_STATE, Arr::wrap($value))) {
                return $query->orWhereDoesntHaveStates($type);
            }
        });
    }

    /**
     * `orWhereStateIsNot` query scope.
     *
     * @param Builder      $query
     * @param string       $type
     * @param string|array $value
     *
     * @return void
     */
    public function scopeOrWhereStateIsNot($query, $type, $value)
    {
        $query->orWhere(function ($query) use ($type, $value) {
            $query->whereExists(function ($existsQuery) use ($type, $value) {
                $existsQuery
                    ->from(DB::raw((new State())->getTable().' as _s'))
                    ->where('type', $type)
                    ->where('stateful_type', static::class)
                    ->whereColumn('stateful_id', $this->getTable().'.id')
                    ->where('state', '!=', $value)
                    ->whereNotExists(function ($notExistsQuery) use ($type) {
                        $notExistsQuery->from('states')
                            ->where('type', $type)
                            ->where('stateful_type', static::class)
                            ->whereColumn('stateful_id', $this->getTable().'.id')
                            ->whereColumn('id', '>', '_s.id');
                    });
            });

            if (! in_array($this->getStateType($type)::INITIAL_STATE, Arr::wrap($value))) {
                return $query->orWhereDoesntHaveStates($type);
            }
        });
    }

    /**
     * `addCurrentStateSelect` query scope.
     *
     * @param Builder $query
     * @param string  $type
     * @param string  $select
     *
     * @return void
     */
    public function scopeAddCurrentStateSelect($query, $type = 'state', $select = 'state', Closure $closure = null)
    {
        $query->addSelect(["current_{$type}_{$select}" => State::select($select)
            ->where('type', $type)
            ->where('stateful_type', static::class)
            ->whereColumn('stateful_id', $this->getTable().'.id')
            ->orderByDesc('id')
            ->take(1),
        ]);
    }

    /**
     * `withCurrentState` query scope.
     *
     * @param Builder $query
     * @param string  $type
     *
     * @return void
     */
    public function scopeWithCurrentState($query, $type = 'state')
    {
        $query->addCurrentStateSelect($type, 'id')
            ->with($this->getCurrentStateRelationName($type));
    }

    /**
     * Load state relation of the given type.
     *
     * @param string $type
     *
     * @return $this
     */
    public function loadCurrentState($type = 'state')
    {
        $currentState = $this->states()
            ->where('type', $type)
            ->orderByDesc('id')
            ->first();

        $this->setRelation(
            $this->getCurrentStateRelationName($type),
            $currentState
        );

        return $this;
    }

    /**
     * Reload current state relation of the given type.
     *
     * @param string $type
     *
     * @return $this
     */
    public function reloadState($type = 'state')
    {
        $this->loadCurrentState($type);

        return $this;
    }
}
