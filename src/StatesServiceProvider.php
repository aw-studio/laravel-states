<?php

namespace AwStudio\States;

use Illuminate\Support\ServiceProvider;

class StatesServiceProvider extends ServiceProvider
{
    /**
     * Boot application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../migrations' => database_path('migrations'),
        ], 'states:migrations');
    }
}
