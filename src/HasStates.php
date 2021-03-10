<?php

namespace AwStudio\States;

use AwStudio\States\Models\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

trait HasStates
{
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
     * @param  string      $type
     * @return string|null
     */
    public function getStateType($type)
    {
        return $this->getStateTypes()[$type] ?? null;
    }

    /**
     * Determine wether a state exists.
     *
     * @param  string $type
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
     * @param  string $value
     * @param  string $type
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
     * @param  string $type
     * @return State
     */
    public function getState($type = 'state')
    {
        $latest = $this->states()
            ->where('type', $type)
            ->orderByDesc('id')
            ->first();

        if (! $latest) {
            return $this->getStateTypes()[$type]::INITIAL_STATE;
        }

        return $latest->state;
    }

    /**
     * Determine if a get mutator exists for an attribute.
     *
     * @param  string $key
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
     * @param  string $key
     * @param  mixed  $value
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
     * @param  string $key
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
     * Get the observable event names.
     *
     * @return array
     */
    public function getObservableEvents()
    {
        return array_merge(
            $this->getObservableStateEvents(),
            parent::getObservableEvents()
        );
    }

    /**
     * Get observable state events.
     *
     * @return array
     */
    public function getObservableStateEvents()
    {
        $events = [];

        foreach ($this->getStateTypes() as $name => $type) {
            foreach ($type::all() as $state) {
                $events[] = $this->getStateEventName($name, $state);
            }
            foreach ($type::uniqueTransitions() as $transition) {
                $events[] = $this->getTransitionEventName($name, $transition);
            }
        }

        return $events;
    }

    /**
     * Get state event name.
     *
     * @param  string $type
     * @param  string $state
     * @return string
     */
    public function getStateEventName($type, $state)
    {
        return Str::camel("{$type}_{$state}");
    }

    /**
     * Get transition event name.
     *
     * @param  string $type
     * @param  string $transition
     * @return string
     */
    public function getTransitionEventName($type, $transition)
    {
        return Str::camel("{$type}_transision_{$transition}");
    }

    /**
     * Fire state event.
     *
     * @param  string $event
     * @return void
     */
    public function fireStateEvent($event)
    {
        $this->fireModelEvent(
            $event, false
        );
    }

    /**
     * `whereState`.
     *
     * @param  Builder $query
     * @param  string  $type
     * @param  string  $value
     * @return void
     */
    public function scopeWhereStateIs($query, $type, $value)
    {
        if ($this->getStateType($type)::INITIAL_STATE == $value) {
            return $query->whereDoesntHave('states');
        }

        $query->whereExists(function ($existsQuery) use ($type, $value) {
            $existsQuery
                ->from((new State)->getTable())
                ->addSelect(["latest_{$type}" => State::select('state')
                ->where('type', 'payment_state')
                ->where('stateful_type', static::class)
                ->whereColumn('stateful_id', 'subscriptions.id')
                ->orderByDesc('id')
                ->take(1),
                ])
                ->having("latest_{$type}", $value);
        });
    }

    /**
     * `whereState`.
     *
     * @param  Builder $query
     * @param  string  $type
     * @param  string  $value
     * @return void
     */
    public function scopeWhereStateIsNot($query, $type, $value)
    {
        if ($this->getStateType($type)::INITIAL_STATE == $value) {
            return $query->whereDoesntHave('states');
        }

        $query->whereNotExists(function ($existsQuery) use ($type, $value) {
            $existsQuery
                ->from((new State)->getTable())
                ->addSelect(["latest_{$type}" => State::select('state')
                ->where('type', 'payment_state')
                ->where('stateful_type', static::class)
                ->whereColumn('stateful_id', 'subscriptions.id')
                ->orderByDesc('id')
                ->take(1),
                ])
                ->having("latest_{$type}", $value);
        });
    }

    // /**
    //  * Apply the given named scope if possible.
    //  *
    //  * @param  string $scope
    //  * @param  array  $parameters
    //  * @return mixed
    //  */
    // public function callNamedScope($scope, array $parameters = [])
    // {
    //     if ($this->hasStateScope($scope)) {
    //         return $this->callNamedStateScope($scope, $parameters);
    //     }

    //     return $this->{'scope'.ucfirst($scope)}(...$parameters);
    // }

    // public function callNamedStateScope($scope, array $parameters = [])
    // {
    //     [$type, $state] = $this->getTypeAndStateFromScope($scope);

    //     $builder = array_shift($parameters);
    //     $builder->where($type, $state);
    // }

    // /**
    //  * Determine if the model has a given scope.
    //  *
    //  * @param  string $scope
    //  * @return bool
    //  */
    // public function hasNamedScope($scope)
    // {
    //     if ($this->hasStateScope($scope)) {
    //         return true;
    //     }

    //     return parent::hasNamesScope($scope);
    // }

    // public function getTypeAndStateFromScope($scope)
    // {
    //     foreach ($this->states as $state => $type) {
    //         foreach ($type::all() as $value) {
    //             $method = 'where'.ucfirst(Str::camel($state)).ucfirst(Str::camel($value));

    //             if ($method == $scope) {
    //                 return [$state, $value];
    //             }
    //         }
    //     }

    //     return [null, null];
    // }

    // public function hasStateScope($scope)
    // {
    //     [$type, $state] = $this->getTypeAndStateFromScope($scope);

    //     return (bool) $type;
    // }
}
