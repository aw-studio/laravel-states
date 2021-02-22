<?php

namespace Tests;

use AwStudio\States\Contracts\Stateful;
use AwStudio\States\Exceptions\TransitionException;
use AwStudio\States\HasStates;
use AwStudio\States\State;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

class ModelIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../migrations');

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
        });
    }

    public function tearDown(): void
    {
        Schema::drop('bookings');
        parent::tearDown();
    }

    /** @test */
    public function it_has_initial_state()
    {
        $booking = new Booking;
        $this->assertNotNull($booking->state);
        $this->assertInstanceOf(BookingState::class, $booking->state);
        $this->assertSame(BookingState::INITIAL_STATE, $booking->state->current());
    }

    /** @test */
    public function it_executes_transition()
    {
        $booking = Booking::create();
        $booking->state->transition(BookingStateTransition::PAYMENT_PAID);
        $this->assertSame(BookingState::SUCCESSFULL, $booking->state->current());

        $booking = Booking::create();
        $booking->state->transition(BookingStateTransition::PAYMENT_FAILED);
        $this->assertSame(BookingState::FAILED, $booking->state->current());
    }

    /** @test */
    public function it_fails_for_invalid_transition()
    {
        $this->expectException(TransitionException::class);
        $booking = Booking::create();
        $booking->state->transition('foo');
    }

    /** @test */
    public function test_state_getter()
    {
        $booking = Booking::create();
        $booking->state->transition(BookingStateTransition::PAYMENT_PAID);
        $this->assertInstanceOf(State::class, $booking->state);
        $this->assertSame(BookingState::SUCCESSFULL, (string) $booking->state);
    }
}

class BookingStateTransition
{
    const PAYMENT_PAID = 'payment_paid';
    const PAYMENT_FAILED = 'payment_failed';
}

class BookingState extends State
{
    const PENDING = 'pending';
    const FAILED = 'failed';
    const SUCCESSFULL = 'successfull';

    const INITIAL_STATE = self::PENDING;

    public static function config()
    {
        self::set(BookingStateTransition::PAYMENT_PAID)
            ->from(self::PENDING)
            ->to(self::SUCCESSFULL);
        self::set(BookingStateTransition::PAYMENT_FAILED)
            ->from(self::PENDING)
            ->to(self::FAILED);
    }
}

class Booking extends Model implements Stateful
{
    use HasStates;

    public $timestamps = false;

    protected $states = [
        'state' => BookingState::class,
    ];
}