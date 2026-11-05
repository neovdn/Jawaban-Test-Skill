<?php

require_once __DIR__ . '/config.php';

// koneksi database 
$pdo = db();

// Dipakai untuk dropdown & validasi kategori
$barang = $pdo->query(
    'SELECT KODE_BARANG, NAMA_BARANG, SATUAN, KATEGORI FROM MSBARANG ORDER BY KODE_BARANG'
)->fetchAll();
$barangMap = array_column($barang, null, 'KODE_BARANG');

// Daftar kategori
$kategoriList = array_values(array_unique(array_column($barang, 'KATEGORI')));
usort($kategoriList, fn($a, $b) => $a === 'SUBMAT' ? -1 : ($b === 'SUBMAT' ? 1 : strcmp($a, $b)));
$jenisList = ['MASUK', 'KELUAR'];

$errors  = [];
$success = null;

// No. Transaksi
$noParam = strtoupper(trim($_GET['no'] ?? ''));

// Helper nomor transaksi
if (!function_exists('nextNoTransaksi')) {
    function nextNoTransaksi(PDO $pdo): string
    {
        $max = $pdo->query(
            "SELECT MAX(CAST(SUBSTRING(NO_TRANSAKSI, 2) AS UNSIGNED))
               FROM TRMUTASIHD
              WHERE NO_TRANSAKSI REGEXP '^T[0-9]+$'"
        )->fetchColumn();
        return sprintf('T%03d', ((int) $max) + 1);
    }
}

// Nilai default form
$data = [
    'no'       => '',
    'tanggal'  => date('Y-m-d'),
    'jenis'    => 'MASUK',
    'kategori' => $kategoriList[0] ?? 'SUBMAT',
    'items'    => [],
];

// Pesan kilat dari redirect setelah simpan/hapus berhasil
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'deleted') $success = 'Transaksi berhasil dihapus.';
    else                            $success = 'Transaksi berhasil disimpan.';
}

// POST add, update, delete
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action   = $_POST['action'] ?? '';
    $rawNo    = strtoupper(trim($_POST['no_transaksi'] ?? ''));
    $tanggal  = trim($_POST['tanggal'] ?? '');
    $jenis    = trim($_POST['jenis_transaksi'] ?? '');
    $kategori = trim($_POST['kategori'] ?? '');

    $kodes = $_POST['kode_barang'] ?? [];
    $qtys  = $_POST['qty'] ?? [];
    $items = [];
    if (is_array($kodes) && is_array($qtys)) {
        foreach ($kodes as $i => $k) {
            $k = trim((string) $k);
            if ($k !== '') {
                $items[] = ['kode' => $k, 'qty' => trim((string) ($qtys[$i] ?? '1'))];
            }
        }
    }

    $data = array_merge($data, compact('tanggal', 'jenis', 'kategori', 'items') + ['no' => $rawNo]);

    if ($action === 'simpan') {
        $st = $rawNo !== '' ? $pdo->prepare('SELECT 1 FROM TRMUTASIHD WHERE NO_TRANSAKSI = ?') : null;
        if ($st) $st->execute([$rawNo]);
        $action = ($st && $st->fetchColumn()) ? 'koreksi' : 'tambah';
    }

    if ($action === 'hapus') {
        if ($rawNo === '') {
            $errors[] = 'Silakan pilih atau isi No. Transaksi yang ingin dihapus.';
        } else {
            $st = $pdo->prepare('SELECT 1 FROM TRMUTASIHD WHERE NO_TRANSAKSI = ?');
            $st->execute([$rawNo]);
            if (!$st->fetchColumn()) {
                $errors[] = "Transaksi $rawNo tidak ditemukan di database.";
            } else {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM TRMUTASIDT WHERE NO_TRANSAKSI = ?')->execute([$rawNo]);
                    $pdo->prepare('DELETE FROM TRMUTASIHD WHERE NO_TRANSAKSI = ?')->execute([$rawNo]);
                    $pdo->commit();
                    header('Location: form.php?msg=deleted');
                    exit;
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = 'Gagal menghapus data: ' . $ex->getMessage();
                }
            }
        }
    }

    if ($action === 'tambah' || $action === 'koreksi') {
        $st = $pdo->prepare('SELECT 1 FROM TRMUTASIHD WHERE NO_TRANSAKSI = ?');
        $st->execute([$rawNo]);
        $existsInDb = ($rawNo !== '') && (bool) $st->fetchColumn();

        if ($existsInDb) {
            $isEdit   = true;
            $targetNo = $rawNo;
        } else {
            $isEdit = false;
            if ($rawNo === '') {
                $targetNo = nextNoTransaksi($pdo);
            } else {
                if (strlen($rawNo) > 5 || !preg_match('/^[A-Z0-9]+$/', $rawNo)) {
                    $errors[] = 'No. Transaksi maksimal 5 karakter alfanumerik.';
                }
                $targetNo = $rawNo;
            }
        }

        // validasi input
        $dt = DateTime::createFromFormat('Y-m-d', $tanggal);
        if (!$dt || $dt->format('Y-m-d') !== $tanggal) $errors[] = 'Tanggal tidak valid.';

        if (!in_array($jenis, $jenisList, true))      $errors[] = 'Jenis transaksi harus MASUK atau KELUAR.';
        if (!in_array($kategori, $kategoriList, true)) $errors[] = 'Kategori transaksi tidak valid.';
        if (count($items) === 0)                       $errors[] = 'Minimal harus menambahkan 1 item barang pada tabel detail.';

        $seen = [];
        foreach ($items as $idx => $it) {
            $line = $idx + 1;
            if (!isset($barangMap[$it['kode']])) {
                $errors[] = "Baris #$line: barang belum dipilih / tidak valid.";
                continue;
            }
            if ($barangMap[$it['kode']]['KATEGORI'] !== $kategori) {
                $errors[] = "Baris #$line: barang {$it['kode']} memiliki kategori {$barangMap[$it['kode']]['KATEGORI']}, berbeda dengan kategori transaksi ($kategori).";
            }
            if (isset($seen[$it['kode']])) {
                $errors[] = "Baris #$line: barang {$it['kode']} diinput ganda.";
            }
            $seen[$it['kode']] = true;

            if (!ctype_digit((string) $it['qty']) || (int) $it['qty'] < 1) {
                $errors[] = "Baris #$line: Qty harus bilangan bulat minimal 1.";
            }
        }

        // write database
        if (!$errors) {
            try {
                $pdo->beginTransaction();

                if ($isEdit) {
                            $pdo->prepare('UPDATE TRMUTASIHD SET TANGGAL = ?, JENIS_TRANSAKSI = ?, KATEGORI = ? WHERE NO_TRANSAKSI = ?')
                        ->execute([$tanggal, $jenis, $kategori, $targetNo]);
                    $pdo->prepare('DELETE FROM TRMUTASIDT WHERE NO_TRANSAKSI = ?')->execute([$targetNo]);
                } else {
                    $pdo->prepare('INSERT INTO TRMUTASIHD (NO_TRANSAKSI, TANGGAL, JENIS_TRANSAKSI, KATEGORI) VALUES (?, ?, ?, ?)')
                        ->execute([$targetNo, $tanggal, $jenis, $kategori]);
                }

                $st = $pdo->prepare('INSERT INTO TRMUTASIDT (NO_TRANSAKSI, KODE_BARANG, QTY) VALUES (?, ?, ?)');
                foreach ($items as $it) {
                    $st->execute([$targetNo, $it['kode'], (int) $it['qty']]);
                }

                $pdo->commit();
                $msgParam = $isEdit ? 'updated' : 'saved';
                header('Location: form.php?no=' . urlencode($targetNo) . '&msg=' . $msgParam);
                exit;
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Gagal menyimpan transaksi: ' . $ex->getMessage();
            }
        }
    }
// GET transaksi
} elseif ($noParam !== '') {
    $st = $pdo->prepare('SELECT * FROM TRMUTASIHD WHERE NO_TRANSAKSI = ?');
    $st->execute([$noParam]);
    $hd = $st->fetch();
    if ($hd) {
        $data['no']       = $hd['NO_TRANSAKSI'];
        $data['tanggal']  = $hd['TANGGAL'];
        $data['jenis']    = $hd['JENIS_TRANSAKSI'];
        $data['kategori'] = $hd['KATEGORI'];

        $st = $pdo->prepare('SELECT KODE_BARANG, QTY FROM TRMUTASIDT WHERE NO_TRANSAKSI = ? ORDER BY KODE_BARANG');
        $st->execute([$noParam]);
        foreach ($st->fetchAll() as $d) {
            $data['items'][] = ['kode' => $d['KODE_BARANG'], 'qty' => (int) $d['QTY']];
        }
    }
}

$transaksiList = $pdo->query(
    'SELECT h.NO_TRANSAKSI, h.TANGGAL, h.JENIS_TRANSAKSI, h.KATEGORI,
            COUNT(d.KODE_BARANG) AS JML_ITEM,
            COALESCE(SUM(d.QTY), 0) AS TOTAL_QTY
       FROM TRMUTASIHD h
       LEFT JOIN TRMUTASIDT d ON d.NO_TRANSAKSI = h.NO_TRANSAKSI
      GROUP BY h.NO_TRANSAKSI, h.TANGGAL, h.JENIS_TRANSAKSI, h.KATEGORI
      ORDER BY h.NO_TRANSAKSI ASC'
)->fetchAll();

$isEditMode = false;
if ($data['no'] !== '') {
    $st = $pdo->prepare('SELECT 1 FROM TRMUTASIHD WHERE NO_TRANSAKSI = ?');
    $st->execute([$data['no']]);
    $isEditMode = (bool) $st->fetchColumn();
}
$nextNo = nextNoTransaksi($pdo);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transaksi Barang - PT. Indospring Tbk</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page-container app-layout">

    <!-- Panel Kiri: Daftar Transaksi -->
    <aside class="sidebar-panel">
        <div class="sidebar-header">
            <div class="sidebar-title-group">
                <h2 class="sidebar-title">Daftar Transaksi</h2>
                <span class="sidebar-count"><?= count($transaksiList) ?></span>
            </div>
            <a href="form.php" class="btn-new-transaksi" title="Buat transaksi baru">+ Baru</a>
        </div>

        <div class="sidebar-search-wrap">
            <input type="text" id="sidebar-search" class="sidebar-search-input" placeholder="Cari No. / Tanggal..." autocomplete="off">
        </div>

        <div class="sidebar-list">
            <?php if (!$transaksiList): ?>
                <div class="sidebar-empty">Belum ada transaksi</div>
            <?php endif; ?>
            <?php foreach ($transaksiList as $t): ?>
                <?php $isActive = ($t['NO_TRANSAKSI'] === $data['no']); ?>
                <a href="form.php?no=<?= urlencode($t['NO_TRANSAKSI']) ?>"
                   class="sidebar-item <?= $isActive ? 'is-active' : '' ?>"
                   title="Buka <?= e($t['NO_TRANSAKSI']) ?>">
                    <div class="sidebar-item-row">
                        <span class="sidebar-item-no"><?= e($t['NO_TRANSAKSI']) ?></span>
                        <span class="badge badge-<?= strtolower(e($t['JENIS_TRANSAKSI'])) ?>">
                            <?= e($t['JENIS_TRANSAKSI']) ?>
                        </span>
                    </div>
                    <div class="sidebar-item-meta">
                        <span><?= e(date('d/m/Y', strtotime($t['TANGGAL']))) ?></span>
                        <span class="item-separator">•</span>
                        <span><?= e($t['KATEGORI']) ?></span>
                    </div>
                    <div class="sidebar-item-sub">
                        <span><?= (int) $t['JML_ITEM'] ?> item (<?= (int) $t['TOTAL_QTY'] ?> pcs)</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- Form transaksi -->
    <main class="main-panel">

        <?php if ($success): ?>
            <div class="alert alert-success">
                <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <span><?= e($success) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <svg class="alert-icon" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card-form">
        <div class="form-header-bar">
            <div class="header-icon-box">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
                    <path d="m3.3 7 8.7 5 8.7-5"/>
                    <path d="M12 22V12"/>
                </svg>
            </div>
            <div class="header-separator"></div>
            <h1 class="header-title">Transaksi Barang</h1>
        </div>

        <form method="post" id="frmTransaksi" action="form.php">

            <div class="form-body">
                <div class="field-row">
                    <label class="field-label" for="no_transaksi">No. Transaksi <span class="req">*</span></label>
                    <div class="field-input-wrap">
                        <input type="text" id="no_transaksi" name="no_transaksi" maxlength="5"
                               value="<?= e($data['no']) ?>"
                               placeholder="Auto Generate / Input Manual"
                               autocomplete="off">
                    </div>
                </div>

                <div class="field-row">
                    <label class="field-label" for="tanggal">Tanggal <span class="req">*</span></label>
                    <div class="field-input-wrap">
                        <input type="date" id="tanggal" name="tanggal" value="<?= e($data['tanggal']) ?>" required>
                    </div>
                </div>

                <div class="field-row">
                    <label class="field-label" for="jenis_transaksi">Jenis Transaksi <span class="req">*</span></label>
                    <div class="field-input-wrap">
                        <select id="jenis_transaksi" name="jenis_transaksi" required>
                            <?php foreach ($jenisList as $j): ?>
                                <option value="<?= e($j) ?>" <?= $data['jenis'] === $j ? 'selected' : '' ?>><?= e($j) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <label class="field-label" for="kategori">Kategori <span class="req">*</span></label>
                    <div class="field-input-wrap">
                        <select id="kategori" name="kategori" required>
                            <?php foreach ($kategoriList as $k): ?>
                                <option value="<?= e($k) ?>" <?= $data['kategori'] === $k ? 'selected' : '' ?>><?= e($k) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="detail-section">
                    <div class="table-container">
                        <table class="detail-table" id="tbl-items">
                            <thead>
                            <tr>
                                <th class="col-chk">#</th>
                                <th class="col-kode">Kode Barang</th>
                                <th class="col-nama">Nama Barang</th>
                                <th class="col-satuan">Satuan</th>
                                <th class="col-qty">Qty</th>
                            </tr>
                            </thead>
                            <tbody id="items-tbody"></tbody>
                        </table>

                        <div class="empty-state" id="empty-state">
                            <div class="empty-icon-box">
                                <svg width="68" height="68" viewBox="0 0 64 64" fill="none" stroke="#9bb3d3" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 26 L32 36 L50 26 L32 16 Z" fill="#f8fafc" />
                                    <path d="M14 26 L14 46 L32 56 L32 36 Z" fill="#eef4fb" stroke="#7a9ecc" />
                                    <path d="M50 26 L50 46 L32 56 L32 36 Z" fill="#e2edfa" stroke="#7a9ecc" />
                                    <path d="M14 26 L5 18 L23 10 L32 16" stroke="#5a88c4" />
                                    <path d="M50 26 L59 18 L41 10 L32 16" stroke="#5a88c4" />
                                    <line x1="32" y1="6" x2="32" y2="10" stroke="#9bb3d3" stroke-width="1.6"/>
                                    <line x1="20" y1="7" x2="22" y2="10" stroke="#9bb3d3" stroke-width="1.6"/>
                                    <line x1="44" y1="7" x2="42" y2="10" stroke="#9bb3d3" stroke-width="1.6"/>
                                </svg>
                            </div>
                            <div class="empty-label">Belum ada item</div>
                        </div>
                    </div>

                    <div class="item-actions">
                        <button type="button" class="btn btn-add-item" id="btn-add-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            <span>Tambah Item (F7)</span>
                        </button>
                        <button type="button" class="btn btn-remove-item" id="btn-remove-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                            <span>Hapus Item</span>
                        </button>
                    </div>
                </div>

                <div class="form-bottom-actions">
                    <button type="submit" name="action" value="simpan" class="btn btn-action-simpan" title="Simpan Transaksi">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        <span>Simpan</span>
                    </button>

                    <button type="submit" name="action" value="hapus" class="btn btn-action-hapus"
                            onclick="return confirmHapus();" title="Hapus Transaksi">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                        </svg>
                        <span>Hapus</span>
                    </button>

                    <a href="form.php" class="btn btn-action-reset" title="Kosongkan form untuk transaksi baru">
                        <span>Form Baru</span>
                    </a>
                </div>
            </div>
        </form>
    </div>
    </main>
</div>

<script>
    const MASTER_BARANG  = <?= json_encode($barang, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const INITIAL_ITEMS  = <?= json_encode(array_map(fn($it) => ['kode' => $it['kode'], 'qty' => $it['qty']], $data['items']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const tbody       = document.getElementById('items-tbody');
    const emptyState  = document.getElementById('empty-state');
    const selKat      = document.getElementById('kategori');
    const selQuick    = document.getElementById('sel_quick_load');
    const inpNoTrans  = document.getElementById('no_transaksi');
    const counterHint = document.getElementById('kategori-counter');

    function getBarangByKategori(kat) {
        return MASTER_BARANG.filter(b => b.KATEGORI === kat);
    }

    function toggleEmptyState() {
        emptyState.style.display = tbody.children.length ? 'none' : 'flex';
    }

    function updateCounter() {
        const avail = getBarangByKategori(selKat.value);
        if (counterHint) {
            counterHint.textContent = `(${avail.length} barang tersedia)`;
            counterHint.title = avail.map(x => `${x.KODE_BARANG}: ${x.NAMA_BARANG}`).join('\n');
        }
    }

    function onSelectBarang(tr) {
        const kode   = tr.querySelector('select.kode-select').value;
        const found  = MASTER_BARANG.find(x => x.KODE_BARANG === kode);
        tr.querySelector('.cell-nama').textContent   = found ? found.NAMA_BARANG : '-';
        tr.querySelector('.cell-satuan').textContent = found ? found.SATUAN : '-';
    }

    function addRow(selectedKode = '', qtyVal = 1) {
        const tr = document.createElement('tr');

        const tdChk = document.createElement('td');
        tdChk.className = 'col-chk center';
        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.className = 'row-select-chk';
        tdChk.appendChild(chk);

        const tdKode = document.createElement('td');
        tdKode.className = 'col-kode';
        const select = document.createElement('select');
        select.name = 'kode_barang[]';
        select.className = 'kode-select';
        select.required = true;
        select.appendChild(new Option('-- Pilih Kode Barang --', ''));
        getBarangByKategori(selKat.value).forEach(b => {
            select.appendChild(new Option(`${b.KODE_BARANG} - ${b.NAMA_BARANG}`, b.KODE_BARANG));
        });
        select.value = selectedKode;
        select.addEventListener('change', () => onSelectBarang(tr));
        tdKode.appendChild(select);

        const tdNama = document.createElement('td');
        tdNama.className = 'col-nama cell-nama';
        tdNama.textContent = '-';

        const tdSatuan = document.createElement('td');
        tdSatuan.className = 'col-satuan cell-satuan center';
        tdSatuan.textContent = '-';

        const tdQty = document.createElement('td');
        tdQty.className = 'col-qty right';
        const inpQty = document.createElement('input');
        inpQty.type = 'number';
        inpQty.name = 'qty[]';
        inpQty.className = 'qty-input';
        inpQty.min = '1';
        inpQty.step = '1';
        inpQty.required = true;
        inpQty.value = qtyVal;
        tdQty.appendChild(inpQty);

        tr.append(tdChk, tdKode, tdNama, tdSatuan, tdQty);
        tbody.appendChild(tr);

        onSelectBarang(tr);
        toggleEmptyState();
    }

    function removeCheckedRows() {
        const rows = tbody.querySelectorAll('tr');
        let removed = 0;
        rows.forEach(tr => {
            const chk = tr.querySelector('.row-select-chk');
            if (chk && chk.checked) { tr.remove(); removed++; }
        });
        if (removed === 0 && rows.length > 0) rows[rows.length - 1].remove();
        toggleEmptyState();
    }

    function confirmHapus() {
        const no = inpNoTrans.value.trim();
        if (!no) {
            alert('Silakan pilih atau isi No. Transaksi yang ingin dihapus.');
            return false;
        }
        return confirm(`Yakin ingin menghapus Transaksi ${no} beserta seluruh detail barangnya?`);
    }

    document.getElementById('btn-add-item').addEventListener('click', () => addRow());
    document.getElementById('btn-remove-item').addEventListener('click', removeCheckedRows);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'F7') { e.preventDefault(); addRow(); }
    });

    let prevKat = selKat.value;
    selKat.addEventListener('change', () => {
        if (tbody.children.length > 0) {
            if (!confirm('Mengganti kategori akan mengosongkan daftar item di tabel. Lanjutkan?')) {
                selKat.value = prevKat;
                return;
            }
            tbody.innerHTML = '';
        }
        prevKat = selKat.value;
        updateCounter();
        toggleEmptyState();
    });

    if (selQuick) {
        selQuick.addEventListener('change', () => {
            window.location.href = selQuick.value
                ? 'form.php?no=' + encodeURIComponent(selQuick.value)
                : 'form.php';
        });
    }

    updateCounter();
    INITIAL_ITEMS.forEach(it => addRow(it.kode, it.qty));
    toggleEmptyState();

    // Filter live pencarian di sidebar
    const inpSearch = document.getElementById('sidebar-search');
    if (inpSearch) {
        inpSearch.addEventListener('input', (e) => {
            const q = e.target.value.toLowerCase().trim();
            document.querySelectorAll('.sidebar-item').forEach(item => {
                const text = item.textContent.toLowerCase();
                item.style.display = text.includes(q) ? 'block' : 'none';
            });
        });
    }

    // Auto-scroll ke transaksi yang sedang aktif di sidebar
    const activeItem = document.querySelector('.sidebar-item.is-active');
    if (activeItem) {
        activeItem.scrollIntoView({ block: 'nearest' });
    }
</script>
</body>
</html>
