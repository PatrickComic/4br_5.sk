<?php
require __DIR__ . '/inc.php';
if (uporabnik()) preusmeri(DOMACA);
$napaka=''; $ime=''; $email='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    preveriCsrf(); $ime=postText('uporabnisko_ime',30); $email=postText('email',254);
    $pw=postGeslo('geslo'); $pw2=postGeslo('geslo2');
    try {
        omeji([['register:'.ipKljuc(),10,3600]]);
        veljavniPodatki($ime,$email);
        if (strcasecmp($ime,'admin')===0) throw new DomainException('Ime admin je rezervirano za glavnega skrbnika.');
        if (strlen($pw)<10 || strlen($pw)>72) throw new DomainException('Geslo naj ima 10–72 bajtov.');
        if ($pw!==$pw2) throw new DomainException('Gesli se ne ujemata.');
        if (sqlVrstica('SELECT id FROM uporabnik WHERE uporabnisko_ime=? OR email=?',[$ime,$email])) throw new DomainException('Uporabniško ime ali e-naslov je že zaseden. Če račun že imaš, se prijavi.');
        $needs=preverjanjeVklopljeno();
        if ($needs && !postaPripravljena()) throw new DomainException('Registracija s potrditvijo trenutno ni na voljo. Skrbnik mora nastaviti pošiljanje e-pošte.');
        izvedi('INSERT INTO uporabnik(uporabnisko_ime,email,geslo_hash,je_admin,admin_level,datum_registracije,aktiviran,email_verified) VALUES (?,?,?,0,0,?,?,0)',[$ime,$email,password_hash($pw,PASSWORD_DEFAULT),time(),$needs?0:1]);
        $u=sqlVrstica('SELECT * FROM uporabnik WHERE id=?',[(int)db()->lastInsertId()]);
        if ($needs) {
            cakajPotrditev($u);
            try { posljiKodo($u); flash('ok','Kodo smo poslali na tvoj e-naslov.'); }
            catch (DomainException $e) { flash('err',$e->getMessage()); }
            preusmeri('/potrditev');
        }
        prijavi($u); flash('ok','Račun je ustvarjen. Dobrodošel!'); preusmeri(DOMACA);
    } catch (DomainException $e) { $napaka=$e->getMessage(); }
    catch (Throwable $e) { error_log('PAT.si: registracija ni uspela.'); $napaka='Računa ni bilo mogoče ustvariti. Preveri, ali že obstaja.'; }
}
glava('Registracija – PAT.si'); ?>
<div class="auth"><h2>Ustvari račun</h2>
<?php if($napaka): ?><p class="msg err" role="alert"><?=h($napaka)?></p><?php endif; ?>
<p class="m"><?=preverjanjeVklopljeno()?'Registracijo zaključiš s šestmestno kodo, ki jo prejmeš po e-pošti.':'Preverjanje e-pošte je trenutno izključeno.'?></p>
<form method="post"><?=csrfPolje()?>
<label>Uporabniško ime<input name="uporabnisko_ime" value="<?=h($ime)?>" required maxlength="30" autocomplete="username"></label>
<label>E-naslov<input type="email" name="email" value="<?=h($email)?>" required maxlength="254" autocomplete="email"></label>
<label>Geslo<input type="password" name="geslo" required minlength="10" maxlength="72" autocomplete="new-password"></label>
<label>Ponovi geslo<input type="password" name="geslo2" required minlength="10" maxlength="72" autocomplete="new-password"></label>
<p class="m">Uporabi dolgo geslo (najmanj 10 znakov). Skrbniki lahko zaradi podpore in moderiranja pregledajo in uredijo uporabniške podatke, oglase ter pogovore. Dostop do pogovorov se beleži.</p>
<button>Ustvari račun</button></form><p class="m">Že imaš račun? <a href="/prijava">Prijavi se</a></p></div>
<?php noga(); ?>
