<?php

namespace App\Console\Commands;

use App\Models\ServiceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Mint a credential for a machine that may call /internal/v1.
 *
 * The only way a service credential is created. There is no screen for this
 * on purpose: a credential that reads any customer's orders should be issued
 * deliberately, by somebody with shell access to the server, and recorded in
 * the same place everything else about that integration lives.
 */
class MakeServiceClientCommand extends Command
{
    protected $signature = 'nexmile:make-service-client
                            {--name= : What the integration is called}
                            {--slug= : Short machine name, unique}
                            {--scopes= : Comma separated, or "all"}
                            {--owner= : Who to contact about it}
                            {--email= : Their email address}
                            {--rate=120 : Requests a minute}';

    protected $description = 'Issue a scoped API credential for an outside system';

    public function handle(): int
    {
        $available = ServiceClient::scopes();

        $name = $this->option('name') ?: $this->ask('What is this integration called?');
        $slug = Str::slug($this->option('slug') ?: $name);

        if (ServiceClient::where('slug', $slug)->exists()) {
            $this->components->error("A service client with the slug {$slug} already exists. Use --slug to choose another.");

            return self::FAILURE;
        }

        $requested = $this->option('scopes') ?: $this->choiceScopes($available);

        $scopes = $requested === 'all'
            ? array_keys($available)
            : array_values(array_filter(array_map('trim', explode(',', $requested))));

        $unknown = array_diff($scopes, array_keys($available));

        if ($unknown !== []) {
            /*
             * Refused rather than ignored. A typo in a scope name would
             * otherwise mint a credential that silently carries less than the
             * operator believed, and they would find out in production.
             */
            $this->components->error('Unknown scope(s): '.implode(', ', $unknown));
            $this->line('  Available: '.implode(', ', array_keys($available)));

            return self::FAILURE;
        }

        if ($scopes === []) {
            $this->components->error('A credential with no scopes can do nothing. Name at least one.');

            return self::FAILURE;
        }

        $client = ServiceClient::create([
            'name' => $name,
            'slug' => $slug,
            'owner_name' => $this->option('owner'),
            'owner_email' => $this->option('email'),
            'rate_limit_per_minute' => (int) $this->option('rate'),
            'is_active' => true,
        ]);

        $token = $client->createToken($slug.'-'.now()->format('Ymd'), $scopes);

        $this->newLine();
        $this->components->info("Service client '{$name}' created.");
        $this->newLine();

        $this->components->twoColumnDetail('Client id', (string) $client->id);
        $this->components->twoColumnDetail('Slug', $slug);
        $this->components->twoColumnDetail('Scopes', implode(', ', $scopes));
        $this->components->twoColumnDetail('Rate limit', $client->rate_limit_per_minute.'/min');

        $this->newLine();
        $this->line('  <fg=yellow>Token, shown once and never again:</>');
        $this->newLine();
        $this->line('  '.$token->plainTextToken);
        $this->newLine();

        /*
         * Said here because this is the moment it matters. The operator is
         * about to paste this into something, and that something is usually
         * a chat window.
         */
        $this->components->warn('Send this through a password manager, not over chat or email.');
        $this->components->warn('It reads real customer data. Revoke with nexmile:revoke-service-client if it is ever exposed.');

        return self::SUCCESS;
    }

    /** @param array<string, string> $available */
    private function choiceScopes(array $available): string
    {
        $this->line('  Available scopes:');

        foreach ($available as $scope => $description) {
            $this->components->twoColumnDetail("  {$scope}", $description);
        }

        return $this->ask('Which scopes? (comma separated, or "all")', 'all');
    }
}
