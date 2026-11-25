<?php

namespace App\Http\Resources;

use App\Services\Media\ImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account, as described to the person holding it.
 *
 * This resource is only ever returned to its own owner — sign-in, and
 * /v1/profile. It is not embedded in an order, a review or a chat, and that
 * is what makes `avatar_url` safe to put here: a customer's face is not
 * something a merchant or a rider has been given. If this resource is ever
 * nested inside somebody else's response, the avatar has to come out first.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'preferred_locale' => $this->preferred_locale,
            'phone_verified' => $this->phone_verified_at !== null,

            // Null when they have not set one. The apps draw their initials
            // rather than a broken image, the same as everywhere else.
            'avatar_url' => app(ImageService::class)->url($this->avatar_path),
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
        ];
    }
}
