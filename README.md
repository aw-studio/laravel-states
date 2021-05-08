# Laravel States

A package to make use of the **finite state pattern** in eloquent Models.

## Introduction

The package stores all states in a database table, so all states changes and the corresponding times can be traced. Since states are mapped via a relation, no additional migrations need to be created when a new state is needed for a model.

## A Recommendation

Use states wherever possible! A state can be used instead of booleans like `active` or timestamps like `declined_at` or `deleted_at`:

```php
$product->state->is('active');
```

This way you also know when the change to active has taken place. Also your app becomes more scalable, you can simply add an additional state if needed.

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

## Usage

```php
$booking->state->current(); // "pending"
(string) $booking->state; // "pending"
$booking->state->is(BookingState::PENDING); // true
$booking->state->isAnyOf(BookingState::FINAL_STATES); // true
$booking->state->was(BookingState::PENDING); // true
$booking->state->can(BookingStateTransition::PAYMENT_PAID); // true
$booking->state->transition(BookingStateTransition::PAYMENT_PAID); // changes state from "pending to "successful"
$booking->state->reload(); // reload the current state
$booking->state->lockForUpdate(); // Locks the state for update
$booking->loadCurrentState();
$booking->loadCurrentState('payment_state');
$booking->states()->get() // Get all states.
$booking->states('payment_state')->get() // Get all payment states.
```

Static Methods:

```php
BookingState::whereCan(BookingStateTransition::PAYMENT_PAID); // Gets states where from where the given transition can be executed.
BookingState::canTransitionFrom('pending', 'cancel'); // Determines if the transition can be executed for the given state.
```

## Query Methods

```php
Booking::withCurrentState(); // eager loading the current state
Booking::whereStateIs('payment_state', PaymentState::PAID);
Booking::orWhereStateIs('payment_state', PaymentState::PAID);
Booking::whereStateIsNot('payment_state', PaymentState::PAID);
Booking::orWhereStateIsNot('payment_state', PaymentState::PAID);
Booking::whereStateWas('payment_state', PaymentState::PAID);
Booking::whereStateWasNot('payment_state', PaymentState::PAID);
```
