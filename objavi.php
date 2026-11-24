<?php
require __DIR__ . '/inc.php';

$u = uporabnik();

if (!$u) {
    zahtevajPrijavo();
}

$db = db();
$napaka = '';

/* =====================================
   OBDELAVA OBRAZCA ZA NOV OGLAS
   Ime in e-naslov vzame iz računa.
   ===================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('err', 'Slike so prevelike (vsaka do 5 MB).');
        preusmeri('/objavi');
    }

    preveriCsrf();

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
        $rez = shraniSlike();

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
                 (uporabnik_id, naslov, vrsta, kat, stanje, cena, opis, ts, slika, moderacija)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                $slika,
                'pending'
            ]);

            flash('ok', 'Oglas je oddan in čaka na pregled skrbnika. Odločitev prejmeš v sporočilih.');
            preusmeri('/profil');

        } catch (Throwable $e) {
            izbrisiSliko($slika);
            error_log($e->getMessage());
            $napaka = 'Napaka pri shranjevanju oglasa.';
        }
    }
}

$pVrsta = in_array($_POST['vrsta'] ?? '', VRSTE, true) ? $_POST['vrsta'] : 'Prodaja';
$pKat = (string)($_POST['kat'] ?? '');
$pStanje = (string)($_POST['stanje'] ?? '');

glava('Objavi oglas – pat.si');
?>

<div class="oglas-back"><a href="<?= h(DOMACA) ?>">Vsi oglasi</a></div>

<form class="oglas-detail objavi" method="post" action="/objavi" enctype="multipart/form-data">
    <?= csrfPolje() ?>
    <input type="hidden" name="akcija" value="oglas">

    <div class="oglas-gallery" aria-label="Slike oglasa">
        <div class="oglas-stage" id="stage">
            <img class="oglas-slide" id="prikaz" alt="Predogled izbrane slike" hidden>
            <label class="drop" id="drop">
                <span class="drop-ico" aria-hidden="true">📷</span>
                <strong>Dodaj slike</strong>
                <span class="m">Do 8 slik · JPG, PNG ali WebP · vsaka do 5 MB</span>
                <input class="sr-only" id="slike" type="file" name="slika[]" multiple accept="image/jpeg,image/png,image/webp">
            </label>
        </div>
        <div class="gallery-thumbs" id="thumbs" hidden></div>
        <label class="btn btn-ghost" id="zamenjaj" for="slike" hidden>Zamenjaj slike</label>
    </div>

    <div class="oglas-info">
        <p class="m">Objavljaš kot <strong><?= h($u['uporabnisko_ime']) ?></strong> (<?= h($u['email']) ?>).</p>
        <h2>Nov oglas</h2>

        <?php if ($napaka !== ''): ?>
        <div class="msg err" role="alert"><?= h($napaka) ?> Slike izberi znova.</div>
        <?php endif; ?>

        <div class="objavi-polja">
            <label class="polje">
                <span class="polje-l">Naslov</span>
                <input name="naslov" value="<?= h($_POST['naslov'] ?? '') ?>" placeholder="Kaj objavljaš?" required maxlength="80">
            </label>

            <div class="polje">
                <span class="polje-l" id="vrsta-l">Vrsta oglasa</span>
                <div class="seg" role="radiogroup" aria-labelledby="vrsta-l">
                    <?php foreach (VRSTE as $k): ?>
                    <label><input type="radio" name="vrsta" value="<?= h($k) ?>" <?= $pVrsta === $k ? 'checked' : '' ?>><span><?= h($k) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="polje" id="cenaBlok">
                <span class="polje-l">Cena</span>
                <label class="cena-polje"><span class="sr-only">Cena v evrih</span><input name="cena" type="number" min="0" max="100000" step="0.01" value="<?= h($_POST['cena'] ?? '0') ?>" placeholder="0"><span class="cena-valuta" aria-hidden="true">€</span></label>
            </div>

            <div class="polje-2">
                <label class="polje">
                    <span class="polje-l">Kategorija</span>
                    <select name="kat">
                        <?php foreach (KAT as $k): ?>
                        <option value="<?= h($k) ?>" <?= $pKat === $k ? 'selected' : '' ?>><?= h($k) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="polje">
                    <span class="polje-l">Stanje</span>
                    <select name="stanje">
                        <?php foreach (STANJA as $k): ?>
                        <option value="<?= h($k) ?>" <?= $pStanje === $k ? 'selected' : '' ?>><?= h($k) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label class="polje">
                <span class="polje-l">Opis</span>
                <textarea name="opis" id="opis" rows="5" maxlength="300" placeholder="Opiši predmet ali napiši, kaj iščeš v zameno"><?= h($_POST['opis'] ?? '') ?></textarea>
                <span class="m opis-st" id="opisSt" aria-live="polite">0 / 300</span>
            </label>
        </div>

        <p class="m">Oglas bo javno viden po pregledu skrbnika. Stanje spremljaš pod »Moj račun«, odločitev pa prejmeš v sporočilih.</p>

        <div class="objavi-gumbi">
            <button type="submit">Oddaj oglas v pregled</button>
            <a class="btn btn-ghost" href="<?= h(DOMACA) ?>">Prekliči</a>
        </div>
    </div>
</form>

<script>
(function () {
    var vnos = document.getElementById('slike'), prikaz = document.getElementById('prikaz'),
        drop = document.getElementById('drop'), thumbs = document.getElementById('thumbs'),
        zamenjaj = document.getElementById('zamenjaj'), urls = [];

    function pocisti() { urls.forEach(URL.revokeObjectURL); urls = []; thumbs.innerHTML = ''; }

    function pokazi(i) {
        prikaz.src = urls[i];
        Array.prototype.forEach.call(thumbs.children, function (b, j) { b.setAttribute('aria-pressed', j === i ? 'true' : 'false'); });
    }

    vnos.addEventListener('change', function () {
        var f = Array.from(vnos.files);
        if (f.length > 8 || f.some(function (x) { return x.size > 5242880; })) {
            alert('Naloži največ 8 slik, vsaka do 5 MB.'); vnos.value = ''; f = [];
        }
        pocisti();
        var ima = f.length > 0;
        prikaz.hidden = !ima; drop.hidden = ima; thumbs.hidden = f.length < 2; zamenjaj.hidden = !ima;
        f.forEach(function (x, i) {
            urls.push(URL.createObjectURL(x));
            if (f.length > 1) {
                var b = document.createElement('button'), im = document.createElement('img');
                b.type = 'button'; b.className = 'gallery-thumb'; b.setAttribute('aria-label', 'Prikaži sliko ' + (i + 1));
                im.src = urls[i]; im.alt = ''; b.appendChild(im);
                b.addEventListener('click', function () { pokazi(i); });
                thumbs.appendChild(b);
            }
        });
        if (ima) pokazi(0);
    });

    var cenaBlok = document.getElementById('cenaBlok');
    function vrsta() {
        var r = document.querySelector('input[name=vrsta]:checked');
        cenaBlok.hidden = !!r && r.value === 'Izmenjava';
    }
    Array.prototype.forEach.call(document.querySelectorAll('input[name=vrsta]'), function (r) { r.addEventListener('change', vrsta); });
    vrsta();

    var opis = document.getElementById('opis'), st = document.getElementById('opisSt');
    function stevec() { st.textContent = opis.value.length + ' / 300'; }
    opis.addEventListener('input', stevec); stevec();
})();
</script>

<?php noga(); ?>
