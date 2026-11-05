<?php
require __DIR__ . '/config.php';

// Endpoint fallback penghapusan transaksi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $no = strtoupper(trim($_POST['no_transaksi'] ?? ''));

    if ($no !== '') {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM TRMUTASIDT WHERE NO_TRANSAKSI = ?')->execute([$no]);
        $st = $pdo->prepare('DELETE FROM TRMUTASIHD WHERE NO_TRANSAKSI = ?');
        $st->execute([$no]);
        $pdo->commit();

        header('Location: form.php?msg=' . ($st->rowCount() ? 'deleted' : 'notfound'));
        exit;
    }
}

header('Location: form.php');
exit;
