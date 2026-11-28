<?php

namespace App\Enums;

/**
 * Order lifecycle. Delivery and self-pickup share the same states up to
 * ReadyForPickup, then branch (EP10).
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Placed = 'placed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Preparing = 'preparing';
    case ReadyForPickup = 'ready_for_pickup';
    case RiderAssigned = 'rider_assigned';

    /*
     * At the counter, and at the door. Both exist because the customer
     * asking "where is it" is answered differently by "a rider is on the
     * way to the restaurant" and "a rider is standing in the restaurant",
     * and because waiting at either end is the most common complaint a
     * delivery rider has.
     */
    case ReachedRestaurant = 'reached_restaurant';

    case PickedUp = 'picked_up';

    case ReachedCustomer = 'reached_customer';

    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Rejected], true);
    }

    /**
     * Is the food out of the kitchen's hands, or on its way to being?
     *
     * The handover window: from the moment it is ready to collect until it
     * has been delivered. It is what decides whether the restaurant is shown
     * the pickup code — the four digits they read to the rider as proof that
     * the right person is taking the right order.
     *
     * Written as "everything except the kitchen stages and the refusals"
     * rather than as a list of the stages it covers, and that is the whole
     * point of it being here.
     *
     * The list it replaces named ready_for_pickup, rider_assigned, picked_up
     * and delivered — and missed reached_restaurant, which is precisely the
     * moment a rider is standing at the counter asking for the code. The one
     * status where it mattered most was the one that hid it. Phrased this
     * way, a stage added later in the journey is covered by default, and the
     * mistake can only be made again by someone adding a stage *before* the
     * food is cooked.
     */
    public function isHandover(): bool
    {
        return ! in_array($this, [
            self::PendingPayment,
            self::Placed,
            self::Accepted,
            self::Preparing,
            self::Rejected,
            self::Cancelled,
        ], true);
    }
}
