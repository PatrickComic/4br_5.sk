<?php
require __DIR__ . '/inc.php';

$db = db();
$u = uporabnik();
$napaka = '';

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
       SPOROČILO PRODAJALCU
       Ustvari (ali nadaljuje) pogovor v predalniku.
       --------------------------------- */

    if ($akcija === 'povprasevanje') {

        $id = (int)($_POST['oglas_id'] ?? 0);
        $sp = postText('sporocilo', 1000);

        $st = $db->prepare(
            'SELECT o.id, o.uporabnik_id, o.naslov, p.email AS prodajalec_email
             FROM oglas o
             JOIN uporabnik p ON p.id = o.uporabnik_id
             WHERE o.id = ? AND o.moderacija = \'approved\''
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
        // Skrbniški posegi tudi na glavni strani uporabljajo enaka atomska pravila.
        $target = sqlVrstica('SELECT uporabnik_id FROM oglas WHERE id=?', [$id]);
        if ((int)$u['admin_level'] > 0 && $target && (int)$target['uporabnik_id'] !== (int)$u['id']) {
            $_POST['akcija'] = 'izbrisi_oglas'; $_POST['id'] = (string)$id;
            try { adminDejanje($u); flash('del', 'Oglas je izbrisan.'); }
            catch (Throwable $e) { flash('err', 'Oglasa ni mogoče izbrisati ali nimaš dovoljenja.'); }
            preusmeri(($_POST['nazaj'] ?? '') === 'moj-racun' ? '/profil' : DOMACA);
        }

        // Kam se vrnemo po brisanju (le dovoljene strani).
        $nazaj = ($_POST['nazaj'] ?? '') === 'moj-racun' ? '/profil' : DOMACA;

        $st = $db->prepare('SELECT uporabnik_id, naslov, slika FROM oglas WHERE id = ?');
        $st->execute([$id]);
        $oglas = $st->fetch();

        if (!$oglas) {
            flash('err', 'Oglas ne obstaja.');

        } elseif ((int)$oglas['uporabnik_id'] === (int)$u['id']
                  || smeUpravljati($u, sqlVrstica('SELECT * FROM uporabnik WHERE id=?', [(int)$oglas['uporabnik_id']]))) {
            try {
                $st = $db->prepare('DELETE FROM oglas WHERE id = ?');
                $st->execute([$id]);
                izbrisiSliko($oglas['slika'] ?? null);
                if ((int)$u['admin_level']>0) dnevnik($u,'Izbris oglasa',(string)$id,'Glavna stran');

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

if (isset($_GET['oglas'])) { prikaziOglas((int)$_GET['oglas']); exit; }

$q = trim((string)($_GET['q'] ?? ''));
$fk = (string)($_GET['kat'] ?? '');
$fs = (string)($_GET['stanje'] ?? '');
$fv = (string)($_GET['vrsta'] ?? '');

$sql = 'SELECT o.*, a.uporabnisko_ime AS avtor
        FROM oglas o
        JOIN uporabnik a ON a.id = o.uporabnik_id
        WHERE o.moderacija = \'approved\'';
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

// Lastni odobreni oglasi imajo prednost tudi pred omejitvijo rezultatov.
$vrstniRed = ' ORDER BY o.ts DESC, o.id DESC LIMIT 200';
if ($u) {
    $vrstniRed = ' ORDER BY CASE WHEN o.uporabnik_id = ? THEN 0 ELSE 1 END, o.ts DESC, o.id DESC LIMIT 200';
    $params[] = (int)$u['id'];
}
$st = $db->prepare($sql . $vrstniRed);
$st->execute($params);
$oglasi = $st->fetchAll();
$mojiOglasi = []; $ostaliOglasi = [];
foreach ($oglasi as $o) {
    if ($u && (int)$o['uporabnik_id'] === (int)$u['id']) $mojiOglasi[] = $o;
    else $ostaliOglasi[] = $o;
}
$skupine = $mojiOglasi
    ? ['Moji oglasi' => $mojiOglasi, 'Ostali oglasi' => $ostaliOglasi]
    : ['' => $oglasi];

$skupaj = count($oglasi);
$filtriran = ($q !== '' || $fk !== '' || $fs !== '' || $fv !== '');
$jeAdmin = $u && (int)$u['admin_level'] > 0;

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

<form class="search search-oglasi" role="search" aria-label="Iskanje in filtriranje oglasov" method="get" action="<?= h(DOMACA) ?>">
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

<?php if (!$u): ?>
<div class="hint">
    <strong>Želiš objaviti oglas ali poslati sporočilo prodajalcu?</strong>
    <a href="/prijava">Prijavi se</a> ali
    <a href="/registracija">ustvari račun</a> –
    ime in e-naslov se izpolnita samodejno.
</div>
<?php endif; ?>

<div class="count">
    <?= $skupaj ?> <?= $skupaj === 1 ? 'oglas' : 'oglasov' ?>
    <?php if ($filtriran): ?>
        · <a href="<?= h(DOMACA) ?>">Počisti filtre</a>
    <?php endif; ?>
</div>

<?php foreach ($skupine as $imeSkupine => $oglasiSkupine): ?>
<?php if ($imeSkupine !== ''): ?><h2 class="oglasi-naslov"><?=h($imeSkupine)?></h2><?php endif; ?>
<section class="g" aria-label="<?=h($imeSkupine ?: 'Oglasi')?>">

<?php if (!$oglasiSkupine): ?>
<div class="empty">
    <p><strong><?=$imeSkupine === 'Ostali oglasi' ? 'Ni ostalih oglasov.' : 'Ni oglasov.'?></strong></p>
    <p><?= $filtriran ? 'Poskusi z drugimi iskalnimi pogoji.' : ($imeSkupine === 'Ostali oglasi' ? 'Tukaj bodo prikazani oglasi drugih uporabnikov.' : 'Bodi prvi in objavi oglas!') ?></p>
</div>
<?php endif; ?>

<?php foreach ($oglasiSkupine as $o): ?>
<?php $lasten = $u && (int)$o['uporabnik_id'] === (int)$u['id']; ?>
<article class="c <?= $o['vrsta'] === 'Izmenjava' ? 'izm' : '' ?>">

    <?php if (!empty($o['slika']) && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $o['slika'])): ?>
    <a class="slika" href="/oglas/<?= (int)$o['id'] ?>">
        <img src="<?= h(URL_SLIK . $o['slika']) ?>" alt="Slika: <?= h($o['naslov']) ?>" loading="lazy">
    </a>
    <?php endif; ?>

    <h3><a class="oglas-open" href="/oglas/<?= (int)$o['id'] ?>"><?= h($o['naslov']) ?></a></h3>

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
    <a class="btn btn-pink" href="/pogovor/<?= (int)$mojiPog[(int)$o['id']] ?>">💬 Odpri pogovor</a>

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
    <div class="m"><a href="/prijava">Prijavi se</a> za pošiljanje sporočil.</div>
    <?php endif; ?>

    <?php if ($lasten || ($jeAdmin && smeUpravljati($u, sqlVrstica('SELECT * FROM uporabnik WHERE id=?',[(int)$o['uporabnik_id']])))): ?>
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
<?php endforeach; ?>

<?php noga(); ?>
