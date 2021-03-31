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

    /** @test */
    public function test_state_was_method()
    {
        $booking = Booking::create();
        $booking->state->transition(BookingStateTransition::PAYMENT_PAID);
        $this->assertTrue($booking->state->was(BookingState::SUCCESSFULL));
        $this->assertFalse($booking->state->was(BookingState::FAILED));
    }

    /** @test */
    public function test_state_was_method_for_initial_state()
    {
        $booking = Booking::create();
        $this->assertTrue($booking->state->was(BookingState::INITIAL_STATE));
        $this->assertFalse($booking->state->was(BookingState::SUCCESSFULL));
        $this->assertFalse($booking->state->was(BookingState::FAILED));
    }

    /** @test */
    public function test_withCurrentState_method()
    {
        $booking = Booking::create();
        $state = $booking->states()->create([
            'type'       => 'state',
            'state'      => 'foo',
            'from'       => 'bar',
            'transition' => 'bar_foo',
        ]);

        $booking = Booking::withCurrentState()->first();
        $this->assertTrue($booking->relationLoaded('current_state'));
        $this->assertSame($state->id, $booking->getRelation('current_state')->id);
        $this->assertSame('foo', $booking->getRelation('current_state')->state);
    }

    /** @test */
    public function test_loadCurrentState_method()
    {
        $booking = Booking::create();
        $state = $booking->states()->create([
            'type'       => 'state',
            'state'      => 'foo',
            'from'       => 'bar',
            'transition' => 'bar_foo',
        ]);

        $this->assertFalse($booking->relationLoaded('current_state'));
        $booking->loadCurrentState();
        $this->assertTrue($booking->relationLoaded('current_state'));
        $this->assertSame($state->id, $booking->getRelation('current_state')->id);
        $this->assertSame('foo', $booking->getRelation('current_state')->state);
    }

    /** @test */
    public function test_getCurrentStateRelationName_method()
    {
        $booking = new Booking;
        $this->assertSame('current_state', $booking->getCurrentStateRelationName());
        $this->assertSame('current_state', $booking->getCurrentStateRelationName('state'));
        $this->assertSame('current_payment_state', $booking->getCurrentStateRelationName('payment_state'));
    }

    /** @test */
    public function test_whereStateIs_query_scope()
    {
        $booking1 = Booking::create();
        $booking2 = Booking::create();
        $booking3 = Booking::create();
        $booking1->state->transition(BookingStateTransition::PAYMENT_PAID);

        $this->assertSame(2, Booking::whereStateIs('state', BookingState::INITIAL_STATE)->count());
        $this->assertSame(0, Booking::whereStateIs('state', BookingState::FAILED)->count());
        $this->assertSame(1, Booking::whereStateIs('state', BookingState::SUCCESSFULL)->count());
        $this->assertSame($booking1->id, Booking::whereStateIs('state', BookingState::SUCCESSFULL)->first()->id);
    }

    /** @test */
    public function test_OrWhereStateIs_query_scope()
    {
        $booking1 = Booking::create();
        $booking2 = Booking::create();
        $booking3 = Booking::create();
        $booking1->state->transition(BookingStateTransition::PAYMENT_PAID);

        $this->assertSame(2, Booking::where('id', $booking2->id)->OrWhereStateIs('state', BookingState::SUCCESSFULL)->count());
    }

    /** @test */
    public function test_whereStateIsNot_query_scope()
    {
        $booking1 = Booking::create();
        $booking2 = Booking::create();
        $booking3 = Booking::create();
        $booking1->state->transition(BookingStateTransition::PAYMENT_PAID);

        // dd(
        // $booking1->state->current(),
        // $booking2->state->current(),
        // $booking3->state->current(),
        // Booking::whereStateIsNot('state', BookingState::INITIAL_STATE)->count()
        // );

        $this->assertSame(3, Booking::whereStateIsNot('state', BookingState::FAILED)->count());
        $this->assertSame(2, Booking::whereStateIsNot('state', BookingState::SUCCESSFULL)->count());
        $this->assertSame(0, Booking::whereStateIsNot('state', [BookingState::SUCCESSFULL, BookingState::INITIAL_STATE])->count());
        $this->assertSame(2, Booking::whereStateIsNot('state', [BookingState::SUCCESSFULL, BookingState::FAILED])->count());
        $this->assertSame(1, Booking::whereStateIsNot('state', BookingState::INITIAL_STATE)->count());
        $this->assertSame($booking1->id, Booking::whereStateIsNot('state', BookingState::INITIAL_STATE)->first()->id);
    }

    /** @test */
    public function test_orWhereStateIsNot_query_scope()
    {
        $booking1 = Booking::create();
        $booking2 = Booking::create();
        $booking3 = Booking::create();
        $booking1->state->transition(BookingStateTransition::PAYMENT_PAID);

        $this->assertSame(1, Booking::where('id', $booking1->id)->OrWhereStateIsNot('state', BookingState::INITIAL_STATE)->count());
        $this->assertSame(2, Booking::where('id', $booking2->id)->OrWhereStateIsNot('state', BookingState::INITIAL_STATE)->count());
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
