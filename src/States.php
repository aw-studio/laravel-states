<?php

namespace AwStudio\States;

use AwStudio\States\Models\State as StateModel;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class States extends MorphMany
{
    /**
     * Make transition.
     *
     * @param  string  $type
     * @param  string  $transition
     * @return StateModel
     */
    public function transition($type, $transition)
    {
        if (! $type = $this->parent->getStateType($type)) {
            return;
        }

        if (! $this->parent->{$type} instanceof State) {
            return;
        }

        return $this->parent->{$type}->executeTransition($transition);
    }

    /**
     * Make state from transition.
     *
     * @param  string  $type
     * @param  Transition  $transition
     * @param  string|null  $reason
     * @return StateModel
     */
    public function makeFromTransition($type, Transition $transition, $reason = null)
    {
        return $this->make([
            'transition' => $transition->name,
            'from'       => $transition->from,
            'state'      => $transition->to,
            'type'       => $type,
            'reason'     => $reason,
        ]);
    }
}
