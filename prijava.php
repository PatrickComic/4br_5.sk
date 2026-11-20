<?php
require __DIR__ . '/inc.php';
if (uporabnik()) preusmeri(DOMACA);
$napaka=''; $vnos='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    preveriCsrf(); $vnos=postText('prijavno',254); $pw=postGeslo('geslo');
    try {
        omeji([['login-ip:'.ipKljuc(),30,900],['login-name:'.hash('sha256',strtolower($vnos)),10,900]]);
        $u=sqlVrstica('SELECT * FROM uporabnik WHERE uporabnisko_ime=? OR email=?',[$vnos,$vnos]);
        $ok=password_verify($pw,$u['geslo_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$u || !$ok || $u['blokiran']) throw new DomainException('Napačno uporabniško ime, e-naslov ali geslo oziroma račun ni na voljo.');
        if (!$u['aktiviran']) {
            if (preverjanjeVklopljeno()) { cakajPotrditev($u); preusmeri('potrditev.php'); }
            izvedi('UPDATE uporabnik SET aktiviran=1 WHERE id=?',[$u['id']]); $u['aktiviran']=1;
        }
        prijavi($u); flash('ok','Pozdravljen, '.$u['uporabnisko_ime'].'!'); preusmeri(DOMACA);
    } catch (DomainException $e) { $napaka=$e->getMessage(); }
    catch (Throwable $e) { error_log('PAT.si: prijava ni uspela.'); $napaka='Napaka pri prijavi. Poskusi pozneje.'; }
}
glava('Prijava – PAT.si'); ?>
<div class="auth"><h2>Prijava</h2><?php if($napaka): ?><p class="msg err" role="alert"><?=h($napaka)?></p><?php endif; ?>
<form method="post"><?=csrfPolje()?>
<label>Uporabniško ime ali e-naslov<input name="prijavno" value="<?=h($vnos)?>" required maxlength="254" autocomplete="username"></label>
<label>Geslo<input name="geslo" type="password" required autocomplete="current-password"></label>
<button>Prijava</button></form><p class="m">Še nimaš računa? <a href="registracija.php">Registriraj se</a></p></div><?php noga(); ?>
