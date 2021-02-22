<?php

namespace AwStudio\States;

use AwStudio\States\Models\State;

trait HasStates
{
    /**
     * Initialize has states.
     *
     * @return void
     */
    public function initializeHasStates()
    {
        foreach ($this->casts as $attribute => $cast) {
            if (! is_subclass_of($cast, State::class)) {
                continue;
            }

            $this->setAttribute($attribute, $cast::INITIAL_STATE);
        }
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
     * @param  string      $type
     * @return string|null
     */
    public function getStateType($type)
    {
        return $this->getStateTypes()[$type] ?? null;
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
            ->latest()
            ->first();

        if (! $latest) {
            return $this->getStateTypes()[$type]::INITIAL_STATE;
        }

        return $latest->state;
    }

    /**
     * Get attribute.
     *
     * @param  string $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if ($type = $this->getStateType($key)) {
            return new $type($this, $key);
        }

        return parent::getAttribute($key);
    }
}
