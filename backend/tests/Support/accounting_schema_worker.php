<?php

declare(strict_types=1);

$payload = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);

try {
    if (($payload['pre_delay_ms'] ?? 0) > 0) {
        usleep((int) $payload['pre_delay_ms'] * 1000);
    }
    $pdo = new PDO($payload['dsn'], $payload['username'], $payload['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET search_path TO '.$pdo->quote($payload['schema']));
    $pdo->beginTransaction();
    $lockTimeout=(int) ($payload['lock_timeout_ms'] ?? 5000);
    $pdo->exec("SET LOCAL lock_timeout = '{$lockTimeout}ms'");

    if ($payload['action'] === 'period') {
        $statement = $pdo->prepare('INSERT INTO accounting_periods(id,tenant_id,start_date,end_date,status,created_by,updated_by,created_at,updated_at) VALUES(?,?,?::date,?::date,\'open\',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
        $statement->execute([$payload['id'],$payload['tenant_id'],$payload['start'],$payload['end'],$payload['actor_id'],$payload['actor_id']]);
    } elseif ($payload['action'] === 'number') {
        $insert = $pdo->prepare("INSERT INTO business_number_sequences(tenant_id,prefix,year,current_value,created_at,updated_at) VALUES(?,'JRN',2026,0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(tenant_id,prefix,year) DO NOTHING");
        $insert->execute([$payload['tenant_id']]);
        $update = $pdo->prepare("UPDATE business_number_sequences SET current_value=current_value+1,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND prefix='JRN' AND year=2026 RETURNING current_value");
        $update->execute([$payload['tenant_id']]);
        $payload['value'] = (int) $update->fetchColumn();
    } elseif ($payload['action'] === 'activate') {
        $statement=$pdo->prepare("INSERT INTO accounting_settings(id,tenant_id,ledger_currency,activated_by,activated_at) VALUES(?,?,'SAR',?,CURRENT_TIMESTAMP)");
        $statement->execute([$payload['id'],$payload['tenant_id'],$payload['actor_id']]);
    } elseif ($payload['action'] === 'currency') {
        $statement=$pdo->prepare("UPDATE tenants SET currency='USD',updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $statement->execute([$payload['tenant_id']]);
    } elseif ($payload['action'] === 'opening') {
        $openingDate=$payload['opening_date'] ?? '2026-01-01';
        $journal=$pdo->prepare("INSERT INTO journal_entries(id,tenant_id,entry_date,description,status,origin,source_type,source_id,created_by,updated_by,created_at,updated_at) VALUES(?,?,?::date,'Opening draft','draft','opening_balance','opening_balance_operation',?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $journal->execute([$payload['journal_id'],$payload['tenant_id'],$openingDate,$payload['operation_id'],$payload['actor_id'],$payload['actor_id']]);
        $operation=$pdo->prepare("INSERT INTO opening_balance_operations(id,tenant_id,status,accounting_date,journal_entry_id,created_by,updated_by,created_at,updated_at) VALUES(?,?,'draft',?::date,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $operation->execute([$payload['operation_id'],$payload['tenant_id'],$openingDate,$payload['journal_id'],$payload['actor_id'],$payload['actor_id']]);
    } elseif ($payload['action'] === 'account_parent') {
        $statement=$pdo->prepare('UPDATE accounts SET parent_id=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?');
        $statement->execute([$payload['parent_id'],$payload['actor_id'],$payload['tenant_id'],$payload['id']]);
    } elseif ($payload['action'] === 'lock_settings') {
        $statement=$pdo->prepare('SELECT id FROM accounting_settings WHERE tenant_id=? FOR UPDATE');
        $statement->execute([$payload['tenant_id']]);
        usleep((int) $payload['hold_ms'] * 1000);
    } elseif ($payload['action'] === 'post') {
        $statement=$pdo->prepare("UPDATE journal_entries SET status='posted',accounting_period_id=?,journal_number=?,journal_number_year=2026,journal_sequence_number=?,posted_by=?,posted_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?");
        $statement->execute([$payload['period_id'],$payload['number'],$payload['sequence'],$payload['actor_id'],$payload['actor_id'],$payload['tenant_id'],$payload['journal_id']]);
    } elseif ($payload['action'] === 'close_period') {
        $statement=$pdo->prepare("UPDATE accounting_periods SET status='closed',closed_at=CURRENT_TIMESTAMP,closed_by=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?");
        $statement->execute([$payload['actor_id'],$payload['actor_id'],$payload['tenant_id'],$payload['period_id']]);
    } elseif ($payload['action'] === 'archive_account') {
        $statement=$pdo->prepare("UPDATE accounts SET status='archived',archived_at=CURRENT_TIMESTAMP,archived_by=?,restored_at=NULL,restored_by=NULL,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?");
        $statement->execute([$payload['actor_id'],$payload['actor_id'],$payload['tenant_id'],$payload['account_id']]);
    } elseif ($payload['action'] === 'account_code') {
        $statement=$pdo->prepare('UPDATE accounts SET code=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?');
        $statement->execute([$payload['code'],$payload['actor_id'],$payload['tenant_id'],$payload['account_id']]);
    } elseif ($payload['action'] === 'reverse') {
        $insert=$pdo->prepare("INSERT INTO journal_entries(id,tenant_id,entry_date,description,status,origin,source_type,source_id,created_by,updated_by,created_at,updated_at,reverses_journal_entry_id,reversal_reason) VALUES(?,?,?::date,'Exact reversal','draft','reversal','journal_entry',?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?,'Correction')");
        $insert->execute([$payload['journal_id'],$payload['tenant_id'],$payload['entry_date'],$payload['target_id'],$payload['actor_id'],$payload['actor_id'],$payload['target_id']]);
        $lines=$pdo->prepare('INSERT INTO journal_lines(id,tenant_id,journal_entry_id,line_number,account_id,debit,credit,memo,created_at,updated_at) SELECT ?,tenant_id,?,line_number,account_id,credit,debit,memo,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP FROM journal_lines WHERE tenant_id=? AND journal_entry_id=? AND line_number=?');
        foreach ($payload['line_ids'] as $lineNumber=>$lineId) {
            $lines->execute([$lineId,$payload['journal_id'],$payload['tenant_id'],$payload['target_id'],$lineNumber]);
        }
        $post=$pdo->prepare("UPDATE journal_entries SET status='posted',accounting_period_id=?,journal_number=?,journal_number_year=2026,journal_sequence_number=?,posted_by=?,posted_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?");
        $post->execute([$payload['period_id'],$payload['number'],$payload['sequence'],$payload['actor_id'],$payload['actor_id'],$payload['tenant_id'],$payload['journal_id']]);
        if (isset($payload['operation_id'])) {
            $project=$pdo->prepare("UPDATE opening_balance_operations SET effect_state=?,latest_effect_journal_entry_id=?,effect_updated_by=?,effect_updated_at=(SELECT posted_at FROM journal_entries WHERE id=?),updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?");
            $project->execute([$payload['effect_state'],$payload['journal_id'],$payload['actor_id'],$payload['journal_id'],$payload['actor_id'],$payload['tenant_id'],$payload['operation_id']]);
        }
    }

    $pdo->commit();
    echo json_encode(['ok'=>true,'value'=>$payload['value'] ?? null], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok'=>false,'class'=>$exception::class,'message'=>$exception->getMessage()], JSON_THROW_ON_ERROR);
}
