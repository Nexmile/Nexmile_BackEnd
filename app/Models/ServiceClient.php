<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A machine allowed to call Nexmile as itself.
 *
 * Tokenable through Sanctum, exactly as a User is. The scopes it may use live
 * on the token as abilities; this row decides whether it may call at all.
 */
class ServiceClient extends Model implements Authenticatable
{
    /*
     * Authenticatable because the auth guard insists on it, not because a
     * service client has a password. It has none, and nothing here will ever
     * check one: the only credential is a Sanctum token, and the password
     * methods the contract requires are never reached by a token guard.
     */
    use AuthenticatableTrait, HasApiTokens;

    protected $fillable = [
        'name', 'slug', 'is_active', 'owner_name', 'owner_email', 'notes', 'rate_limit_per_minute',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rate_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ServiceApiLog::class);
    }

    /**
     * Every scope the internal API understands.
     *
     * Listed in one place so a credential cannot be minted with a scope that
     * no route checks — a scope nothing enforces reads like a restriction and
     * is not one.
     *
     * @return array<string, string>
     */
    public static function scopes(): array
    {
        return [
            'customers.read' => 'Read a customer\'s identity and contact details',
            'orders.read' => 'Read an order, its status and its timestamps',
            'merchants.read' => 'Read a restaurant\'s profile and operating state',
            'riders.read' => 'Read the delivery state of an order\'s rider',
            'support.notify' => 'Tell a customer their support thread has a reply',
            'tickets.write' => 'Raise a support case against an order',
            'refunds.check' => 'Ask whether an order is eligible for a refund',
        ];
    }
}
