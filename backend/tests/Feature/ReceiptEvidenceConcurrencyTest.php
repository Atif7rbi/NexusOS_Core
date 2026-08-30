<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Payments\Actions\RecordPaymentAction;
use App\Modules\ReceiptEvidence\Actions\ApproveReceivingAccount;
use App\Modules\ReceiptEvidence\Actions\AssociateReceiptWithPayment;
use App\Modules\ReceiptEvidence\Actions\VerifyBankReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ReceiptEvidenceConcurrencyTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        } parent::tearDown();
    }

    public function test_c1_same_receipt_operation_same_facts_replays(): void
    {
        [$t,$a] = $this->context();
        $f = $this->receiptFacts($this->account($t, $a), 'c1');
        $r = $this->race($t, [$this->verify($t, $a, $f), $this->verify($t, $a, $f)]);
        self::assertSame(2, $this->successes($r), json_encode($r));
        self::assertSame(1, DB::table('bank_receipt_evidence')->where('tenant_id', $t)->where('receipt_operation_id', $f['receipt_operation_id'])->count());
    }

    public function test_c2_same_receipt_operation_different_facts_conflicts(): void
    {
        [$t,$a] = $this->context();
        $f = $this->receiptFacts($this->account($t, $a), 'c2');
        $g = $f;
        $g['amount'] = '11.00';
        $r = $this->race($t, [$this->verify($t, $a, $f), $this->verify($t, $a, $g)]);
        self::assertSame(1, $this->successes($r), json_encode($r));
    }

    public function test_c3_same_source_different_operations_has_one_effective_receipt(): void
    {
        [$t,$a] = $this->context();
        $account = $this->account($t, $a);
        $f = $this->receiptFacts($account, 'c3');
        $g = $this->receiptFacts($account, 'c3');
        $r = $this->race($t, [$this->verify($t, $a, $f), $this->verify($t, $a, $g)]);
        self::assertSame(2, $this->successes($r), json_encode($r));
        self::assertSame(1, DB::table('bank_receipt_evidence')->where('tenant_id', $t)->where('source_identity', 'c3')->where('status', 'effective')->count());
    }

    public function test_c4_duplicate_manual_verification_has_no_duplicate_truth(): void
    {
        [$t,$a] = $this->context();
        $f = $this->receiptFacts($this->account($t, $a), 'c4');
        $r = $this->race($t, [$this->verify($t, $a, $f), $this->verify($t, $a, $f)]);
        self::assertSame(1, DB::table('bank_receipt_evidence')->where('tenant_id', $t)->where('source_identity', 'c4')->count());
        self::assertSame(2, $this->successes($r), json_encode($r));
    }

    public function test_c5_same_association_operation_same_facts_replays(): void
    {
        [$t,$a,$c] = $this->context();
        [$receipt,$payment] = $this->pair($t, $a, $c, 'c5');
        $f = $this->associationFacts($receipt, $payment);
        $r = $this->race($t, [$this->associate($t, $a, $f), $this->associate($t, $a, $f)]);
        self::assertSame(2, $this->successes($r), json_encode($r));
        self::assertSame(1, DB::table('receipt_payment_associations')->where('tenant_id', $t)->where('association_operation_id', $f['association_operation_id'])->count());
    }

    public function test_c6_same_association_operation_different_payment_conflicts(): void
    {
        [$t,$a,$c] = $this->context();
        [$receipt,$payment] = $this->pair($t, $a, $c, 'c6');
        $other = $this->payment($t, $a, $c, '10.00');
        $f = $this->associationFacts($receipt, $payment);
        $g = $f;
        $g['payment_id'] = $other;
        $r = $this->race($t, [$this->associate($t, $a, $f), $this->associate($t, $a, $g)]);
        self::assertSame(1, $this->successes($r), json_encode($r));
    }

    public function test_c7_same_receipt_two_payments_has_one_effective_association(): void
    {
        [$t,$a,$c] = $this->context();
        [$receipt,$payment] = $this->pair($t, $a, $c, 'c7');
        $r = $this->race($t, [$this->associate($t, $a, $this->associationFacts($receipt, $payment)), $this->associate($t, $a, $this->associationFacts($receipt, $this->payment($t, $a, $c, '10.00')))]);
        self::assertSame(1, $this->successes($r), json_encode($r));
    }

    public function test_c8_same_payment_two_receipts_has_one_effective_association(): void
    {
        [$t,$a,$c] = $this->context();
        [$receipt,$payment] = $this->pair($t, $a, $c, 'c8a');
        $other = $this->receipt($t, $a, $this->account($t, $a), '10.00', 'c8b');
        $r = $this->race($t, [$this->associate($t, $a, $this->associationFacts($receipt, $payment)), $this->associate($t, $a, $this->associationFacts($other, $payment))]);
        self::assertSame(1, $this->successes($r), json_encode($r));
    }

    public function test_c9_cancel_payment_vs_association_preserves_forbidden_state(): void
    {
        [$t,$a,$c] = $this->context();
        [$receipt,$payment] = $this->pair($t, $a, $c, 'c9');
        $r = $this->race($t, [$this->cancelPayment($t, $a, $payment), $this->associate($t, $a, $this->associationFacts($receipt, $payment))]);
        $effective = DB::table('receipt_payment_associations')->where('tenant_id', $t)->where('payment_id', $payment)->where('status', 'effective')->exists();
        self::assertFalse(DB::table('payments')->where('id', $payment)->value('status') === 'cancelled' && $effective, json_encode($r));
    }

    public function test_c10_invalidate_receipt_vs_association_preserves_forbidden_state(): void
    {
        [$t,$a,$c] = $this->context();
        [$receipt,$payment] = $this->pair($t, $a, $c, 'c10');
        $r = $this->race($t, [$this->invalidate($t, $a, $receipt), $this->associate($t, $a, $this->associationFacts($receipt, $payment))]);
        $effective = DB::table('receipt_payment_associations')->where('receipt_id', $receipt)->where('status', 'effective')->exists();
        self::assertFalse(DB::table('bank_receipt_evidence')->where('id', $receipt)->value('status') === 'invalidated' && $effective, json_encode($r));
    }

    public function test_c11_cancel_association_vs_replacement_requires_cancellation_first(): void
    {
        [$t,$a,$c] = $this->context();
        [$receipt,$payment] = $this->pair($t, $a, $c, 'c11');
        $old = app(AssociateReceiptWithPayment::class)->execute($t, $a, $this->associationFacts($receipt, $payment));
        $replacementReceipt = $this->receipt($t, $a, $this->account($t, $a), '10.00', 'c11r');
        $replacementPayment = $this->payment($t, $a, $c, '10.00');
        $r = $this->race($t, [$this->cancelAssociation($t, $a, $old), $this->associate($t, $a, $this->associationFacts($replacementReceipt, $replacementPayment, ['replaces_association_id' => $old]))]);
        self::assertLessThanOrEqual(1, DB::table('receipt_payment_associations')->where(fn ($q) => $q->where('id', $old)->orWhere('replaces_association_id', $old))->where('status', 'effective')->count(), json_encode($r));
    }

    public function test_c12_retirement_vs_historical_receipt_verification_allows_historical_fact(): void
    {
        [$t,$a] = $this->context();
        $account = $this->account($t, $a);
        $f = $this->receiptFacts($account, 'c12', ['control_date' => now()->subDay()->toDateString()]);
        $r = $this->race($t, [$this->retire($t, $a, $account, now()->toDateString()), $this->verify($t, $a, $f)]);
        self::assertSame(2, $this->successes($r), json_encode($r));
    }

    public function test_c13_retirement_vs_new_receipt_never_commits_temporally_invalid_evidence(): void
    {
        [$t,$a] = $this->context();
        $account = $this->account($t, $a);
        $f = $this->receiptFacts($account, 'c13');
        $r = $this->race($t, [$this->retire($t, $a, $account, now()->toDateString()), $this->verify($t, $a, $f)]);
        self::assertLessThanOrEqual(1, $this->successes($r), json_encode($r));
    }

    public function test_c19_same_import_style_source_has_one_effective_receipt(): void
    {
        [$t,$a] = $this->context();
        $account = $this->account($t, $a);
        $f = $this->receiptFacts($account, 'fingerprint-c19', ['source_identity_kind' => 'statement_line_fingerprint_v1']);
        $g = $this->receiptFacts($account, 'fingerprint-c19', ['source_identity_kind' => 'statement_line_fingerprint_v1']);
        $r = $this->race($t, [$this->verify($t, $a, $f), $this->verify($t, $a, $g)]);
        self::assertSame(1, DB::table('bank_receipt_evidence')->where('source_identity', 'fingerprint-c19')->where('status', 'effective')->count(), json_encode($r));
    }

    public function test_c21_retirement_same_operation_replays_and_different_target_conflicts_concurrently(): void
    {
        [$t,$a] = $this->context();
        $account = $this->account($t, $a);
        $f = ['retirement_operation_id' => (string) Str::ulid(), 'retired_from' => now()->addDay()->toDateString(), 'retirement_reason' => 'C21'];
        $r = $this->race($t, [$this->retire($t, $a, $account, $f['retired_from'], $f), $this->retire($t, $a, $account, $f['retired_from'], $f)]);
        self::assertSame(2, $this->successes($r), json_encode($r));
        $r = $this->race($t, [$this->retire($t, $a, $this->account($t, $a), $f['retired_from'], $f), $this->retire($t, $a, $this->account($t, $a), $f['retired_from'], $f)]);
        self::assertSame(0, $this->successes($r), json_encode($r));
    }

    public function test_c22_invalidation_same_operation_replays_and_different_facts_conflict_concurrently(): void
    {
        [$t,$a] = $this->context();
        $receipt = $this->receipt($t, $a, $this->account($t, $a), '10.00', 'c22');
        $f = ['invalidation_operation_id' => (string) Str::ulid(), 'invalidation_reason' => 'C22'];
        $r = $this->race($t, [$this->invalidate($t, $a, $receipt, $f), $this->invalidate($t, $a, $receipt, $f)]);
        self::assertSame(2, $this->successes($r), json_encode($r));
        $g = $f;
        $g['invalidation_reason'] = 'Different';
        $r = $this->race($t, [$this->invalidate($t, $a, $receipt, $g), $this->invalidate($t, $a, $receipt, $g)]);
        self::assertSame(0, $this->successes($r), json_encode($r));
    }

    public function test_c23_association_cancellation_same_operation_replays_and_different_facts_conflict_concurrently(): void
    {
        [$t,$a,$c] = $this->context();
        [$receipt,$payment] = $this->pair($t, $a, $c, 'c23');
        $association = app(AssociateReceiptWithPayment::class)->execute($t, $a, $this->associationFacts($receipt, $payment));
        $f = ['cancellation_operation_id' => (string) Str::ulid(), 'cancellation_reason' => 'C23'];
        $r = $this->race($t, [$this->cancelAssociation($t, $a, $association, $f), $this->cancelAssociation($t, $a, $association, $f)]);
        self::assertSame(2, $this->successes($r), json_encode($r));
        $g = $f;
        $g['cancellation_reason'] = 'Different';
        $r = $this->race($t, [$this->cancelAssociation($t, $a, $association, $g), $this->cancelAssociation($t, $a, $association, $g)]);
        self::assertSame(0, $this->successes($r), json_encode($r));
    }

    private function context(): array
    {
        $a = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $t = (string) TenantUser::query()->where('user_id', $a->id)->value('tenant_id');

        return [$t, $a, (string) $this->createIntegrityCustomer($t, $a->id)->id];
    }

    private function account(string $t, User $a): string
    {
        return app(ApproveReceivingAccount::class)->execute($t, $a, ['receiving_account_operation_id' => (string) Str::ulid(), 'institution_identifier' => 'bank-'.Str::lower((string) Str::ulid()), 'account_identity' => 'iban-'.Str::lower((string) Str::ulid()), 'masked_account_identity' => 'SA**1234', 'valid_from' => now()->subDays(2)->toDateString()]);
    }

    private function receipt(string $t, User $a, string $account, string $amount, string $source): string
    {
        return app(VerifyBankReceipt::class)->execute($t, $a, $this->receiptFacts($account, $source, ['amount' => $amount]));
    }

    private function payment(string $t, User $a, string $c, string $amount): string
    {
        return app(RecordPaymentAction::class)->execute($t, $a, ['payment_operation_id' => (string) Str::ulid(), 'customer_id' => $c, 'amount' => $amount, 'currency' => 'SAR', 'received_on' => now()->toDateString()]);
    }

    private function pair(string $t, User $a, string $c, string $source): array
    {
        return [$this->receipt($t, $a, $this->account($t, $a), '10.00', $source), $this->payment($t, $a, $c, '10.00')];
    }

    private function receiptFacts(string $account, string $source, array $overrides = []): array
    {
        return [...['receipt_operation_id' => (string) Str::ulid(), 'receiving_account_id' => $account, 'source_identity_kind' => 'bank_transaction_id', 'source_identity_version' => 1, 'source_identity' => $source, 'amount' => '10.00', 'currency' => 'SAR', 'control_date' => now()->toDateString(), 'evidence_reference' => 'concurrency'], ...$overrides];
    }

    private function associationFacts(string $r, string $p, array $o = []): array
    {
        return [...['association_operation_id' => (string) Str::ulid(), 'receipt_id' => $r, 'payment_id' => $p], ...$o];
    }

    private function verify(string $t, User $a, array $f): array
    {
        return ['action' => 'verify', 'tenant_id' => $t, 'actor_id' => $a->id, 'facts' => $f];
    }

    private function associate(string $t, User $a, array $f): array
    {
        return ['action' => 'associate', 'tenant_id' => $t, 'actor_id' => $a->id, 'facts' => $f];
    }

    private function cancelPayment(string $t, User $a, string $p): array
    {
        return ['action' => 'cancel_payment', 'tenant_id' => $t, 'actor_id' => $a->id, 'payment_id' => $p, 'reason' => 'Concurrent correction'];
    }

    private function invalidate(string $t, User $a, string $r, array $f = []): array
    {
        return ['action' => 'invalidate', 'tenant_id' => $t, 'actor_id' => $a->id, 'receipt_id' => $r, 'facts' => $f ?: ['invalidation_operation_id' => (string) Str::ulid(), 'invalidation_reason' => 'Concurrent correction']];
    }

    private function retire(string $t, User $a, string $account, string $date, array $f = []): array
    {
        return ['action' => 'retire', 'tenant_id' => $t, 'actor_id' => $a->id, 'account_id' => $account, 'facts' => $f ?: ['retirement_operation_id' => (string) Str::ulid(), 'retired_from' => $date, 'retirement_reason' => 'Concurrent correction']];
    }

    private function cancelAssociation(string $t, User $a, string $id, array $f = []): array
    {
        return ['action' => 'cancel_association', 'tenant_id' => $t, 'actor_id' => $a->id, 'association_id' => $id, 'facts' => $f ?: ['cancellation_operation_id' => (string) Str::ulid(), 'cancellation_reason' => 'Concurrent correction']];
    }

    private function successes(array $r): int
    {
        return count(array_filter($r, fn ($x) => $x['ok']));
    }

    private function file(string $p): string
    {
        $f = tempnam(sys_get_temp_dir(), $p);
        unlink($f);
        $this->temporaryFiles[] = $f;

        return $f;
    }

    private function wait(array $files): void
    {
        $end = microtime(true) + 15;
        while (array_filter($files, fn ($f) => ! is_file($f))) {
            if (microtime(true) > $end) {
                $this->fail('Timed out waiting for receipt concurrency barrier.');
            } usleep(1000);
        }
    }

    private function start(array $p): array
    {
        $c = config('database.connections.'.DB::getDefaultConnection());
        $d = array_intersect_key($c, array_flip(['host', 'port', 'database', 'username', 'password']));
        $proc = proc_open([PHP_BINARY, base_path('tests/Support/receipt_evidence_worker.php'), base64_encode(json_encode($p + ['database' => $d], JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        fclose($pipes[0]);

        return [$proc, $pipes[1], $pipes[2]];
    }

    private function finish(array $w): array
    {
        [$p,$out,$err] = $w;
        $stdout = stream_get_contents($out);
        $stderr = stream_get_contents($err);
        fclose($out);
        fclose($err);
        self::assertSame(0, proc_close($p), $stderr);

        return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitLocks(array $pids): void
    {
        $end = microtime(true) + 15;
        while (true) {
            $waiting = DB::table('pg_stat_activity')->whereIn('pid',$pids)->where('wait_event_type','Lock')->count();
            if ($waiting === count($pids)) {
                return;
            } if (microtime(true) > $end) {
                $this->fail('Timed out waiting for receipt workers to block on PostgreSQL locks.');
            } usleep(1000);
        }
    }

    private function race(string $t,array $payloads): array
    {
        $ready = $this->file('receipt-holder-ready-');
        $release = $this->file('receipt-holder-release-');
        $start = $this->file('receipt-start-');
        $holder = $this->start(['action' => 'hold_tenant', 'tenant_id' => $t, 'actor_id' => 0, 'ready_file' => $ready, 'release_file' => $release]);
        $this->wait([$ready]);
        $workers = [];
        $pidFiles = [];
        foreach ($payloads as $i => $p) {
            $pidFiles[] = $this->file('receipt-worker-'.$i.'-');
            $workers[] = $this->start($p + ['start_barrier' => $start, 'barrier_timeout_ms' => 15000, 'pid_file' => $pidFiles[$i]]);
        } $this->wait($pidFiles);
        touch($start);
        $this->waitLocks(array_map(fn ($f) => (int) trim((string) file_get_contents($f)),$pidFiles));
        touch($release);
        $results = array_map(fn ($w) => $this->finish($w),$workers);
        $this->finish($holder);

        return $results;
    }
}
