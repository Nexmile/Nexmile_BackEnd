<?php

namespace App\Console\Commands;

use App\Models\ServiceClient;
use Illuminate\Console\Command;

/**
 * Switch an integration off, now.
 *
 * Its own command because the moment it is needed is the moment nobody wants
 * to be working out which rows to edit. One argument, one effect.
 */
class RevokeServiceClientCommand extends Command
{
    protected $signature = 'nexmile:revoke-service-client
                            {client : id or slug}
                            {--keep-active : Delete the tokens but leave the client enabled}';

    protected $description = 'Revoke a service credential and suspend the integration';

    public function handle(): int
    {
        $needle = (string) $this->argument('client');

        $client = ServiceClient::where('slug', $needle)
            ->orWhere('id', ctype_digit($needle) ? (int) $needle : 0)
            ->first();

        if ($client === null) {
            $this->components->error("No service client matches '{$needle}'.");

            return self::FAILURE;
        }

        $tokens = $client->tokens()->count();
        $client->tokens()->delete();

        if (! $this->option('keep-active')) {
            /*
             * Suspended as well as de-tokened. Deleting tokens alone leaves a
             * client that works again the moment somebody mints a new one,
             * which is not what "revoke" means to the person typing it at
             * two in the morning.
             */
            $client->forceFill(['is_active' => false])->save();
        }

        $this->components->info(sprintf(
            '%s: %d token(s) revoked%s.',
            $client->name,
            $tokens,
            $this->option('keep-active') ? '' : ', integration suspended',
        ));

        return self::SUCCESS;
    }
}
