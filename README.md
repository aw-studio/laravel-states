# Laravel States

A package to make use of the **finite state pattern** in eloquent Models.

The package stores all states in a database table, so all states changes and the corresponding times can be traced. Since states are mapped via a relation, no additional migrations need to be created when a new state is needed for a model.

## A Recommendation

Use states wherever possible! A state can be used instead of booleans like `active` or timestamps like `declined_at` or `deleted_at`:

```php
$product->state->is('active');
```

This way you also know when the change to active has taken place. Also your app becomes more scalable, you can simply add an additional state if needed.

## Table Of Contents

- [Setup](#setup)
- [Basics](#basics)
- [Usage](#usage)
    - [Receive The Current State](#receive-state)
    - [Execute Transitions](#execute-transitions)
    - [Eager Loading](#eager-loading)
    - [Query Methods](#query)
- [Observer Events](#events)


<a name="setup"></a>

## Setup

1. Install the package via composer:

```shell
composer require aw-studio/laravel-states
```

2. Publish the required assets:

```shell
php artisan vendor:publish --tag="states:migrations"
```

3. Run The Migrations

```php
php artisan migrate
```

## Basics

1. Create A State:

```php
class BookingState extends State
{
    const PENDING = 'pending';
    const FAILED = 'failed';
    const SUCCESSFULL = 'successfull';

    const INITIAL_STATE = self::PENDING;
    const FINAL_STATES = [self::FAILED, self::SUCCESSFULL];
}
```

2. Create the transitions class:

```php
class BookingStateTransitions extends State
{
    const PAYMENT_PAID = 'payment_paid';
    const PAYMENT_FAILED = 'payment_failed';
}
```

3. Define the allowed transitions:

```php
class BookingState extends State
{
    // ...

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
```

4. Setup your Model:

```php
use AwStudio\States\Contracts\Stateful;
use AwStudio\States\HasStates;

class Booking extends Model implements Stateful
{
    use HasStates;

    protected $states = [
        'state' => BookingState::class,
        'payment_state' => ...,
    ];
}
```

<a name="usage"></a>

## Usage

<a name="receive-state"></a>

### Receive The Current State

```php
$booking->state->current(); // "pending"
(string) $booking->state; // "pending"
```

Determine if the current state is a given state:

```php
if($booking->state->is(BookingState::PENDING)) {
    //
}
```

Determine if the current state is any of a the given states:

```php
$states = [
    BookingState::PENDING,
    BookingState::SUCCESSFULL
];
if($booking->state->isAnyOf($states)) {
    //
}
```

Determine if the state has been the given state at any time:

```php
if($booking->state->was(BookingState::PENDING)) {
    //
}
```

<a name="transitions"></a>

### Execute Transitions

Execute a state transition:

```php
$booking->state->transition(BookingStateTransition::PAYMENT_PAID);
```

Prevent throwing an exception when the given transition is not allowed for the current state by setting fail to `false`:

```php
$booking->state->transition(BookingStateTransition::PAYMENT_PAID, fail: false);
```

Store additional information about the reason of a transition.

```php
$booking->state->transition(BookingStateTransition::PAYMENT_PAID, reason: "Mollie API call failed.");
```

Determine wether the transition is allowed for the current state:

```php
$booking->state->can(BookingStateTransition::PAYMENT_PAID);
```

Lock the current state for update at the start of a transaction so the state can not be modified by simultansiously requests until the transaction is finished:

```php
DB::transaction(function() {
    // Lock the current state for update:
    $booking->state->lockForUpdate();
    
    // ...
});

```

<a name="eager-loading"></a>

### Eager Loading

Reload the current state:

```php
$booking->state->reload();
```

Eager load the current state:

```php
Booking::withCurrentState();
Booking::withCurrentState('payment_state');

$booking->loadCurrentState();
$booking->loadCurrentState('payment_state');
```

<a name="query"></a>

### Query Methods

Filter models that have or dont have a current state:

```php
Booking::whereStateIs('payment_state', PaymentState::PAID);
Booking::orWhereStateIs('payment_state', PaymentState::PAID);
Booking::whereStateIsNot('payment_state', PaymentState::PAID);
Booking::orWhereStateIsNot('payment_state', PaymentState::PAID);
Booking::whereStateWas('payment_state', PaymentState::PAID);
Booking::whereStateWasNot('payment_state', PaymentState::PAID);
```

Receive state changes:

```php
$booking->states()->get() // Get all states.
$booking->states('payment_state')->get() // Get all payment states.
```

<a name="events"></a>

## Observer Events

Listen to state changes or transitions in your model observer:

```php
class BookingObserver
{
    public function stateSuccessfull(Booking $booking)
    {
        // Gets fired when booking state changed to successfull.
    }
    
    public function paymentStatePaid(Booking $booking)
    {
        // Gets fired when booking payment_state changed to paid.
    }
    
    public function stateTransitionPaymentPaid(Booking $booking)
    {
        // Gets fired when state transition payment_paid gets fired.
    }
}
```

## Static Methods:

```php
BookingState::whereCan(BookingStateTransition::PAYMENT_PAID); // Gets states where from where the given transition can be executed.
BookingState::canTransitionFrom('pending', 'cancel'); // Determines if the transition can be executed for the given state.
```
