<?php
require __DIR__ . '/inc.php';

$db = db();
$u = uporabnik();
$napaka = '';
$odpriObrazec = false;

/* =====================================
   OBDELAVA OBRAZCEV (le za prijavljene)
   ===================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('err', 'Slika je prevelika (največ 5 MB).');
        preusmeri(DOMACA);
    }

    preveriCsrf();

    if (!$u) {
        zahtevajPrijavo();
    }

    $akcija = (string)($_POST['akcija'] ?? '');

    /* ---------------------------------
       OBJAVA OGLASA
       Ime in e-naslov vzame iz računa.
       --------------------------------- */

    if ($akcija === 'oglas') {

        $naslov = postText('naslov', 80);
        $opis = postText('opis', 300);

        $vrsta = in_array($_POST['vrsta'] ?? '', VRSTE, true)
            ? $_POST['vrsta'] : 'Prodaja';

        $kat = in_array($_POST['kat'] ?? '', KAT, true)
            ? $_POST['kat'] : 'Ostalo';

        $stanje = in_array($_POST['stanje'] ?? '', STANJA, true)
            ? $_POST['stanje'] : 'Rabljeno';

        $cena = 0.0;

        if ($naslov === '') {
            $napaka = 'Naslov oglasa je obvezen.';

        } elseif ($vrsta === 'Prodaja') {
            $cenaVnos = str_replace(',', '.', (string)($_POST['cena'] ?? '0'));

            if (!is_numeric($cenaVnos)
                || (float)$cenaVnos < 0
                || (float)$cenaVnos > 100000) {
                $napaka = 'Vnesi veljavno ceno.';
            } else {
                $cena = (float)$cenaVnos;
            }
        }

        $slika = null;

        if ($napaka === '') {
            $rez = shraniSliko('slika');

            if (is_string($rez)) {
                $napaka = $rez;
            } elseif (is_array($rez)) {
                $slika = $rez['ime'];
            }
        }

        if ($napaka === '') {
            try {
                $st = $db->prepare(
                    'INSERT INTO oglas
                     (uporabnik_id, naslov, vrsta, kat, stanje, cena, opis, ts, slika)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    (int)$u['id'],
                    $naslov,
                    $vrsta,
                    $kat,
                    $stanje,
                    $cena,
                    $opis,
                    time(),
                    $slika
                ]);

                flash('ok', 'Oglas je uspešno objavljen!');
                preusmeri(DOMACA);

            } catch (Throwable $e) {
                izbrisiSliko($slika);
                error_log($e->getMessage());
                $napaka = 'Napaka pri shranjevanju oglasa.';
            }
        }

        $odpriObrazec = true;
    }

    /* ---------------------------------
       SPOROČILO PRODAJALCU
       Ustvari (ali nadaljuje) pogovor v predalniku.
       --------------------------------- */

    elseif ($akcija === 'povprasevanje') {

        $id = (int)($_POST['oglas_id'] ?? 0);
        $sp = postText('sporocilo', 1000);

        $st = $db->prepare(
            'SELECT o.id, o.uporabnik_id, o.naslov, p.email AS prodajalec_email
             FROM oglas o
             JOIN uporabnik p ON p.id = o.uporabnik_id
             WHERE o.id = ?'
        );
        $st->execute([$id]);
        $oglas = $st->fetch();

        if (!$oglas) {
            flash('err', 'Oglas ne obstaja.');
            preusmeri(DOMACA);
        }

        if ((int)$oglas['uporabnik_id'] === (int)$u['id']) {
            flash('err', 'Na svoj oglas ne moreš poslati sporočila.');
            preusmeri(DOMACA);
        }

        if ($sp === '') {
            flash('err', 'Napiši sporočilo prodajalcu.');
            preusmeri(DOMACA);
        }

        try {
            $db->beginTransaction();
            $pid = pogovorZaOglas($oglas, (int)$u['id']);
            posljiSporocilo($pid, (int)$u['id'], $sp);
            $db->commit();

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log($e->getMessage());
            flash('err', 'Napaka pri pošiljanju sporočila.');
            preusmeri(DOMACA);
        }

        obvestiEmail(
            $oglas['prodajalec_email'],
            $u['uporabnisko_ime'],
            $oglas['naslov'],
            $pid
        );

        flash(
            'ok',
            'Sporočilo je poslano prodajalcu. Odgovor boš dobil v predalniku.',
            'Sporočilo poslano'
        );
        preusmeri('sporocila.php?id=' . $pid);
    }

    /* ---------------------------------
       BRISANJE OGLASA
       Lastnik ali skrbnik.
       --------------------------------- */

    elseif ($akcija === 'brisi') {

        $id = (int)($_POST['oglas_id'] ?? 0);

        // Kam se vrnemo po brisanju (le dovoljene strani).
        $nazaj = ($_POST['nazaj'] ?? '') === 'moj-racun' ? 'moj-racun.php' : DOMACA;

        $st = $db->prepare('SELECT uporabnik_id, naslov, slika FROM oglas WHERE id = ?');
        $st->execute([$id]);
        $oglas = $st->fetch();

        if (!$oglas) {
            flash('err', 'Oglas ne obstaja.');

        } elseif ((int)$oglas['uporabnik_id'] === (int)$u['id']
                  || !empty($u['je_admin'])) {
            try {
                $st = $db->prepare('DELETE FROM oglas WHERE id = ?');
                $st->execute([$id]);
                izbrisiSliko($oglas['slika'] ?? null);

                flash(
                    'del',
                    'Oglas »' . $oglas['naslov'] . '« je bil izbrisan. '
                    . 'Pogovori o njem ostanejo v predalniku.',
                    'Oglas izbrisan'
                );

            } catch (Throwable $e) {
                error_log($e->getMessage());
                flash('err', 'Napaka pri brisanju oglasa.');
            }

        } else {
            flash('err', 'Tega oglasa ne smeš izbrisati.');
        }

        preusmeri($nazaj);
    }
}

/* =====================================
   ISKANJE IN FILTRIRANJE
   ===================================== */

$q = trim((string)($_GET['q'] ?? ''));
$fk = (string)($_GET['kat'] ?? '');
$fs = (string)($_GET['stanje'] ?? '');
$fv = (string)($_GET['vrsta'] ?? '');

$sql = 'SELECT o.*, a.uporabnisko_ime AS avtor
        FROM oglas o
        JOIN uporabnik a ON a.id = o.uporabnik_id
        WHERE 1=1';
$params = [];

if ($q !== '') {
    $sql .= ' AND (o.naslov LIKE ? OR o.opis LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if (in_array($fk, KAT, true)) {
    $sql .= ' AND o.kat = ?';
    $params[] = $fk;
}

if (in_array($fs, STANJA, true)) {
    $sql .= ' AND o.stanje = ?';
    $params[] = $fs;
}

if (in_array($fv, VRSTE, true)) {
    $sql .= ' AND o.vrsta = ?';
    $params[] = $fv;
}

$st = $db->prepare($sql . ' ORDER BY o.ts DESC, o.id DESC LIMIT 200');
$st->execute($params);
$oglasi = $st->fetchAll();

$skupaj = count($oglasi);
$filtriran = ($q !== '' || $fk !== '' || $fs !== '' || $fv !== '');
$jeAdmin = $u && !empty($u['je_admin']);

// Oglasi, pri katerih ima prijavljeni kupec že odprt pogovor.
$mojiPog = [];

if ($u) {
    $st = $db->prepare(
        'SELECT oglas_id, id FROM pogovor
         WHERE kupec_id = ? AND oglas_id IS NOT NULL'
    );
    $st->execute([(int)$u['id']]);

    foreach ($st->fetchAll() as $r) {
        $mojiPog[(int)$r['oglas_id']] = (int)$r['id'];
    }
}

glava();
?>

<?php if ($napaka !== ''): ?>
<div class="msg err" role="alert"><?= h($napaka) ?></div>
<?php endif; ?>

<form class="search" method="get" action="<?= h(DOMACA) ?>">
    <input name="q" value="<?= h($q) ?>" placeholder="🔍 Išči oglase..." maxlength="100" aria-label="Iskanje">

    <select name="kat" aria-label="Kategorija">
        <option value="">Vse kategorije</option>
        <?php foreach (KAT as $k): ?>
        <option value="<?= h($k) ?>" <?= $fk === $k ? 'selected' : '' ?>><?= h($k) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="stanje" aria-label="Stanje">
        <option value="">Novo in rabljeno</option>
        <?php foreach (STANJA as $k): ?>
        <option value="<?= h($k) ?>" <?= $fs === $k ? 'selected' : '' ?>><?= h($k) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="vrsta" aria-label="Vrsta">
        <option value="">Prodaja in izmenjava</option>
        <?php foreach (VRSTE as $k): ?>
        <option value="<?= h($k) ?>" <?= $fv === $k ? 'selected' : '' ?>><?= h($k) ?></option>
        <?php endforeach; ?>
    </select>

    <button type="submit">Išči</button>
</form>

<?php if ($u): ?>
<details class="add" <?= $odpriObrazec ? 'open' : '' ?>>
<summary>+ Objavi oglas</summary>

<form method="post" action="<?= h(DOMACA) ?>" enctype="multipart/form-data">
    <?= csrfPolje() ?>
    <input type="hidden" name="akcija" value="oglas">

    <p class="m full">Objavljaš kot <strong><?= h($u['uporabnisko_ime']) ?></strong> (<?= h($u['email']) ?>).</p>

    <input class="full" name="naslov" value="<?= h($_POST['naslov'] ?? '') ?>" placeholder="Naslov oglasa" aria-label="Naslov oglasa" required maxlength="80">

    <select name="vrsta" aria-label="Vrsta oglasa">
        <?php foreach (VRSTE as $k): ?>
        <option value="<?= h($k) ?>"><?= h($k) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="kat" aria-label="Kategorija">
        <?php foreach (KAT as $k): ?>
        <option value="<?= h($k) ?>"><?= h($k) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="stanje" aria-label="Stanje">
        <?php foreach (STANJA as $k): ?>
        <option value="<?= h($k) ?>"><?= h($k) ?></option>
        <?php endforeach; ?>
    </select>

    <input name="cena" type="number" min="0" step="0.01" value="<?= h($_POST['cena'] ?? '0') ?>" placeholder="Cena v € (le pri prodaji)" aria-label="Cena v €">

    <textarea class="full" name="opis" rows="3" maxlength="300" placeholder="Opis ali kaj iščeš v zameno" aria-label="Opis"><?= h($_POST['opis'] ?? '') ?></textarea>

    <label class="full slika-polje">
        <span>📷 Slika (neobvezno, JPG/PNG/WebP, do 5 MB)</span>
        <input type="file" name="slika" accept="image/jpeg,image/png,image/webp" onchange="if(this.files[0]&&this.files[0].size>5242880){alert('Slika je prevelika (največ 5 MB).');this.value='';}">
    </label>

    <button class="full" type="submit">Objavi oglas</button>
</form>
</details>
<?php else: ?>
<div class="hint">
    <strong>Želiš objaviti oglas ali poslati sporočilo prodajalcu?</strong>
    <a href="prijava.php">Prijavi se</a> ali
    <a href="registracija.php">ustvari račun</a> –
    ime in e-naslov se izpolnita samodejno.
</div>
<?php endif; ?>

<div class="count">
    <?= $skupaj ?> <?= $skupaj === 1 ? 'oglas' : 'oglasov' ?>
    <?php if ($filtriran): ?>
        · <a href="<?= h(DOMACA) ?>">Počisti filtre</a>
    <?php endif; ?>
</div>

<section class="g">

<?php if (!$oglasi): ?>
<div class="empty">
    <p><strong>Ni oglasov.</strong></p>
    <p><?= $filtriran ? 'Poskusi z drugimi iskalnimi pogoji.' : 'Bodi prvi in objavi oglas!' ?></p>
</div>
<?php endif; ?>

<?php foreach ($oglasi as $o): ?>
<?php $lasten = $u && (int)$o['uporabnik_id'] === (int)$u['id']; ?>
<article class="c <?= $o['vrsta'] === 'Izmenjava' ? 'izm' : '' ?>">

    <?php if (!empty($o['slika']) && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $o['slika'])): ?>
    <a class="slika" href="<?= h(URL_SLIK . $o['slika']) ?>" target="_blank" rel="noopener">
        <img src="<?= h(URL_SLIK . $o['slika']) ?>" alt="Slika: <?= h($o['naslov']) ?>" loading="lazy">
    </a>
    <?php endif; ?>

    <h3><?= h($o['naslov']) ?></h3>

    <div class="tags">
        <span class="tag <?= $o['vrsta'] === 'Izmenjava' ? 'p' : '' ?>"><?= h($o['vrsta']) ?></span>
        <span class="tag n"><?= h($o['stanje']) ?></span>
        <span class="tag"><?= h($o['kat']) ?></span>
    </div>

    <?php if ($o['vrsta'] === 'Prodaja'): ?>
    <p class="price"><?= h(number_format((float)$o['cena'], 2, ',', '.')) ?> €</p>
    <?php endif; ?>

    <?php if (trim((string)$o['opis']) !== ''): ?>
    <p class="desc"><?= nl2br(h($o['opis'])) ?></p>
    <?php endif; ?>

    <div class="who">
        Objavil <strong><?= h($o['avtor']) ?></strong>
        · <?= h(date('j. n. Y', (int)$o['ts'])) ?>
    </div>

    <?php if ($lasten): ?>
    <div class="m">To je tvoj oglas.</div>

    <?php elseif ($u && isset($mojiPog[(int)$o['id']])): ?>
    <a class="btn btn-pink" href="sporocila.php?id=<?= (int)$mojiPog[(int)$o['id']] ?>">💬 Odpri pogovor</a>

    <?php elseif ($u): ?>
    <details>
    <summary><?= $o['vrsta'] === 'Prodaja' ? 'Želim kupiti' : 'Predlagaj zamenjavo' ?></summary>

    <form method="post" action="<?= h(DOMACA) ?>">
        <?= csrfPolje() ?>
        <input type="hidden" name="akcija" value="povprasevanje">
        <input type="hidden" name="oglas_id" value="<?= (int)$o['id'] ?>">

        <div class="m">Pošlješ kot <strong><?= h($u['uporabnisko_ime']) ?></strong>. Odgovor prejmeš v predalniku.</div>
        <textarea name="sporocilo" rows="2" maxlength="1000" placeholder="Napiši sporočilo prodajalcu" aria-label="Sporočilo" required></textarea>

        <button type="submit" class="btn-pink">Pošlji sporočilo</button>
    </form>
    </details>

    <?php else: ?>
    <div class="m"><a href="prijava.php">Prijavi se</a> za pošiljanje sporočil.</div>
    <?php endif; ?>

    <?php if ($lasten || $jeAdmin): ?>
    <form method="post" action="<?= h(DOMACA) ?>" onsubmit="return confirm('Res izbrišem ta oglas?')">
        <?= csrfPolje() ?>
        <input type="hidden" name="akcija" value="brisi">
        <input type="hidden" name="oglas_id" value="<?= (int)$o['id'] ?>">
        <button type="submit" class="btn-danger">Izbriši oglas</button>
    </form>
    <?php endif; ?>

</article>
<?php endforeach; ?>

</section>

<?php noga(); ?>
