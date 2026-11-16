<?php
require __DIR__ . '/inc.php';

$u = uporabnik();

if (!$u) {
    zahtevajPrijavo();
}

$db = db();
$uid = (int)$u['id'];

/* =====================================
   POŠILJANJE ODGOVORA
   ===================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    preveriCsrf();

    $pid = (int)($_POST['pogovor_id'] ?? 0);
    $besedilo = postText('besedilo', 1000);

    $st = $db->prepare(
        'SELECT p.id, p.oglas_naslov, p.kupec_id, p.prodajalec_id,
                k.email AS kupec_email,
                s.email AS prodajalec_email
         FROM pogovor p
         JOIN uporabnik k ON k.id = p.kupec_id
         JOIN uporabnik s ON s.id = p.prodajalec_id
         WHERE p.id = ? AND (p.kupec_id = ? OR p.prodajalec_id = ?)'
    );
    $st->execute([$pid, $uid, $uid]);
    $p = $st->fetch();

    if (!$p) {
        flash('err', 'Pogovor ne obstaja.');
        preusmeri('sporocila.php');
    }

    if ($besedilo === '') {
        flash('err', 'Napiši sporočilo.');
        preusmeri('sporocila.php?id=' . $pid);
    }

    try {
        posljiSporocilo($pid, $uid, $besedilo);

    } catch (Throwable $e) {
        error_log($e->getMessage());
        flash('err', 'Sporočila ni bilo mogoče poslati.');
        preusmeri('sporocila.php?id=' . $pid);
    }

    $komu = (int)$p['kupec_id'] === $uid
        ? $p['prodajalec_email']
        : $p['kupec_email'];

    obvestiEmail($komu, $u['uporabnisko_ime'], $p['oglas_naslov'], $pid);

    preusmeri('sporocila.php?id=' . $pid);
}

/* =====================================
   PRIKAZ PREDALNIKA
   ===================================== */

$izbran = (int)($_GET['id'] ?? 0);

// Odprt pogovor: označi prejeta sporočila kot prebrana
// (le če je uporabnik udeleženec pogovora).
if ($izbran > 0) {
    $st = $db->prepare(
        'UPDATE sporocilo SET prebrano = 1
         WHERE pogovor_id = ? AND posiljatelj_id <> ? AND prebrano = 0
           AND pogovor_id IN (
               SELECT id FROM pogovor
               WHERE id = ? AND (kupec_id = ? OR prodajalec_id = ?)
           )'
    );
    $st->execute([$izbran, $uid, $izbran, $uid, $uid]);
}

// Seznam pogovorov (najnovejši zgoraj)
$st = $db->prepare(
    'SELECT p.id, p.oglas_id, p.oglas_naslov, p.ts_zadnje,
            CASE WHEN p.kupec_id = ? THEN s.uporabnisko_ime
                 ELSE k.uporabnisko_ime END AS drugi,
            (SELECT besedilo FROM sporocilo
             WHERE pogovor_id = p.id ORDER BY id DESC LIMIT 1) AS zadnje,
            (SELECT posiljatelj_id FROM sporocilo
             WHERE pogovor_id = p.id ORDER BY id DESC LIMIT 1) AS zadnji_pos,
            (SELECT COUNT(*) FROM sporocilo
             WHERE pogovor_id = p.id AND prebrano = 0
               AND posiljatelj_id <> ?) AS nepr
     FROM pogovor p
     JOIN uporabnik k ON k.id = p.kupec_id
     JOIN uporabnik s ON s.id = p.prodajalec_id
     WHERE p.kupec_id = ? OR p.prodajalec_id = ?
     ORDER BY p.ts_zadnje DESC, p.id DESC'
);
$st->execute([$uid, $uid, $uid, $uid]);
$pogovori = $st->fetchAll();

$trenutni = null;

foreach ($pogovori as $c) {
    if ((int)$c['id'] === $izbran) {
        $trenutni = $c;
        break;
    }
}

$sporocila = [];

if ($trenutni) {
    $st = $db->prepare(
        'SELECT * FROM (
             SELECT id, posiljatelj_id, besedilo, ts
             FROM sporocilo
             WHERE pogovor_id = ?
             ORDER BY id DESC LIMIT 300
         ) ORDER BY id ASC'
    );
    $st->execute([(int)$trenutni['id']]);
    $sporocila = $st->fetchAll();
}

glava('Sporočila – pat.si');
?>

<div class="inbox <?= $trenutni ? 'odprt' : '' ?>">

    <!-- Seznam pogovorov -->
    <aside class="conv-list" aria-label="Pogovori">
        <h2>Sporočila</h2>

        <?php if (!$pogovori): ?>
        <p class="m pad">Še nimaš pogovorov. Odpri oglas in prodajalcu pošlji sporočilo – pogovor se prikaže tukaj.</p>
        <?php endif; ?>

        <?php foreach ($pogovori as $c): ?>
        <?php
            $jeAktiven = $trenutni && (int)$c['id'] === (int)$trenutni['id'];
            $predogled = skrajsaj((string)$c['zadnje'], 60);

            if ((int)$c['zadnji_pos'] === $uid) {
                $predogled = 'Ti: ' . $predogled;
            }
        ?>
        <a class="conv <?= $jeAktiven ? 'act' : '' ?> <?= (int)$c['nepr'] > 0 ? 'nepr' : '' ?>"
           href="sporocila.php?id=<?= (int)$c['id'] ?>">
            <span class="av" aria-hidden="true"><?= h(crka((string)$c['drugi'])) ?></span>

            <span class="conv-b">
                <span class="conv-n">
                    <span><?= h($c['drugi']) ?></span>
                    <span class="conv-t"><?= h(casKratko((int)$c['ts_zadnje'])) ?></span>
                </span>
                <span class="conv-o"><?= h($c['oglas_naslov']) ?></span>
                <span class="conv-p"><?= h($predogled) ?></span>
            </span>

            <?php if ((int)$c['nepr'] > 0): ?>
            <span class="bdg"><?= (int)$c['nepr'] ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </aside>

    <!-- Odprt pogovor -->
    <section class="thread" aria-label="Pogovor">

        <?php if (!$trenutni): ?>
        <div class="thread-empty">
            <div class="thread-empty-ico" aria-hidden="true">💬</div>
            <p><strong>Izberi pogovor</strong></p>
            <p class="m">Tukaj se prikažejo vsa tvoja sporočila s kupci in prodajalci.</p>
        </div>

        <?php else: ?>
        <div class="thread-h">
            <a class="back" href="sporocila.php">← Nazaj</a>
            <span class="av" aria-hidden="true"><?= h(crka((string)$trenutni['drugi'])) ?></span>
            <div class="thread-who">
                <strong><?= h($trenutni['drugi']) ?></strong>
                <span class="m">
                    <?php if ($trenutni['oglas_id'] !== null): ?>
                    Oglas: <?= h($trenutni['oglas_naslov']) ?>
                    <?php else: ?>
                    Oglas »<?= h($trenutni['oglas_naslov']) ?>« je bil izbrisan
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div class="msgs" id="msgs">
            <?php $prejsnjiDan = ''; ?>
            <?php foreach ($sporocila as $s): ?>
            <?php
                $dan = date('j. n. Y', (int)$s['ts']);
                $moje = (int)$s['posiljatelj_id'] === $uid;
            ?>
            <?php if ($dan !== $prejsnjiDan): ?>
            <?php $prejsnjiDan = $dan; ?>
            <div class="day"><?= h($dan === date('j. n. Y') ? 'Danes' : $dan) ?></div>
            <?php endif; ?>

            <div class="bub <?= $moje ? 'me' : 'them' ?>">
                <?= nl2br(h($s['besedilo'])) ?>
                <time><?= h(date('H:i', (int)$s['ts'])) ?></time>
            </div>
            <?php endforeach; ?>
        </div>

        <form class="reply" method="post" action="sporocila.php">
            <?= csrfPolje() ?>
            <input type="hidden" name="pogovor_id" value="<?= (int)$trenutni['id'] ?>">
            <textarea name="besedilo" rows="1" maxlength="1000" placeholder="Napiši sporočilo…" aria-label="Sporočilo" required></textarea>
            <button type="submit">Pošlji</button>
        </form>

        <script>
        (function () {
            var m = document.getElementById('msgs');
            if (m) { m.scrollTop = m.scrollHeight; }

            var ta = document.querySelector('.reply textarea');
            if (ta && window.matchMedia && window.matchMedia('(pointer: fine)').matches) {
                // Na računalniku Enter pošlje, Shift+Enter naredi novo vrstico.
                ta.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (ta.value.trim() !== '') { ta.form.submit(); }
                    }
                });
            }
        })();
        </script>
        <?php endif; ?>

    </section>
</div>

<?php noga(); ?>
