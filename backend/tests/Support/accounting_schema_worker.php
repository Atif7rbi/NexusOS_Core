<?php

declare(strict_types=1);

$payload = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);

try {
    $pdo = new PDO($payload['dsn'], $payload['username'], $payload['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET search_path TO '.$pdo->quote($payload['schema']));
    $pdo->beginTransaction();
    $pdo->exec("SET LOCAL lock_timeout = '5s'");

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
        $journal=$pdo->prepare("INSERT INTO journal_entries(id,tenant_id,entry_date,description,status,origin,source_type,source_id,created_by,updated_by,created_at,updated_at) VALUES(?,?,DATE '2026-01-01','Opening draft','draft','opening_balance','opening_balance_operation',?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $journal->execute([$payload['journal_id'],$payload['tenant_id'],$payload['operation_id'],$payload['actor_id'],$payload['actor_id']]);
        $operation=$pdo->prepare("INSERT INTO opening_balance_operations(id,tenant_id,status,accounting_date,journal_entry_id,created_by,updated_by,created_at,updated_at) VALUES(?,?,'draft',DATE '2026-01-01',?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $operation->execute([$payload['operation_id'],$payload['tenant_id'],$payload['journal_id'],$payload['actor_id'],$payload['actor_id']]);
    } elseif ($payload['action'] === 'account_parent') {
        $statement=$pdo->prepare('UPDATE accounts SET parent_id=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?');
        $statement->execute([$payload['parent_id'],$payload['actor_id'],$payload['tenant_id'],$payload['id']]);
    }

    $pdo->commit();
    echo json_encode(['ok'=>true,'value'=>$payload['value'] ?? null], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok'=>false,'class'=>$exception::class,'message'=>$exception->getMessage()], JSON_THROW_ON_ERROR);
}
