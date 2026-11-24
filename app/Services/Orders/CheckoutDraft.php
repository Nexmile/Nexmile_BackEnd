<?php

namespace App\Services\Orders;

use App\Enums\FulfilmentType;
use App\Enums\PaymentMethod;
use App\Models\Address;

/**
 * What the customer asked for at checkout, before the server has agreed to
 * any of it.
 *
 * `CheckoutService::place()` took seven positional arguments and was about to
 * take nine. Two booleans and two arrays side by side in a call is a swap
 * waiting to happen, and the swap is silent: an order saved with the cutlery
 * flag set from the wallet flag reads as a working order.
 *
 * Deliberately **not** validated here. Everything on this object is what the
 * client said; `place()` is the only thing that decides what is true. Keeping
 * the two apart is what makes it safe for a controller to build one of these
 * straight from a request.
 *
 * Same shape and the same reasoning as ReviewDraft.
 */
final readonly class CheckoutDraft
{
    /**
     * @param  array<int, string>  $deliveryInstructions  Codes from config('checkout.delivery_instructions')
     */
    public function __construct(
        public FulfilmentType $fulfilment,
        public PaymentMethod $method,
        public ?Address $address = null,
        public ?string $note = null,
        public bool $useWallet = false,
        public array $deliveryInstructions = [],
        public bool $noCutlery = false,
    ) {}

    /**
     * Build one from validated request data.
     *
     * The address arrives resolved rather than as an id, because scoping it to
     * the caller is an authorisation decision and belongs with the request
     * that made it — not in a value object a job could build later.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromRequest(array $data, FulfilmentType $fulfilment, PaymentMethod $method, ?Address $address): self
    {
        return new self(
            fulfilment: $fulfilment,
            method: $method,
            address: $address,
            note: $data['note'] ?? null,
            useWallet: (bool) ($data['use_wallet'] ?? false),
            // Duplicates dropped: a chip tapped twice is one instruction.
            deliveryInstructions: array_values(array_unique($data['delivery_instructions'] ?? [])),
            noCutlery: (bool) ($data['no_cutlery'] ?? false),
        );
    }
}
