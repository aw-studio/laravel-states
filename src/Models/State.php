<?php

namespace AwStudio\States\Models;

use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    /**
     * Fillable attributes.
     *
     * @var array
     */
    protected $fillable = [
        'stateful_type', 'stateful_id', 'transition', 'from', 'state', 'type',
    ];
}
