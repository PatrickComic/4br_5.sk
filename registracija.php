<?php
require __DIR__ . '/inc.php';

if (uporabnik()) {
    preusmeri(DOMACA);
}

$napaka = '';
$ime = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    preveriCsrf();

    $ime = postText('uporabnisko_ime', 30);
    $email = postText('email', 80);
    $geslo = (string)($_POST['geslo'] ?? '');
    $geslo2 = (string)($_POST['geslo2'] ?? '');

    if (!preg_match('/^[\p{L}\p{N}_. -]{3,30}$/u', $ime)) {
        $napaka = 'Uporabniško ime naj ima 3–30 znakov (črke, številke, _ . -).';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $napaka = 'Vnesi veljaven e-naslov.';

    } elseif (strlen($geslo) < 6) {
        $napaka = 'Geslo naj ima vsaj 6 znakov.';

    } elseif ($geslo !== $geslo2) {
        $napaka = 'Gesli se ne ujemata.';

    } else {
        try {
            $db = db();

            $st = $db->prepare(
                'SELECT 1 FROM uporabnik
                 WHERE uporabnisko_ime = ? OR email = ?'
            );
            $st->execute([$ime, $email]);

            if ($st->fetch()) {
                $napaka = 'Uporabniško ime ali e-naslov je že zaseden.';

            } else {
                // Prvi uporabnik postane skrbnik.
                $prvi = (int)$db->query(
                    'SELECT COUNT(*) FROM uporabnik'
                )->fetchColumn() === 0 ? 1 : 0;

                $st = $db->prepare(
                    'INSERT INTO uporabnik
                     (uporabnisko_ime, email, geslo_hash, je_admin, datum_registracije)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $ime,
                    $email,
                    password_hash($geslo, PASSWORD_DEFAULT),
                    $prvi,
                    time()
                ]);

                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$db->lastInsertId();

                flash('ok', 'Račun je ustvarjen. Dobrodošel, ' . $ime . '!');
                preusmeri(DOMACA);
            }

        } catch (Throwable $e) {
            error_log($e->getMessage());
            $napaka = 'Napaka pri ustvarjanju računa.';
        }
    }
}

glava('Registracija – pat.si');
?>

<div class="auth">
    <h2>Ustvari račun</h2>

    <?php if ($napaka !== ''): ?>
    <div class="msg err" role="alert"><?= h($napaka) ?></div>
    <?php endif; ?>

    <form method="post" action="registracija.php">
        <?= csrfPolje() ?>
        <input name="uporabnisko_ime" value="<?= h($ime) ?>" placeholder="Uporabniško ime" aria-label="Uporabniško ime" required maxlength="30" autocomplete="username">
        <input name="email" type="email" value="<?= h($email) ?>" placeholder="E-naslov" aria-label="E-naslov" required maxlength="80" autocomplete="email">
        <input name="geslo" type="password" placeholder="Geslo (vsaj 6 znakov)" aria-label="Geslo" required minlength="6" autocomplete="new-password">
        <input name="geslo2" type="password" placeholder="Ponovi geslo" aria-label="Ponovi geslo" required minlength="6" autocomplete="new-password">
        <button type="submit">Registracija</button>
    </form>

    <p class="m">Že imaš račun? <a href="prijava.php">Prijavi se</a></p>
</div>

<?php noga(); ?>
