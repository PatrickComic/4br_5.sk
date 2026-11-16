<?php
/* =====================================
   pat.si – skupna koda
   (seja, baza, uporabnik, pomožne funkcije, glava in noga strani)
   ===================================== */

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- Nastavitve ---------- */

date_default_timezone_set('Europe/Ljubljana');

const DB_DATOTEKA = __DIR__ . '/pat_baza.sqlite';
const SQL_DATOTEKA = __DIR__ . '/baza.sql';
const DOMACA = 'pat.si.php';
const OBVESTILA_EMAIL = 'info@pat.si';
const SPLETNO_MESTO = 'https://pat.si'; // za povezave v e-poštnih obvestilih

const KAT = [
    'Učbeniki',
    'Zvezki in papir',
    'Torbe in nahrbtniki',
    'Pisala in pribor',
    'Likovni material',
    'Športna oprema',
    'Ostalo'
];

const VRSTE = ['Prodaja', 'Izmenjava'];
const STANJA = ['Novo', 'Rabljeno'];

const MAPA_SLIK = __DIR__ . '/uploads';
const URL_SLIK = 'uploads/';
const MAX_SLIKA = 5 * 1024 * 1024; // 5 MB

/* ---------- Baza ---------- */

function db(): PDO {
    static $db = null;

    if ($db === null) {
        $db = new PDO('sqlite:' . DB_DATOTEKA);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA busy_timeout = 5000');

        // Če tabel za pogovore še ni (nova baza ali starejša različica),
        // izvedi baza.sql. Vsi stavki so IF NOT EXISTS, zato obstoječi
        // podatki ostanejo nedotaknjeni.
        $obstaja = $db->query(
            "SELECT name FROM sqlite_master
             WHERE type = 'table' AND name = 'pogovor'"
        )->fetchColumn();

        if (!$obstaja) {
            $sql = file_get_contents(SQL_DATOTEKA);

            if ($sql === false) {
                throw new RuntimeException('Manjka datoteka baza.sql');
            }

            $db->exec($sql);
        }

        // Starejša baza: dodaj stolpec za sliko oglasa (obstoječi oglasi ostanejo).
        $imaSliko = false;

        foreach ($db->query('PRAGMA table_info(oglas)')->fetchAll() as $stolpec) {
            if ($stolpec['name'] === 'slika') {
                $imaSliko = true;
                break;
            }
        }

        if (!$imaSliko) {
            $db->exec('ALTER TABLE oglas ADD COLUMN slika TEXT');
        }

        // Stara povpraševanja (profil) preseli v predalnik.
        preseliPovprasevanja($db);
    }

    return $db;
}

/* ---------- Pomožne funkcije ---------- */

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postText(string $key, int $max = 200): string {
    $v = $_POST[$key] ?? '';
    $v = is_string($v) ? trim($v) : '';

    return function_exists('mb_substr')
        ? mb_substr($v, 0, $max, 'UTF-8')
        : substr($v, 0, $max);
}

/* ---------- Slike oglasov ---------- */

// Preveri in shrani naloženo sliko. Vrne ime datoteke, null (slike ni)
// ali niz z opisom napake.
function shraniSliko(string $polje = 'slika') {
    if (empty($_FILES[$polje]) || ($_FILES[$polje]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $f = $_FILES[$polje];

    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        return 'Slika je prevelika (največ 5 MB).';
    }

    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return 'Slike ni bilo mogoče naložiti. Poskusi znova.';
    }

    if ($f['size'] > MAX_SLIKA) {
        return 'Slika je prevelika (največ 5 MB).';
    }

    $info = @getimagesize($f['tmp_name']);
    $dovoljeno = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp'
    ];

    if (!$info || !isset($dovoljeno[$info[2]])) {
        return 'Dovoljene so slike JPG, PNG ali WebP.';
    }

    if (!is_dir(MAPA_SLIK) && !@mkdir(MAPA_SLIK, 0755, true)) {
        error_log('Ne morem ustvariti mape ' . MAPA_SLIK);
        return 'Slike trenutno ni mogoče shraniti.';
    }

    $ime = bin2hex(random_bytes(12)) . '.' . $dovoljeno[$info[2]];

    if (!move_uploaded_file($f['tmp_name'], MAPA_SLIK . '/' . $ime)) {
        error_log('Neuspel move_uploaded_file za ' . $ime);
        return 'Slike trenutno ni mogoče shraniti.';
    }

    return ['ime' => $ime];
}

function izbrisiSliko(?string $ime): void {
    if ($ime !== null && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $ime)) {
        @unlink(MAPA_SLIK . '/' . $ime);
    }
}

function preusmeri(string $url) {
    header('Location: ' . $url);
    exit;
}

/* ---------- CSRF zaščita ---------- */

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function csrfPolje(): string {
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function preveriCsrf(): void {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Neveljavna seja. Osveži stran.');
    }
}

/* ---------- Sporočila (prikažejo se po preusmeritvi) ---------- */

function flash(string $tip, string $besedilo, string $naslov = ''): void {
    $_SESSION['flash'] = [$tip, $besedilo, $naslov];
}

function vzemiFlash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Prijavljen uporabnik ---------- */

function uporabnik(): ?array {
    static $u = false;

    if ($u === false) {
        $u = null;

        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare(
                'SELECT id, uporabnisko_ime, email, je_admin, datum_registracije
                 FROM uporabnik WHERE id = ?'
            );
            $st->execute([(int)$_SESSION['uid']]);
            $r = $st->fetch();

            if ($r) {
                $u = $r;
            } else {
                unset($_SESSION['uid']);
            }
        }
    }

    return $u;
}

function zahtevajPrijavo() {
    flash('err', 'Za to dejanje se moraš prijaviti.');
    preusmeri('prijava.php');
}

/* ---------- Predalnik sporočil ---------- */

function steviloNeprebranih(int $uid): int {
    $st = db()->prepare(
        'SELECT COUNT(*)
         FROM sporocilo s
         JOIN pogovor p ON p.id = s.pogovor_id
         WHERE s.prebrano = 0
           AND s.posiljatelj_id <> ?
           AND (p.kupec_id = ? OR p.prodajalec_id = ?)'
    );
    $st->execute([$uid, $uid, $uid]);

    return (int)$st->fetchColumn();
}

// Vrne id pogovora za oglas in kupca (ustvari ga, če ga še ni).
function pogovorZaOglas(array $oglas, int $kupecId): int {
    $db = db();

    $st = $db->prepare(
        'SELECT id FROM pogovor WHERE oglas_id = ? AND kupec_id = ?'
    );
    $st->execute([(int)$oglas['id'], $kupecId]);
    $id = $st->fetchColumn();

    if ($id) {
        return (int)$id;
    }

    $st = $db->prepare(
        'INSERT INTO pogovor
         (oglas_id, oglas_naslov, kupec_id, prodajalec_id, ts_zadnje)
         VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([
        (int)$oglas['id'],
        (string)$oglas['naslov'],
        $kupecId,
        (int)$oglas['uporabnik_id'],
        time()
    ]);

    return (int)$db->lastInsertId();
}

function posljiSporocilo(int $pogovorId, int $posiljateljId, string $besedilo): void {
    $db = db();
    $lastna = !$db->inTransaction();

    if ($lastna) {
        $db->beginTransaction();
    }

    try {
        $ts = time();

        $st = $db->prepare(
            'INSERT INTO sporocilo (pogovor_id, posiljatelj_id, besedilo, ts, prebrano)
             VALUES (?, ?, ?, ?, 0)'
        );
        $st->execute([$pogovorId, $posiljateljId, $besedilo, $ts]);

        $st = $db->prepare('UPDATE pogovor SET ts_zadnje = ? WHERE id = ?');
        $st->execute([$ts, $pogovorId]);

        if ($lastna) {
            $db->commit();
        }

    } catch (Throwable $e) {
        if ($lastna && $db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }
}

// E-poštno obvestilo o novem sporočilu (brez e-naslova pošiljatelja).
function obvestiEmail(string $komu, string $od, string $oglasNaslov, int $pogovorId): void {
    $telo =
        "Novo sporočilo od {$od} (oglas: {$oglasNaslov}).\n\n" .
        "Preberi in odgovori v predalniku:\n" .
        SPLETNO_MESTO . '/sporocila.php?id=' . $pogovorId . "\n";

    @mail(
        $komu,
        '=?UTF-8?B?' . base64_encode('pat.si – novo sporočilo') . '?=',
        $telo,
        'From: pat.si <' . OBVESTILA_EMAIL . ">\r\n" .
        "Content-Type: text/plain; charset=UTF-8"
    );
}

// Enkratna preselitev starih povpraševanj (tabela povprasevanje) v pogovore.
function preseliPovprasevanja(PDO $db): void {
    $je = $db->query(
        "SELECT name FROM sqlite_master
         WHERE type = 'table' AND name = 'povprasevanje'"
    )->fetchColumn();

    if (!$je) {
        return;
    }

    try {
        $db->exec('BEGIN IMMEDIATE');

        $se = $db->query(
            "SELECT name FROM sqlite_master
             WHERE type = 'table' AND name = 'povprasevanje'"
        )->fetchColumn();

        if ($se) {
            $vrstice = $db->query(
                'SELECT p.oglas_id, p.uporabnik_id AS kupec, p.sporocilo, p.ts,
                        o.uporabnik_id AS prodajalec, o.naslov
                 FROM povprasevanje p
                 JOIN oglas o ON o.id = p.oglas_id
                 ORDER BY p.ts, p.id'
            )->fetchAll();

            $najdi = $db->prepare(
                'SELECT id FROM pogovor WHERE oglas_id = ? AND kupec_id = ?'
            );
            $nov = $db->prepare(
                'INSERT INTO pogovor
                 (oglas_id, oglas_naslov, kupec_id, prodajalec_id, ts_zadnje)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $sp = $db->prepare(
                'INSERT INTO sporocilo (pogovor_id, posiljatelj_id, besedilo, ts, prebrano)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $zadnje = $db->prepare('UPDATE pogovor SET ts_zadnje = ? WHERE id = ?');

            foreach ($vrstice as $r) {
                $najdi->execute([$r['oglas_id'], $r['kupec']]);
                $pid = $najdi->fetchColumn();

                if (!$pid) {
                    $nov->execute([
                        $r['oglas_id'], $r['naslov'],
                        $r['kupec'], $r['prodajalec'], $r['ts']
                    ]);
                    $pid = (int)$db->lastInsertId();
                }

                $besedilo = trim((string)$r['sporocilo']);

                if ($besedilo === '') {
                    $besedilo = 'Zanima me ta oglas.';
                }

                $sp->execute([$pid, $r['kupec'], $besedilo, $r['ts']]);
                $zadnje->execute([$r['ts'], $pid]);
            }

            $db->exec('DROP TABLE povprasevanje');
        }

        $db->exec('COMMIT');

    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable $x) {
            // transakcija ni bila odprta
        }

        error_log($e->getMessage());
    }
}

function crka(string $s): string {
    $c = function_exists('mb_substr') ? mb_substr($s, 0, 1, 'UTF-8') : substr($s, 0, 1);

    return function_exists('mb_strtoupper') ? mb_strtoupper($c, 'UTF-8') : strtoupper($c);
}

function skrajsaj(string $s, int $n): string {
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));

    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8') > $n
            ? mb_substr($s, 0, $n - 1, 'UTF-8') . '…'
            : $s;
    }

    return strlen($s) > $n ? substr($s, 0, $n - 1) . '…' : $s;
}

function casKratko(int $ts): string {
    return date('Y-m-d', $ts) === date('Y-m-d') ? date('H:i', $ts) : date('j. n.', $ts);
}

/* ---------- Glava strani ---------- */

function glava(string $naslov = 'pat.si – šolske potrebščine'): void {
    $u = uporabnik();
    $f = vzemiFlash();
    $neprebrana = $u ? steviloNeprebranih((int)$u['id']) : 0;

    $ikone = ['ok' => '✓', 'err' => '!', 'del' => '🗑', 'info' => 'i'];
    $naslovi = ['ok' => 'Uspešno', 'err' => 'Napaka', 'del' => 'Izbrisano', 'info' => 'Obvestilo'];

    $tip = 'info';
    $tNaslov = '';
    $tBesedilo = '';

    if ($f) {
        $tip = isset($ikone[$f[0]]) ? $f[0] : 'info';
        $tNaslov = ($f[2] ?? '') !== '' ? (string)$f[2] : $naslovi[$tip];
        $tBesedilo = (string)$f[1];
    }
    ?>
<!DOCTYPE html>
<html lang="sl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($naslov) ?></title>
<meta name="description" content="Kupi, prodaj ali zamenjaj šolske potrebščine – nove ali rabljene.">
<link rel="icon" href="logo.png" type="image/png">
<link rel="stylesheet" href="stil.css">
</head>

<body>

<?php if ($f): ?>
<div class="toast toast-<?= h($tip) ?>" role="<?= $tip === 'err' ? 'alert' : 'status' ?>">
    <span class="toast-ico" aria-hidden="true"><?= $ikone[$tip] ?></span>
    <div class="toast-t">
        <strong><?= h($tNaslov) ?></strong>
        <span><?= h($tBesedilo) ?></span>
    </div>
    <button type="button" class="toast-x" aria-label="Zapri obvestilo" onclick="this.parentNode.remove()">×</button>
    <i class="toast-bar" aria-hidden="true"></i>
</div>
<?php endif; ?>

<header class="top">
<div class="hw">
    <div class="brand">
        <a class="logo" href="<?= h(DOMACA) ?>" title="pat.si – domača stran">
            <img src="logo.png" alt="pat.si – šolske potrebščine">
        </a>

        <div class="tagline">
            <h1>Šolske potrebščine na enem mestu</h1>
            <p>Kupi, prodaj ali zamenjaj – nove ali rabljene.</p>
        </div>
    </div>

    <nav class="nav">
        <?php if ($u): ?>
        <a href="sporocila.php" class="nav-msg" title="Sporočila">✉ Sporočila<?php if ($neprebrana > 0): ?> <span class="bdg"><?= $neprebrana > 99 ? '99+' : (int)$neprebrana ?></span><?php endif; ?></a>
        <a href="moj-racun.php" class="nav-user">👤 <?= h($u['uporabnisko_ime']) ?></a>
        <a href="odjava.php" class="btn btn-ghost">Odjava</a>
        <?php else: ?>
        <a href="prijava.php" class="btn btn-ghost">Prijava</a>
        <a href="registracija.php" class="btn">Registracija</a>
        <?php endif; ?>
    </nav>
</div>
</header>

<main class="w">

<?php
}

/* ---------- Noga strani ---------- */

function noga(): void {
    ?>
</main>

<footer>
<div class="w">
    <span>© <?= date('Y') ?> <a href="<?= h(DOMACA) ?>">pat.si</a> – prodaja in izmenjava šolskih potrebščin.</span>
</div>
</footer>

</body>
</html>
<?php
}
