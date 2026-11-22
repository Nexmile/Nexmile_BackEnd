<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call made into Nexmile by an outside system. Append-only.
 */
class ServiceApiLog extends Model
{
    /** A log that can be edited is not evidence. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'service_client_id', 'method', 'path', 'scope',
        'subject_type', 'subject_id', 'status_code', 'ip_hash', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function serviceClient(): BelongsTo
    {
        return $this->belongsTo(ServiceClient::class);
    }
}
