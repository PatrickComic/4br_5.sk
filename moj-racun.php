<?php
require __DIR__ . '/inc.php';

$u = uporabnik();

if (!$u) {
    zahtevajPrijavo();
}

$db = db();

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
    <p class="m">Še nimaš oglasov. <a href="<?= h(DOMACA) ?>">Objavi prvega</a>.</p>
    <?php endif; ?>

    <?php foreach ($moji as $o): ?>
    <div class="moj">
        <h3><?= h($o['naslov']) ?></h3>
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

<?php noga(); ?>
