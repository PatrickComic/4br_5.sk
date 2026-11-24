<?php
require __DIR__.'/inc.php';
$a=skrbnik();
$tabs=['pregled'=>'Pregled','moderacija'=>'Pregled oglasov','uporabniki'=>'Uporabniki','oglasi'=>'Oglasi','pogovori'=>'Pogovori','dnevnik'=>'Dnevnik','nastavitve'=>'Nastavitve'];
$tab=is_string($_GET['zavihek']??null) && isset($tabs[$_GET['zavihek']]) ? $_GET['zavihek'] : 'pregled';
$id=max(0,is_scalar($_GET['id']??null)?(int)$_GET['id']:0); $page=max(1,min(100000,is_scalar($_GET['stran']??null)?(int)$_GET['stran']:1)); $offset=($page-1)*25;
$q=is_string($_GET['q']??null)?(function_exists('mb_substr')?mb_substr(trim($_GET['q']),0,100):substr(trim($_GET['q']),0,100)):'';
$filter=is_string($_GET['stanje']??null)?$_GET['stanje']:'';
$napaka='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (postText('akcija')==='moderacija') {
            odlociOglas($a);
        } elseif (postText('akcija')==='test_posta') {
            preveriCsrf(); omeji([['mail-test:'.$a['id'],3,3600]]);
            if (!posljiPosto($a['email'],'PAT.si – preizkus pošte','Preizkusna pošta je uspešno prispela.')) throw new DomainException('Pošiljanje ni uspelo. Preveri nastavitve SMTP.');
            dnevnik($a,'Preizkus pošte',(string)$a['id']);
        } elseif (postText('akcija')==='izvoz') {
            preveriCsrf();
            $rows=sqlVse('SELECT id,uporabnisko_ime,email,admin_level,email_verified,aktiviran,blokiran,datum_registracije,zadnja_prijava FROM uporabnik'.((int)$a['admin_level']===2?'':' WHERE admin_level=0').' ORDER BY id');
            dnevnik($a,'Izvoz uporabnikov','CSV');
            header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="PAT-uporabniki.csv"');
            $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
            fputcsv($out,['ID','Uporabniško ime','E-naslov','Raven','E-pošta potrjena','Aktiviran','Blokiran','Registracija (Unix)','Zadnja prijava (Unix)'],';');
            foreach($rows as $row) { foreach($row as &$cell) if(is_string($cell) && preg_match('/^[=+@\-\t\r\n]/',$cell)) $cell="'".$cell; unset($cell); fputcsv($out,$row,';'); }
            fclose($out); exit;
        } else adminDejanje($a);
        if ((int)postText('id',20)===(int)$a['id'] && in_array(postText('akcija'),['uporabnik','preklic_sej'],true)) {
            unset($_SESSION['uid'],$_SESSION['sv']); flash('ok','Spremembe so shranjene. Ponovno se prijavi.'); preusmeri('prijava.php');
        }
        flash('ok','Spremembe so shranjene.');
        preusmeri('admin.php?zavihek='.urlencode($tab).($id?'&id='.$id:''));
    } catch (DomainException $e) { $napaka=$e->getMessage(); }
    catch (Throwable $e) { error_log('PAT.si: skrbniška sprememba ni uspela.'); $napaka='Sprememba ni uspela. Preveri, ali so podatki edinstveni in veljavni.'; }
}
function adminForm(string $action,int $id=0,bool $confirm=false): void {
    echo '<form method="post" class="admin-form"'.($confirm?' onsubmit="return confirm(\'Res želiš izvesti to nepovratno dejanje?\')"':'').'>'.csrfPolje().'<input type="hidden" name="akcija" value="'.h($action).'"><input type="hidden" name="id" value="'.$id.'">';
}
function adminSelect(string $name,array $items,string $value): void {
    echo '<select name="'.h($name).'">'; foreach($items as $v) echo '<option value="'.h($v).'"'.($v===$value?' selected':'').'>'.h($v).'</option>'; echo '</select>';
}
function adminDatum($ts): string { return $ts?date('j. n. Y H:i',(int)$ts):'—'; }
function strani(int $count,int $page,string $tab,string $q='',string $filter='',int $id=0): void {
    $pages=max(1,(int)ceil($count/25)); echo '<nav class="pagination" aria-label="Strani"><span>'.$count.' zapisov · stran '.$page.' / '.$pages.'</span>';
    foreach ([$page-1=>'← Prejšnja',$page+1=>'Naslednja →'] as $n=>$text) if($n>=1 && $n<=$pages) echo '<a class="btn btn-ghost" href="?'.h(http_build_query(['zavihek'=>$tab,'stran'=>$n,'q'=>$q,'stanje'=>$filter,'id'=>$id])).'">'.h($text).'</a>';
    echo '</nav>';
}
$all=(int)$a['admin_level']===2;
$userScope=$all?'1=1':'u.admin_level=0';
$postScope=$all?'1=1':'u.admin_level=0';
$convScope=$all?'1=1':'k.admin_level=0 AND s.admin_level=0';
glava('Skrbništvo – PAT.si'); ?>
<div class="admin-head"><div><p class="eyebrow">PAT.si / Skrbništvo</p><h2>Nadzorna plošča</h2><p class="m"><?=h($a['uporabnisko_ime'])?> · raven <?=(int)$a['admin_level']?> · <?=$all?'Glavni skrbnik':'Upravljanje običajnih uporabnikov'?></p></div><a class="btn btn-ghost" href="pat.si.php">← Na spletno mesto</a></div>
<nav class="admin-tabs" aria-label="Skrbniški razdelki"><?php foreach($tabs as $key=>$title): ?><a class="<?=$key===$tab?'active':''?>" href="?zavihek=<?=h($key)?>"><?=h($title)?></a><?php endforeach; ?></nav>
<?php if($napaka): ?><div class="msg err" role="alert"><?=h($napaka)?></div><?php endif; ?>
<?php if(!postaPripravljena()): ?><div class="msg err">SMTP še ni nastavljen. Potrditvenih kod ni mogoče pošiljati. Preberi NAVODILA.md in nastavi config.local.php.</div><?php endif; ?>
<?php if($tab==='pregled'):
$stats=[
 'Uporabniki'=>sqlVrstica("SELECT COUNT(*) n FROM uporabnik u WHERE $userScope")['n'],
 'Čakajo na aktivacijo'=>sqlVrstica("SELECT COUNT(*) n FROM uporabnik u WHERE $userScope AND aktiviran=0")['n'],
 'Blokirani računi'=>sqlVrstica("SELECT COUNT(*) n FROM uporabnik u WHERE $userScope AND blokiran=1")['n'],
 'Čakajoči oglasi'=>sqlVrstica("SELECT COUNT(*) n FROM oglas WHERE moderacija='pending'")['n'],
 'Oglasi'=>sqlVrstica("SELECT COUNT(*) n FROM oglas o JOIN uporabnik u ON u.id=o.uporabnik_id WHERE $postScope")['n'],
 'Pogovori'=>sqlVrstica("SELECT COUNT(*) n FROM pogovor p JOIN uporabnik k ON k.id=p.kupec_id JOIN uporabnik s ON s.id=p.prodajalec_id WHERE $convScope")['n'],
 'Sporočila'=>sqlVrstica("SELECT COUNT(*) n FROM sporocilo m JOIN pogovor p ON p.id=m.pogovor_id JOIN uporabnik k ON k.id=p.kupec_id JOIN uporabnik s ON s.id=p.prodajalec_id WHERE $convScope")['n']]; ?>
<div class="admin-stats"><?php foreach($stats as $label=>$n): ?><article class="panel"><span class="m"><?=h($label)?></span><strong><?=(int)$n?></strong></article><?php endforeach; ?></div>
<section class="panel"><h3>Stanje sistema</h3><p>Preverjanje e-pošte: <strong><?=preverjanjeVklopljeno()?'vključeno':'izključeno'?></strong><br>Pošiljanje: <strong><?=postaPripravljena()?'SMTP nastavljen (dostavo preveri s preizkusom)':'ni nastavljeno'?></strong><br>Različica podatkovne sheme: 2</p><a href="?zavihek=nastavitve">Uredi nastavitve →</a></section>
<section class="panel"><h3>Pravila dostopa</h3><p>Raven 1 upravlja samo račune ravni 0 in njihove oglase. Pogovori s skrbniki so zaščiteni. Raven 2 lahko upravlja tudi skrbnike, vendar glavnega računa admin ni mogoče izbrisati, preimenovati ali znižati. Vsaka sprememba in vsak skrbniški vpogled v pogovor se zabeleži.</p><p class="m">Gesla so shranjena kot enosmerne zgoščene vrednosti. Izvirnih gesel ni mogoče prikazati. Uporabi ponastavitev gesla; zgoščenih gesel in potrditvenih kod ne prikazujemo.</p></section>
<?php elseif($tab==='moderacija'):
$where="o.moderacija='pending' AND (o.naslov LIKE ? OR o.opis LIKE ? OR u.uporabnisko_ime LIKE ?)";
$params=array_fill(0,3,'%'.$q.'%');
$total=(int)sqlVrstica("SELECT COUNT(*) n FROM oglas o JOIN uporabnik u ON u.id=o.uporabnik_id WHERE $where",$params)['n'];
$rows=sqlVse("SELECT o.*,u.uporabnisko_ime avtor FROM oglas o JOIN uporabnik u ON u.id=o.uporabnik_id WHERE $where ORDER BY o.ts,o.id LIMIT 25 OFFSET $offset",$params); ?>
<section class="panel"><h2>Pregled oglasov (<?=$total?>)</h2><p>Pred odločitvijo preglej naslov, opis, kategorijo in vse slike. Dovoljene so šolske potrebščine in povezana oprema. Oglasi z neprimerno vsebino ali nepovezanimi predmeti se zavrnejo. Avtor odločitev prejme v sporočilih.</p></section>
<form class="search" method="get"><input type="hidden" name="zavihek" value="moderacija"><input name="q" value="<?=h($q)?>" placeholder="Naslov, opis ali avtor" aria-label="Iskanje čakajočih oglasov"><button>Išči</button></form>
<?php if(!$rows): ?><section class="panel"><p>Ni oglasov, ki čakajo na pregled.</p></section><?php endif; ?>
<?php foreach($rows as $o): $original=sqlVrstica('SELECT * FROM oglas WHERE id=?',[$o['id']]); if(!$original || $original['moderacija']!=='pending') continue; $slike=slikeOglasa($original); ?>
<article class="panel pregled-oglasa"><h3>#<?=(int)$o['id']?> <?=h($o['naslov'])?></h3><p class="m">Avtor: <?=h($o['avtor'])?> · <?=h(adminDatum($o['ts']))?></p><p><?=h($o['vrsta'])?> · <?=h($o['kat'])?> · <?=h($o['stanje'])?> · <?=h(number_format((float)$o['cena'],2,',','.'))?> €</p><h4>Opis</h4><p><?=nl2br(h($o['opis']))?></p>
<div class="moderacija-slike"><?php foreach($slike as $i=>$slika): ?><a href="<?=h(URL_SLIK.$slika)?>" target="_blank" rel="noopener"><img src="<?=h(URL_SLIK.$slika)?>" alt="Slika <?=$i+1?> oglasa <?=h($o['naslov'])?>" loading="lazy"></a><?php endforeach; ?></div><?php if(!$slike): ?><p class="m">Oglas nima slik.</p><?php endif; ?>
<p><a href="pat.si.php?oglas=<?=(int)$o['id']?>" target="_blank" rel="noopener">Odpri celoten oglas</a></p>
<?php adminForm('moderacija',(int)$o['id']); ?><input type="hidden" name="razlicica" value="<?=h(hash('sha256',json_encode($original).json_encode($slike)))?>"><label>Razlog zavrnitve oziroma neobvezna opomba avtorju<textarea name="razlog" maxlength="1000" rows="3"></textarea></label><div class="moderacija-gumbi"><button name="odlocitev" value="approved">Odobri oglas</button><button class="btn-danger" name="odlocitev" value="rejected" onclick="const t=this.form.elements.razlog;if(!t.value.trim()){alert('Napiši razlog zavrnitve.');t.focus();return false;}t.setCustomValidity('');">Zavrni oglas</button></div></form>
</article><?php endforeach; ?>
<?php strani($total,$page,$tab,$q); ?>
<?php elseif($tab==='uporabniki' && $id):
try { $u=preveriCilj($a,$id); } catch(DomainException $e) { echo '<p class="msg err">'.h($e->getMessage()).'</p>'; noga(); exit; }
$posts=sqlVse('SELECT id,naslov,vrsta,cena,ts FROM oglas WHERE uporabnik_id=? ORDER BY id DESC LIMIT 25',[$id]);
$convs=sqlVse("SELECT p.id,p.oglas_naslov,k.uporabnisko_ime kupec,s.uporabnisko_ime prodajalec,p.ts_zadnje FROM pogovor p JOIN uporabnik k ON k.id=p.kupec_id JOIN uporabnik s ON s.id=p.prodajalec_id WHERE ($convScope) AND (p.kupec_id=? OR p.prodajalec_id=?) ORDER BY p.ts_zadnje DESC LIMIT 25",[$id,$id]); ?>
<p><a href="?zavihek=uporabniki">← Vsi uporabniki</a></p><div class="admin-grid"><section class="panel"><h3><?=h($u['uporabnisko_ime'])?> <span class="badge">ID <?=$id?> / raven <?=(int)$u['admin_level']?></span></h3>
<p class="m">Registracija: <?=h(adminDatum($u['datum_registracije']))?><br>Zadnja prijava: <?=h(adminDatum($u['zadnja_prijava']))?><br>Različica seje: <?=(int)$u['session_version']?></p>
<?php adminForm('uporabnik',$id); ?><label>Uporabniško ime<input name="ime" value="<?=h($u['uporabnisko_ime'])?>" maxlength="30" required <?=$u['admin_level']==2?'readonly':''?>></label>
<label>E-naslov<input name="email" type="email" maxlength="254" required value="<?=h($u['email'])?>"></label>
<label class="check"><input type="checkbox" name="email_verified" value="1" <?=$u['email_verified']?'checked':''?>> E-naslov potrjen (ročno preverjeno)</label>
<label class="check"><input type="checkbox" name="aktiviran" value="1" <?=$u['aktiviran']?'checked':''?>> Račun aktiviran</label>
<label class="check"><input type="checkbox" name="blokiran" value="1" <?=$u['blokiran']?'checked':''?>> Blokiran račun</label>
<label>Interna opomba<textarea name="opomba" maxlength="2000" rows="3"><?=h($u['opomba_admin'])?></textarea></label>
<label>Novo geslo (pusti prazno, da ostane enako)<input type="password" name="novo_geslo" minlength="10" maxlength="72" autocomplete="new-password"></label>
<p class="m">Vsako shranjevanje prekliče obstoječe seje in potrditvene kode. Če spreminjaš e-pošto, ustrezno nastavi potrditev in aktivacijo. Tudi sprememba lastnega računa zahteva novo prijavo.</p><button>Shrani uporabnika</button></form></section>
<section class="panel"><h3>Dostop in vloga</h3><?php adminForm('preklic_sej',$id); ?><p>Odjavi uporabnika na vseh napravah.</p><button class="btn-ghost">Prekliči vse seje</button></form>
<?php if($all && $u['admin_level']!=2): ?><hr><?php adminForm('vloga',$id); ?><label>Raven dostopa<select name="raven"><option value="0" <?=$u['admin_level']==0?'selected':''?>>0 – uporabnik</option><option value="1" <?=$u['admin_level']==1?'selected':''?>>1 – skrbnik</option></select></label><button>Spremeni raven</button></form><?php endif; ?>
<?php if($u['admin_level']!=2): ?><hr><h3>Izbris računa</h3><p>Izbris odstrani tudi oglase in povezane pogovore ter sporočila. Dejanja ni mogoče razveljaviti.</p><?php adminForm('izbrisi_uporabnika',$id,true); ?><label>Za potrditev vnesi uporabniško ime<input name="potrditev" required autocomplete="off"></label><button class="btn-danger">Trajno izbriši uporabnika</button></form><?php endif; ?></section></div>
<section class="panel"><h3>Oglasi tega uporabnika (zadnjih 25)</h3><?php foreach($posts as $o): ?><p><a href="?zavihek=oglasi&id=<?=(int)$o['id']?>">#<?=(int)$o['id']?> <?=h($o['naslov'])?></a> · <?=h($o['vrsta'])?> · <?=h($o['cena'])?> €</p><?php endforeach; ?><a href="?zavihek=oglasi&q=<?=urlencode($u['uporabnisko_ime'])?>">Preišči vse oglase →</a></section>
<section class="panel"><h3>Pogovori tega uporabnika (zadnjih 25 dovoljenih)</h3><?php foreach($convs as $p): ?><p><a href="?zavihek=pogovori&id=<?=(int)$p['id']?>"><?=h($p['kupec'])?> ↔ <?=h($p['prodajalec'])?> · <?=h($p['oglas_naslov'])?></a></p><?php endforeach; ?><a href="?zavihek=pogovori&q=<?=urlencode($u['uporabnisko_ime'])?>">Preišči vse pogovore →</a></section>
<?php elseif($tab==='uporabniki'):
$where="($userScope) AND (u.uporabnisko_ime LIKE ? OR u.email LIKE ?)"; $params=['%'.$q.'%','%'.$q.'%'];
if($filter==='cakajoci') $where.=' AND u.aktiviran=0';
if($filter==='blokirani') $where.=' AND u.blokiran=1';
if($filter==='skrbniki') $where.=' AND u.admin_level>0';
$total=(int)sqlVrstica("SELECT COUNT(*) n FROM uporabnik u WHERE $where",$params)['n'];
$rows=sqlVse("SELECT u.*, (SELECT COUNT(*) FROM oglas WHERE uporabnik_id=u.id) oglasi FROM uporabnik u WHERE $where ORDER BY u.id DESC LIMIT 25 OFFSET $offset",$params); ?>
<form class="search" method="get"><input type="hidden" name="zavihek" value="uporabniki"><input name="q" value="<?=h($q)?>" placeholder="Ime ali e-naslov" aria-label="Iskanje uporabnikov"><select name="stanje" aria-label="Stanje računa"><?php foreach([''=>'Vsa stanja','cakajoci'=>'Čakajoči','blokirani'=>'Blokirani','skrbniki'=>'Skrbniki'] as $v=>$label): ?><option value="<?=h($v)?>" <?=$filter===$v?'selected':''?>><?=h($label)?></option><?php endforeach; ?></select><button>Išči</button></form>
<section class="panel table-wrap"><table class="admin-table"><thead><tr><th>ID / Ime</th><th>E-naslov</th><th>Raven</th><th>Stanje</th><th>Oglasi</th><th>Registracija</th><th></th></tr></thead><tbody><?php foreach($rows as $u): ?><tr><td>#<?=(int)$u['id']?> <strong><?=h($u['uporabnisko_ime'])?></strong></td><td><?=h($u['email'])?></td><td><?=(int)$u['admin_level']?></td><td><?= $u['blokiran']?'Blokiran':($u['aktiviran']?'Aktiven':'Čaka na kodo')?><br><span class="m"><?=$u['email_verified']?'Potrjen e-naslov':'E-naslov ni potrjen'?></span></td><td><?=(int)$u['oglasi']?></td><td><?=h(adminDatum($u['datum_registracije']))?></td><td><a class="btn btn-ghost" href="?zavihek=uporabniki&id=<?=(int)$u['id']?>">Odpri</a></td></tr><?php endforeach; ?></tbody></table><?php if(!$rows): ?><p>Ni ustreznih uporabnikov.</p><?php endif; ?></section>
<?php strani($total,$page,$tab,$q,$filter); adminForm('izvoz'); ?><button class="btn-ghost">Izvozi dovoljene uporabnike (CSV)</button></form>
<?php elseif($tab==='oglasi' && $id):
$o=sqlVrstica('SELECT * FROM oglas WHERE id=?',[$id]);
try { if(!$o) throw new DomainException('Oglas ne obstaja.'); $owner=preveriCilj($a,(int)$o['uporabnik_id']); } catch(DomainException $e) { echo '<p class="msg err">'.h($e->getMessage()).'</p>'; noga(); exit; } ?>
<p><a href="?zavihek=oglasi">← Vsi oglasi</a></p><section class="panel"><h3>Oglas #<?=$id?></h3><p>Avtor: <a href="?zavihek=uporabniki&id=<?=(int)$owner['id']?>"><?=h($owner['uporabnisko_ime'])?></a> · <?=h(adminDatum($o['ts']))?></p>
<?php if($o['slika'] && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/',$o['slika'])): ?><img class="admin-image" src="<?=h(URL_SLIK.$o['slika'])?>" alt="Slika oglasa"><?php endif; ?>
<?php adminForm('oglas',$id); ?><label>Naslov<input name="naslov" maxlength="80" required value="<?=h($o['naslov'])?>"></label><label>Opis<textarea name="opis" maxlength="300" rows="4"><?=h($o['opis'])?></textarea></label><div class="admin-grid"><label>Vrsta<?php adminSelect('vrsta',VRSTE,$o['vrsta']); ?></label><label>Kategorija<?php adminSelect('kat',KAT,$o['kat']); ?></label><label>Stanje<?php adminSelect('stanje',STANJA,$o['stanje']); ?></label><label>Cena (€)<input name="cena" type="number" step="0.01" min="0" max="100000" required value="<?=h($o['cena'])?>"></label></div><label class="check"><input type="checkbox" name="odstrani_sliko" value="1"> Odstrani sliko</label><button>Shrani oglas</button></form>
<hr><?php adminForm('izbrisi_oglas',$id,true); ?><button class="btn-danger">Trajno izbriši oglas</button><p class="m">Pogovori ostanejo shranjeni.</p></form></section>
<?php elseif($tab==='oglasi'):
$where="($postScope) AND (o.naslov LIKE ? OR o.opis LIKE ? OR u.uporabnisko_ime LIKE ?)"; $params=array_fill(0,3,'%'.$q.'%');
$total=(int)sqlVrstica("SELECT COUNT(*) n FROM oglas o JOIN uporabnik u ON u.id=o.uporabnik_id WHERE $where",$params)['n'];
$rows=sqlVse("SELECT o.*,u.uporabnisko_ime avtor FROM oglas o JOIN uporabnik u ON u.id=o.uporabnik_id WHERE $where ORDER BY o.id DESC LIMIT 25 OFFSET $offset",$params); ?>
<form class="search" method="get"><input type="hidden" name="zavihek" value="oglasi"><input name="q" value="<?=h($q)?>" placeholder="Naslov, opis ali avtor" aria-label="Iskanje oglasov"><button>Išči</button></form><section class="panel table-wrap"><table class="admin-table"><thead><tr><th>ID / Naslov</th><th>Avtor</th><th>Vrsta / kategorija</th><th>Cena</th><th>Objavljeno</th><th></th></tr></thead><tbody><?php foreach($rows as $o): ?><tr><td>#<?=(int)$o['id']?> <?=h($o['naslov'])?></td><td><?=h($o['avtor'])?></td><td><?=h($o['vrsta'])?><br><?=h($o['kat'])?></td><td><?=h($o['cena'])?> €</td><td><?=h(adminDatum($o['ts']))?></td><td><a class="btn btn-ghost" href="?zavihek=oglasi&id=<?=(int)$o['id']?>">Uredi</a></td></tr><?php endforeach; ?></tbody></table><?php if(!$rows): ?><p>Ni ustreznih oglasov.</p><?php endif; ?></section><?php strani($total,$page,$tab,$q); ?>
<?php elseif($tab==='pogovori' && $id):
try { $p=preveriPogovor($a,$id); } catch(DomainException $e) { echo '<p class="msg err">'.h($e->getMessage()).'</p>'; noga(); exit; }
dnevnik($a,'Vpogled v pogovor',(string)$id,'Brez spreminjanja prebranosti');
$total=(int)sqlVrstica('SELECT COUNT(*) n FROM sporocilo WHERE pogovor_id=?',[$id])['n'];
$rows=sqlVse("SELECT m.*,u.uporabnisko_ime avtor FROM sporocilo m JOIN uporabnik u ON u.id=m.posiljatelj_id WHERE pogovor_id=? ORDER BY m.id DESC LIMIT 25 OFFSET $offset",[$id]);
$people=sqlVse('SELECT id,uporabnisko_ime FROM uporabnik WHERE id IN (?,?)',[$p['kupec_id'],$p['prodajalec_id']]); ?>
<p><a href="?zavihek=pogovori">← Vsi pogovori</a></p><section class="panel"><h3><?=h($p['oglas_naslov'])?> <span class="badge">Pogovor #<?=$id?></span></h3><p><?php foreach($people as $person): ?><a href="?zavihek=uporabniki&id=<?=(int)$person['id']?>"><?=h($person['uporabnisko_ime'])?></a> · <?php endforeach; ?></p><p class="m">Najnovejša sporočila so prva. Ta vpogled se beleži in sporočil ne označi kot prebranih. Skrbnik ne more pošiljati sporočil v imenu uporabnika.</p>
<?php foreach($rows as $m): ?><article class="admin-message"><p><strong><?=h($m['avtor'])?></strong> · <?=h(adminDatum($m['ts']))?> · #<?=(int)$m['id']?> · <?=$m['prebrano']?'Prebrano':'Neprebrano'?></p><?php adminForm('sporocilo',(int)$m['id']); ?><label>Besedilo<textarea name="besedilo" maxlength="1000" rows="3" required><?=h($m['besedilo'])?></textarea></label><button class="btn-ghost">Shrani popravek</button></form><?php adminForm('izbrisi_sporocilo',(int)$m['id'],true); ?><button class="btn-danger">Izbriši sporočilo</button></form></article><?php endforeach; ?>
<?php strani($total,$page,$tab,'','',$id); ?><hr><?php adminForm('izbrisi_pogovor',$id,true); ?><button class="btn-danger">Trajno izbriši celoten pogovor</button></form></section>
<?php elseif($tab==='pogovori'):
$where="($convScope) AND (p.oglas_naslov LIKE ? OR k.uporabnisko_ime LIKE ? OR s.uporabnisko_ime LIKE ?)"; $params=array_fill(0,3,'%'.$q.'%');
$join='FROM pogovor p JOIN uporabnik k ON k.id=p.kupec_id JOIN uporabnik s ON s.id=p.prodajalec_id';
$total=(int)sqlVrstica("SELECT COUNT(*) n $join WHERE $where",$params)['n'];
$rows=sqlVse("SELECT p.*,k.uporabnisko_ime kupec,s.uporabnisko_ime prodajalec,(SELECT COUNT(*) FROM sporocilo WHERE pogovor_id=p.id) stevilo $join WHERE $where ORDER BY p.ts_zadnje DESC,p.id DESC LIMIT 25 OFFSET $offset",$params); ?>
<form class="search" method="get"><input type="hidden" name="zavihek" value="pogovori"><input name="q" value="<?=h($q)?>" placeholder="Uporabnik ali naslov oglasa" aria-label="Iskanje pogovorov"><button>Išči</button></form><section class="panel table-wrap"><table class="admin-table"><thead><tr><th>ID / Oglas</th><th>Kupec</th><th>Prodajalec</th><th>Sporočila</th><th>Zadnja dejavnost</th><th></th></tr></thead><tbody><?php foreach($rows as $p): ?><tr><td>#<?=(int)$p['id']?> <?=h($p['oglas_naslov'])?><?=$p['oglas_id']===null?' (oglas izbrisan)':''?></td><td><?=h($p['kupec'])?></td><td><?=h($p['prodajalec'])?></td><td><?=(int)$p['stevilo']?></td><td><?=h(adminDatum($p['ts_zadnje']))?></td><td><a class="btn btn-ghost" href="?zavihek=pogovori&id=<?=(int)$p['id']?>">Preglej</a></td></tr><?php endforeach; ?></tbody></table><?php if(!$rows): ?><p>Ni dovoljenih pogovorov za prikaz.</p><?php endif; ?></section><?php strani($total,$page,$tab,$q); ?>
<?php elseif($tab==='dnevnik'):
$where=$all?'1=1':'akter_id=?'; $params=$all?[]:[$a['id']];
$where.=' AND (dejanje LIKE ? OR cilj LIKE ? OR akter LIKE ?)'; $params=array_merge($params,array_fill(0,3,'%'.$q.'%'));
$total=(int)sqlVrstica("SELECT COUNT(*) n FROM pat_dnevnik WHERE $where",$params)['n'];
$rows=sqlVse("SELECT * FROM pat_dnevnik WHERE $where ORDER BY id DESC LIMIT 25 OFFSET $offset",$params); ?>
<p class="m">Dnevnik je v tej aplikaciji samo za branje. Gesla, kode in vsebina sporočil se ne zapisujejo. Raven 1 vidi samo svoja dejanja.</p><form class="search" method="get"><input type="hidden" name="zavihek" value="dnevnik"><input name="q" value="<?=h($q)?>" placeholder="Dejanje, cilj ali skrbnik" aria-label="Iskanje dnevnika"><button>Išči</button></form><section class="panel table-wrap"><table class="admin-table"><thead><tr><th>Čas</th><th>Skrbnik</th><th>Dejanje</th><th>Cilj</th><th>Podrobnosti</th></tr></thead><tbody><?php foreach($rows as $log): ?><tr><td><?=h(adminDatum($log['ts']))?></td><td><?=h($log['akter'])?></td><td><?=h($log['dejanje'])?></td><td><?=h($log['cilj'])?></td><td><?=h($log['podrobnosti'])?></td></tr><?php endforeach; ?></tbody></table></section><?php strani($total,$page,$tab,$q); ?>
<?php elseif($tab==='nastavitve'): ?>
<div class="admin-grid"><section class="panel"><h3>Registracija in preverjanje</h3><?php adminForm('nastavitve'); ?><label class="check"><input type="checkbox" name="email_verification" value="1" <?=preverjanjeVklopljeno()?'checked':''?>> Zahtevaj potrditveno kodo pri novih registracijah</label><p class="m">Obstoječi aktivni računi ostanejo aktivni. Izklop omogoči tudi čakajočim računom zaključek registracije brez kode. Ponovni vklop že aktivnih računov ne zaklene. Neodvisno od tega je status »potrjen e-naslov« prikazan posebej.</p><button>Shrani nastavitve</button></form></section><section class="panel"><h3>E-poštni sistem</h3><p>SMTP: <?=postaPripravljena()?'nastavljen':'ni nastavljen'?><br>Povezava: STARTTLS, vrata <?=(int)patConfig()['smtp_port']?><br>Pošiljatelj: <?=h(patConfig()['mail_from'])?></p><p class="m">Poverilnice ostanejo v config.local.php in niso prikazane v nadzorni plošči. Preizkus gre samo na tvoj e-naslov.</p><?php adminForm('test_posta'); ?><button>Pošlji preizkusno pošto sebi</button></form></section></div>
<section class="panel"><h3>Omejitve potrditvenih kod</h3><ul><li>Veljavnost kode: 10 minut.</li><li>Največ 5 napačnih vnosov na kodo; dodatno 10 preverjanj na račun v 15 minutah.</li><li>Pošiljanje: najmanj 60 sekund med zahtevama, največ 5 na uro in 10 na dan na račun.</li><li>Na naslov IP: največ 20 pošiljanj na uro, 50 na dan; skupno največ 200 na uro.</li><li>Nova koda razveljavi prejšnjo. Tudi neuspešno pošiljanje porabi omejitev.</li><li>Prijava: največ 10 zahtev na ime in 30 na IP v 15 minutah; registracija največ 10 na IP na uro.</li></ul></section>
<?php endif; ?>
<?php noga(); ?>
