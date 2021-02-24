<?php

namespace Tests;

use AwStudio\States\State;
use PHPUnit\Framework\TestCase;

class StateTest extends TestCase
{
    public function test_all_method()
    {
        $this->assertEquals([
            'foo', 'bar',
        ], DummyState::all());
    }

    public function test_uniqueTransitions_method()
    {
        $this->assertEquals([
            'hello_world',
        ], DummyState::uniqueTransitions());
    }
}

class DummyState extends State
{
    const FOO = 'foo';
    const BAR = 'bar';

    const INITIAL_STATE = self::FOO;
    const FINAL_STATES = [self::BAR];

    public static function config()
    {
        self::set('hello_world')->from(self::FOO)->to(self::BAR);
    }
}
