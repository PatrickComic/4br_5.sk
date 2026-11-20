<?php
require __DIR__ . '/inc.php';
header('Cache-Control: no-store');
$u=cakajoci(); if (!$u) { flash('info','Za nadaljevanje potrditve se prijavi.'); preusmeri('prijava.php'); }
if ($u['aktiviran']) { prijavi($u); preusmeri(DOMACA); }
$napaka='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    preveriCsrf();
    try {
        if (!preverjanjeVklopljeno()) { izvedi('UPDATE uporabnik SET aktiviran=1 WHERE id=?',[$u['id']]); prijavi($u); preusmeri(DOMACA); }
        if (postText('akcija')==='poslji') { posljiKodo($u); flash('ok','Nova koda je poslana. Prejšnja ne velja več.'); preusmeri('potrditev.php'); }
        potrdiKodo($u,postText('koda',20));
        prijavi($u); flash('ok','E-naslov je potrjen. Dobrodošel!'); preusmeri(DOMACA);
    } catch (DomainException $e) { $napaka=$e->getMessage(); }
    catch (Throwable $e) { $napaka='Potrditev trenutno ni uspela. Poskusi znova.'; }
}
glava('Potrditev e-naslova – PAT.si'); ?>
<div class="auth"><h2>Potrdi e-naslov</h2><p>Kodo pošljemo na <strong><?=h($u['email'])?></strong>.</p>
<?php if($napaka): ?><p class="msg err" role="alert"><?=h($napaka)?></p><?php endif; ?>
<?php if(preverjanjeVklopljeno()): ?>
<form method="post"><?=csrfPolje()?><label>Šestmestna koda<input name="koda" pattern="[0-9]{6}" minlength="6" maxlength="6" inputmode="numeric" autocomplete="one-time-code" required autofocus></label><button>Potrdi in dokončaj registracijo</button></form>
<p class="m">Koda velja 10 minut. Dovoljenih je 5 poskusov. Preveri tudi neželeno pošto.</p>
<form method="post"><?=csrfPolje()?><input type="hidden" name="akcija" value="poslji"><button class="btn-ghost">Pošlji novo kodo</button></form>
<p class="m">Ponovno pošiljanje: po 60 sekundah, največ 5-krat na uro in 10-krat na dan. Veljajo tudi skupne omejitve.</p>
<?php else: ?><form method="post"><?=csrfPolje()?><button>Dokončaj registracijo</button></form><?php endif; ?>
<p class="m">Napačen naslov? <a href="registracija.php">Začni novo registracijo</a> ali se <a href="prijava.php">prijavi z drugim računom</a>.</p></div><?php noga(); ?>
