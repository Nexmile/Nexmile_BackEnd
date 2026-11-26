<?php

namespace App\Console\Commands;

use App\Contracts\PaymentGateway;
use App\Models\WalletTopup;
use App\Services\Payments\RazorpayGateway;
use Illuminate\Console\Command;
use Throwable;

/**
 * What state the payment gateway is actually in.
 *
 * Written because answering "why is money not going into the wallet" took a
 * string of tinker one-liners pasted into a chat window, and the answer was
 * always one of five things. It is read-only and it prints no secret: a
 * key is shown by its prefix, everything else as set or missing.
 *
 * The prefix is the point. `rzp_test_` and `rzp_live_` are the difference
 * between a sandbox and somebody's actual money, they are indistinguishable
 * in a `.env` at a glance, and getting it the wrong way round is silent in
 * both directions — test keys on launch day means nobody can pay, and live
 * keys during testing means somebody's card is really charged.
 */
class PaymentsDoctorCommand extends Command
{
    protected $signature = 'nexmile:payments-doctor';

    protected $description = 'Report the payment gateway configuration and recent top-up outcomes';

    public function handle(): int
    {
        $this->components->info('Payment gateway');

        $configured = config('payments.gateway');

        $this->components->twoColumnDetail(
            'PAYMENT_GATEWAY',
            $configured === null ? '<fg=yellow>not set — cash only</>' : (string) $configured,
        );

        /*
         * Resolved rather than inferred from config. The binding is what
         * actually decides, and the whole class of bug this command exists
         * for is config and binding disagreeing.
         */
        try {
            $bound = get_class(app(PaymentGateway::class));
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('Bound class', '<fg=red>REFUSED</>');
            $this->newLine();
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $isReal = $bound === RazorpayGateway::class;

        $this->components->twoColumnDetail(
            'Bound class',
            ($isReal ? '<fg=green>' : '<fg=yellow>').class_basename($bound).'</>',
        );

        $key = (string) config('payments.razorpay.key');

        $this->components->twoColumnDetail('Key id', match (true) {
            $key === '' => '<fg=red>MISSING</>',
            str_starts_with($key, 'rzp_test_') => '<fg=yellow>rzp_test_… (sandbox)</>',
            str_starts_with($key, 'rzp_live_') => '<fg=green>rzp_live_… (real money)</>',
            default => '<fg=red>set, but not a Razorpay key id</>',
        });

        $this->components->twoColumnDetail(
            'Key secret',
            config('payments.razorpay.secret') ? '<fg=green>set</>' : '<fg=red>MISSING</>',
        );

        /*
         * Called out loudly because it fails silently and in only one
         * direction. Without it every webhook is rejected unverified, which
         * leaves the customer's own app as the only thing that can credit a
         * wallet — so a payment made on a phone that then lost signal is
         * money taken and never credited, and nothing anywhere logs a
         * problem.
         */
        $this->components->twoColumnDetail(
            'Webhook secret',
            config('payments.razorpay.webhook_secret')
                ? '<fg=green>set</>'
                : '<fg=red>MISSING — every webhook will be rejected</>',
        );

        $this->newLine();
        $this->components->info('Wallet');

        $this->components->twoColumnDetail(
            'Top-ups enabled',
            config('payments.wallet.enabled') ? '<fg=green>yes</>' : '<fg=yellow>no</>',
        );

        $this->components->twoColumnDetail('Per top-up', sprintf(
            '₹%s – ₹%s',
            config('payments.wallet.minimum_topup'),
            config('payments.wallet.maximum_topup'),
        ));

        $this->components->twoColumnDetail('Balance ceiling', '₹'.config('payments.wallet.maximum_balance'));

        $this->newLine();
        $this->components->info('Recent top-ups');

        $counts = WalletTopup::selectRaw('status, count(*) as total, max(created_at) as latest')
            ->groupBy('status')
            ->pluck('total', 'status');

        if ($counts->isEmpty()) {
            $this->components->warn('No top-up has ever been started.');
        } else {
            foreach (['created', 'paid', 'failed'] as $status) {
                $this->components->twoColumnDetail(
                    $status === 'created' ? 'created (never completed)' : $status,
                    (string) ($counts[$status] ?? 0),
                );
            }
        }

        foreach (WalletTopup::latest('id')->take(5)->get() as $topup) {
            $this->line(sprintf(
                '  #%d  ₹%s  %s  order=%s  payment=%s',
                $topup->id,
                $topup->amount,
                str_pad($topup->status, 7),
                $topup->gateway_order_id ?: '-',
                $topup->gateway_payment_id ?: '-',
            ));
        }

        $this->newLine();

        /*
         * The conclusions, rather than leaving somebody to read five rows and
         * work out which combination means what. Each of these was a real
         * afternoon.
         */
        if (! $isReal && $configured !== null) {
            $this->components->error('A fake gateway is bound. No real payment can complete.');

            return self::FAILURE;
        }

        if ($configured === null) {
            $this->components->warn('Cash only. Top-ups will answer 503 until PAYMENT_GATEWAY is set.');

            return self::SUCCESS;
        }

        if (! config('payments.razorpay.webhook_secret')) {
            $this->components->error(
                'No webhook secret. A payment made on a phone that then loses signal will be '
                .'taken and never credited. Add the webhook in the Razorpay dashboard — in the '
                .'same mode as the keys above — and set RAZORPAY_WEBHOOK_SECRET.'
            );

            return self::FAILURE;
        }

        if (str_starts_with($key, 'rzp_test_')) {
            $this->components->warn(
                'Sandbox keys. Real cards will be declined — correct for testing, and the thing '
                .'to swap before real customers arrive. Live mode needs its own webhook and secret.'
            );
        }

        $this->components->info('Configuration looks complete.');

        return self::SUCCESS;
    }
}
