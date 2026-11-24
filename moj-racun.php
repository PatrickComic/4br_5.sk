<?php
require __DIR__ . '/inc.php';

$u = uporabnik();

if (!$u) {
    zahtevajPrijavo();
}

$db = db();

// Izbris lastnega računa
$napakaBrisanja = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['akcija'] ?? '') === 'izbrisi_racun') {
    preveriCsrf();
    $vrstica = sqlVrstica('SELECT geslo_hash, admin_level FROM uporabnik WHERE id = ?', [(int)$u['id']]);

    if (!$vrstica) {
        $napakaBrisanja = 'Računa ni mogoče najti.';
    } elseif ((int)$vrstica['admin_level'] === 2) {
        $napakaBrisanja = 'Glavnega skrbniškega računa ni dovoljeno izbrisati.';
    } elseif (!password_verify(postGeslo('geslo'), (string)$vrstica['geslo_hash'])) {
        $napakaBrisanja = 'Geslo ni pravilno. Račun ni bil izbrisan.';
    } else {
        $slike = array_column(sqlVse('SELECT slika FROM oglas WHERE uporabnik_id = ?', [(int)$u['id']]), 'slika');
        try {
            $db->exec('BEGIN IMMEDIATE');
            // Oglasi, pogovori in sporočila se izbrišejo samodejno (ON DELETE CASCADE).
            izvedi('DELETE FROM uporabnik WHERE id = ?', [(int)$u['id']]);
            $db->exec('COMMIT');
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->exec('ROLLBACK'); }
            $napakaBrisanja = 'Računa ni bilo mogoče izbrisati. Poskusi znova ali piši skrbniku.';
        }

        if ($napakaBrisanja === '') {
            foreach ($slike as $slika) { izbrisiSliko($slika); }
            $_SESSION = [];
            session_regenerate_id(true);
            flash('ok', 'Tvoj račun in vsi povezani podatki so izbrisani.', 'Račun izbrisan');
            preusmeri(DOMACA);
        }
    }
}

// Moji oglasi
$st = $db->prepare(
    'SELECT * FROM oglas WHERE uporabnik_id = ? ORDER BY ts DESC, id DESC'
);
$st->execute([(int)$u['id']]);
$moji = $st->fetchAll();

$nepr = steviloNeprebranih((int)$u['id']);

glava('Moj račun – pat.si');
?>

<div class="panel">
    <p class="m">Skrbniki lahko zaradi podpore in moderiranja pregledujejo podatke, oglase in pogovore. Skrbniški vpogledi v pogovore se beležijo.</p>
    <h2>Moj račun <?php if (!empty($u['je_admin'])): ?><span class="badge">Skrbnik</span><?php endif; ?></h2>
    <p>
        <strong>Uporabniško ime:</strong> <?= h($u['uporabnisko_ime']) ?><br>
        <strong>E-naslov:</strong> <?= h($u['email']) ?><br>
        <strong>Registriran:</strong> <?= h(date('j. n. Y', (int)$u['datum_registracije'])) ?>
    </p>
    <p class="m">Pri oglasih in sporočilih se prikaže le tvoje uporabniško ime. E-naslov drugim uporabnikom ni viden.</p>
</div>

<div class="panel">
    <h2>Sporočila</h2>
    <p class="m">Pogovori s kupci in prodajalci so v predalniku, ne v profilu.</p>
    <a class="btn" href="sporocila.php">✉ Odpri predalnik<?php if ($nepr > 0): ?> <span class="bdg"><?= (int)$nepr ?></span><?php endif; ?></a>
</div>

<div class="panel">
    <h2>Moji oglasi (<?= count($moji) ?>)</h2>

    <?php if (!$moji): ?>
    <p class="m">Še nimaš oglasov. <a href="objavi.php">Objavi prvega</a>.</p>
    <?php endif; ?>

    <?php foreach ($moji as $o): ?>
    <div class="moj">
        <h3><a href="pat.si.php?oglas=<?= (int)$o['id'] ?>"><?= h($o['naslov']) ?></a></h3>
        <p><span class="badge status-<?=h($o['moderacija'])?>"><?=h(stanjeModeracije($o['moderacija']))?></span></p>
        <?php if($o['moderacija']==='pending'): ?><p class="m">Oglas še ni javno viden. Skrbnik bo pregledal naslov, opis in vse slike.</p><?php endif; ?>
        <?php if($o['moderacija']==='rejected'): ?><p class="msg err"><strong>Razlog zavrnitve:</strong><br><?=nl2br(h($o['razlog_moderacije']))?></p><?php endif; ?>
        <div class="m">
            <?= h($o['vrsta']) ?> · <?= h($o['stanje']) ?> · <?= h($o['kat']) ?>
            <?php if ($o['vrsta'] === 'Prodaja'): ?>
            · <?= h(number_format((float)$o['cena'], 2, ',', '.')) ?> €
            <?php endif; ?>
            · <?= h(date('j. n. Y', (int)$o['ts'])) ?>
        </div>

        <form method="post" action="<?= h(DOMACA) ?>" onsubmit="return confirm('Res izbrišem ta oglas?')">
            <?= csrfPolje() ?>
            <input type="hidden" name="akcija" value="brisi">
            <input type="hidden" name="nazaj" value="moj-racun">
            <input type="hidden" name="oglas_id" value="<?= (int)$o['id'] ?>">
            <button type="submit" class="btn-danger">Izbriši oglas</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>

<div class="panel panel-danger">
    <h2>Izbris računa</h2>
    <?php if ($napakaBrisanja): ?><div class="msg err" role="alert"><?= h($napakaBrisanja) ?></div><?php endif; ?>
    <?php if ((int)($u['admin_level'] ?? 0) === 2): ?>
    <p class="m">Glavnega skrbniškega računa ni mogoče izbrisati.</p>
    <?php else: ?>
    <p class="m">Z izbrisom trajno odstraniš svoj račun, vse svoje oglase (s slikami) ter pogovore in sporočila, ki so povezana s tabo. Dejanja ni mogoče razveljaviti.</p>
    <form method="post" class="izbris-racuna" onsubmit="return confirm('Res želiš trajno izbrisati svoj račun?')">
        <?= csrfPolje() ?>
        <input type="hidden" name="akcija" value="izbrisi_racun">
        <input type="password" name="geslo" placeholder="Za potrditev vnesi geslo" aria-label="Geslo za potrditev izbrisa" autocomplete="current-password" required>
        <button type="submit" class="btn-danger">Izbriši moj račun</button>
    </form>
    <?php endif; ?>
</div>

<?php noga(); ?>
