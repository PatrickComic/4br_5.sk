<?php
require __DIR__ . '/inc.php';

if (uporabnik()) {
    preusmeri(DOMACA);
}

$napaka = '';
$vnos = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    preveriCsrf();

    $vnos = postText('prijavno', 80);
    $geslo = (string)($_POST['geslo'] ?? '');

    try {
        $st = db()->prepare(
            'SELECT id, uporabnisko_ime, geslo_hash FROM uporabnik
             WHERE uporabnisko_ime = ? OR email = ?'
        );
        $st->execute([$vnos, $vnos]);
        $r = $st->fetch();

        if ($r && password_verify($geslo, $r['geslo_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$r['id'];

            flash('ok', 'Pozdravljen, ' . $r['uporabnisko_ime'] . '!');
            preusmeri(DOMACA);
        }

        $napaka = 'Napačno uporabniško ime, e-naslov ali geslo.';

    } catch (Throwable $e) {
        error_log($e->getMessage());
        $napaka = 'Napaka pri prijavi.';
    }
}

glava('Prijava – pat.si');
?>

<div class="auth">
    <h2>Prijava</h2>

    <?php if ($napaka !== ''): ?>
    <div class="msg err" role="alert"><?= h($napaka) ?></div>
    <?php endif; ?>

    <form method="post" action="prijava.php">
        <?= csrfPolje() ?>
        <input name="prijavno" value="<?= h($vnos) ?>" placeholder="Uporabniško ime ali e-naslov" aria-label="Uporabniško ime ali e-naslov" required maxlength="80" autocomplete="username">
        <input name="geslo" type="password" placeholder="Geslo" aria-label="Geslo" required autocomplete="current-password">
        <button type="submit">Prijava</button>
    </form>

    <p class="m">Še nimaš računa? <a href="registracija.php">Registriraj se</a></p>
</div>

<?php noga(); ?>
