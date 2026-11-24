<?php
/* PAT.si: migracije, varnost, preverjanje e-pošte in pravila skrbništva. */
function patConfig(): array {
    static $c;
    if ($c === null) {
        $c = ['database'=>__DIR__.'/pat_baza.sqlite','site_url'=>'https://pat.site.je',
            'smtp_host'=>'','smtp_port'=>587,'smtp_username'=>'','smtp_password'=>'',
            'mail_from'=>'','mail_name'=>'PAT.si','setup_token'=>''];
        if (is_file(__DIR__.'/config.local.php')) {
            $local = require __DIR__.'/config.local.php';
            if (is_array($local)) $c = array_replace($c, $local);
        }
    }
    return $c;
}
function postGeslo(string $name): string { return is_string($_POST[$name]??null) ? $_POST[$name] : ''; }
function sqlVrstica(string $sql, array $args=[]): ?array {
    $s=db()->prepare($sql); $s->execute($args); return $s->fetch() ?: null;
}
function sqlVse(string $sql, array $args=[]): array {
    $s=db()->prepare($sql); $s->execute($args); return $s->fetchAll();
}
function izvedi(string $sql, array $args=[]): void {
    $s=db()->prepare($sql); $s->execute($args);
}
function nadgradnja(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS pat_meta (kljuc TEXT PRIMARY KEY, vrednost TEXT NOT NULL)');
    if ($db->query("SELECT vrednost FROM pat_meta WHERE kljuc='schema'")->fetchColumn() === '2') return;
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ($db->query("SELECT vrednost FROM pat_meta WHERE kljuc='schema'")->fetchColumn() === '2') { $db->exec('COMMIT'); return; }
        $cols=array_column($db->query('PRAGMA table_info(uporabnik)')->fetchAll(), 'name');
        foreach (['admin_level'=>'INTEGER NOT NULL DEFAULT 0','email_verified'=>'INTEGER NOT NULL DEFAULT 0',
            'aktiviran'=>'INTEGER NOT NULL DEFAULT 1','blokiran'=>'INTEGER NOT NULL DEFAULT 0',
            'session_version'=>'INTEGER NOT NULL DEFAULT 0','zadnja_prijava'=>'INTEGER',
            'opomba_admin'=>'TEXT NOT NULL DEFAULT \'\''] as $name=>$def) {
            if (!in_array($name,$cols,true)) $db->exec("ALTER TABLE uporabnik ADD COLUMN $name $def");
        }
        $db->exec("UPDATE uporabnik SET admin_level=CASE WHEN uporabnisko_ime='admin' COLLATE NOCASE THEN 2 WHEN je_admin<>0 THEN 1 ELSE 0 END");
        $db->exec("UPDATE uporabnik SET uporabnisko_ime='admin',aktiviran=1,blokiran=0 WHERE admin_level=2");
        $db->exec('UPDATE uporabnik SET je_admin=CASE WHEN admin_level>0 THEN 1 ELSE 0 END');
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS pat_en_lastnik ON uporabnik(admin_level) WHERE admin_level=2;
            CREATE TABLE IF NOT EXISTS pat_nastavitve (kljuc TEXT PRIMARY KEY, vrednost TEXT NOT NULL);
            INSERT OR IGNORE INTO pat_nastavitve VALUES ('email_verification','1');
            CREATE TABLE IF NOT EXISTS pat_kode (uporabnik_id INTEGER PRIMARY KEY REFERENCES uporabnik(id) ON DELETE CASCADE,
                hash TEXT NOT NULL, potece INTEGER NOT NULL, poskusi INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS pat_omejitve (id INTEGER PRIMARY KEY, kljuc TEXT NOT NULL, ts INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS pat_limit_idx ON pat_omejitve(kljuc,ts);
            CREATE TABLE IF NOT EXISTS pat_dnevnik (id INTEGER PRIMARY KEY, akter_id INTEGER, akter TEXT NOT NULL,
                dejanje TEXT NOT NULL, cilj TEXT NOT NULL, podrobnosti TEXT NOT NULL, ts INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS pat_log_ts ON pat_dnevnik(ts);
            CREATE TRIGGER IF NOT EXISTS pat_owner_delete BEFORE DELETE ON uporabnik WHEN OLD.admin_level=2
                BEGIN SELECT RAISE(ABORT,'Glavnega skrbnika ni dovoljeno izbrisati.'); END;
            CREATE TRIGGER IF NOT EXISTS pat_owner_update BEFORE UPDATE ON uporabnik
                WHEN OLD.admin_level=2 AND (NEW.admin_level<>2 OR NEW.uporabnisko_ime<>'admin' OR NEW.blokiran<>0 OR NEW.aktiviran<>1)
                BEGIN SELECT RAISE(ABORT,'Glavni skrbnik mora ostati aktiven račun admin.'); END;
            CREATE TRIGGER IF NOT EXISTS pat_role_insert BEFORE INSERT ON uporabnik
                WHEN NEW.admin_level NOT IN (0,1,2) OR (NEW.admin_level=2 AND NEW.uporabnisko_ime<>'admin')
                BEGIN SELECT RAISE(ABORT,'Neveljavna raven.'); END;
            CREATE TRIGGER IF NOT EXISTS pat_role_update BEFORE UPDATE ON uporabnik
                WHEN NEW.admin_level NOT IN (0,1,2) OR (NEW.admin_level=2 AND NEW.uporabnisko_ime<>'admin')
                BEGIN SELECT RAISE(ABORT,'Neveljavna raven.'); END;
            INSERT OR REPLACE INTO pat_meta VALUES ('schema','2');");
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}
function preverjanjeVklopljeno(): bool {
    return (sqlVrstica("SELECT vrednost FROM pat_nastavitve WHERE kljuc='email_verification'")['vrednost'] ?? '1') === '1';
}
function prijavi(array $u): void {
    session_regenerate_id(true);
    $_SESSION['uid']=(int)$u['id']; $_SESSION['sv']=(int)$u['session_version'];
    unset($_SESSION['pending_uid'], $_SESSION['pending_until']);
    izvedi('UPDATE uporabnik SET zadnja_prijava=? WHERE id=?',[time(),$u['id']]);
}
function cakajPotrditev(array $u): void {
    session_regenerate_id(true); unset($_SESSION['uid'],$_SESSION['sv']);
    $_SESSION['pending_uid']=(int)$u['id']; $_SESSION['pending_sv']=(int)$u['session_version'];
    $_SESSION['pending_until']=time()+3600;
}
function cakajoci(): ?array {
    if (empty($_SESSION['pending_uid']) || (int)($_SESSION['pending_until']??0)<time()) return null;
    $u=sqlVrstica('SELECT * FROM uporabnik WHERE id=?',[(int)$_SESSION['pending_uid']]);
    return $u && !$u['blokiran'] && (int)$u['session_version']===(int)($_SESSION['pending_sv']??-1) ? $u : null;
}
function ipKljuc(): string { return hash('sha256', (string)($_SERVER['REMOTE_ADDR']??'local')); }
/* Vsako pravilo: [ključ, največ zahtev, časovno okno]. Atomsko tudi med sejami. */
function omeji(array $pravila): void {
    $db=db(); $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare('DELETE FROM pat_omejitve WHERE ts<?')->execute([time()-86400]);
        $s=$db->prepare('SELECT COUNT(*), MIN(ts) FROM pat_omejitve WHERE kljuc=? AND ts>?');
        foreach ($pravila as [$key,$max,$window]) {
            $s->execute([$key,time()-$window]); $r=$s->fetch(PDO::FETCH_NUM);
            if ((int)$r[0]>=$max) throw new DomainException('Preveč zahtev. Poskusi znova čez '.max(1,(int)$r[1]+$window-time()).' sekund.');
        }
        $s=$db->prepare('INSERT INTO pat_omejitve(kljuc,ts) VALUES (?,?)');
        foreach (array_unique(array_column($pravila,0)) as $key) $s->execute([$key,time()]);
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}
function postaPripravljena(): bool {
    $c=patConfig(); return $c['smtp_host']!=='' && $c['smtp_username']!=='' && $c['smtp_password']!=='' && filter_var($c['mail_from'],FILTER_VALIDATE_EMAIL);
}
function posljiPosto(string $komu,string $naslov,string $telo,string $html=''): bool {
    if (!postaPripravljena()) return false;
    require_once __DIR__.'/lib/PHPMailer/Exception.php';
    require_once __DIR__.'/lib/PHPMailer/PHPMailer.php';
    require_once __DIR__.'/lib/PHPMailer/SMTP.php';
    try {
        $c=patConfig(); $m=new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP(); $m->Host=$c['smtp_host']; $m->Port=(int)$c['smtp_port'];
        $m->SMTPAuth=true; $m->Username=$c['smtp_username']; $m->Password=$c['smtp_password'];
        $m->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $m->Timeout=15; $m->CharSet='UTF-8'; $m->setFrom($c['mail_from'],$c['mail_name']);
        $m->addAddress($komu); $m->Subject=$naslov; $m->Body=$telo;
        if ($html !== '') {
            $m->isHTML(true);
            $m->Body=$html;
            $m->AltBody=$telo;
            $logo=file_get_contents(__DIR__.'/logo.png');
            if ($logo===false) throw new RuntimeException('Logotipa ni mogoče prebrati.');
            $m->addStringEmbeddedImage($logo,'pat-logo','pat-logo.png','base64','image/png');
        }
        return $m->send();
    } catch (Throwable $e) { error_log('PAT.si: pošiljanje SMTP ni uspelo.'); return false; }
}
function posljiKodo(array $u): void {
    if ($u['aktiviran']) throw new DomainException('Račun je že aktiviran.');
    if (!postaPripravljena()) throw new DomainException('Pošiljanje e-pošte še ni nastavljeno. Obrni se na skrbnika.');
    $k='code-user:'.$u['id']; $ip='code-ip:'.ipKljuc();
    omeji([[$k,1,60],[$k,5,3600],[$k,10,86400],[$ip,20,3600],[$ip,50,86400],['code-global',200,3600]]);
    $code=(string)random_int(100000,999999); $hash=password_hash($code,PASSWORD_DEFAULT);
    izvedi('INSERT INTO pat_kode(uporabnik_id,hash,potece,poskusi) VALUES (?,?,?,0) ON CONFLICT(uporabnik_id) DO UPDATE SET hash=excluded.hash,potece=excluded.potece,poskusi=0',[$u['id'],$hash,time()+600]);
    require_once __DIR__.'/email.php';
    $posta=potrditvenaPosta((string)$u['uporabnisko_ime'],$code);
    if (!posljiPosto($u['email'],'PAT.si – potrditvena koda',$posta['besedilo'],$posta['html'])) {
        izvedi('DELETE FROM pat_kode WHERE uporabnik_id=? AND hash=?',[$u['id'],$hash]);
        throw new DomainException('Kode ni bilo mogoče poslati. Poskusi pozneje ali obvesti skrbnika.');
    }
}
function potrdiKodo(array $u,string $code): void {
    omeji([['verify-user:'.$u['id'],10,900],['verify-ip:'.ipKljuc(),40,900]]);
    $db=db(); $db->exec('BEGIN IMMEDIATE');
    try {
        $s=$db->prepare('SELECT * FROM pat_kode WHERE uporabnik_id=?'); $s->execute([$u['id']]); $k=$s->fetch();
        if (!$k || (int)$k['potece']<time() || (int)$k['poskusi']>=5) {
            $db->exec('COMMIT'); throw new DomainException('Koda je potekla ali zaklenjena. Zahtevaj novo.');
        }
        $s=$db->prepare('UPDATE pat_kode SET poskusi=poskusi+1 WHERE uporabnik_id=?'); $s->execute([$u['id']]);
        if (!preg_match('/^[0-9]{6}$/',$code) || !password_verify($code,$k['hash'])) {
            $left=4-(int)$k['poskusi']; $db->exec('COMMIT');
            throw new DomainException('Napačna koda. Preostali poskusi: '.$left.'.');
        }
        $s=$db->prepare('UPDATE uporabnik SET email_verified=1,aktiviran=1 WHERE id=? AND blokiran=0'); $s->execute([$u['id']]);
        $s=$db->prepare('DELETE FROM pat_kode WHERE uporabnik_id=?'); $s->execute([$u['id']]);
        $db->exec('COMMIT');
    } catch (Throwable $e) { try { $db->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
}
function dnevnik(array $a,string $action,string $target,string $details=''): void {
    izvedi('INSERT INTO pat_dnevnik(akter_id,akter,dejanje,cilj,podrobnosti,ts) VALUES (?,?,?,?,?,?)',[$a['id'],$a['uporabnisko_ime'],$action,$target,$details,time()]);
}
function skrbnik(): array {
    $u=uporabnik(); if (!$u || (int)$u['admin_level']<1) { http_response_code(403); exit('Dostop je dovoljen samo skrbnikom.'); }
    header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); return $u;
}
function smeUpravljati(array $a,array $u): bool {
    return (int)$a['admin_level']===2 || ((int)$a['admin_level']===1 && (int)$u['admin_level']===0);
}
function preveriCilj(array $a,int $id): array {
    $u=sqlVrstica('SELECT * FROM uporabnik WHERE id=?',[$id]);
    if (!$u || !smeUpravljati($a,$u)) throw new DomainException('Za ta račun nimaš dovoljenja.');
    return $u;
}
function preveriPogovor(array $a,int $id): array {
    $p=sqlVrstica('SELECT p.*, k.admin_level AS kupec_raven,s.admin_level AS prodajalec_raven FROM pogovor p JOIN uporabnik k ON k.id=p.kupec_id JOIN uporabnik s ON s.id=p.prodajalec_id WHERE p.id=?',[$id]);
    if (!$p || ((int)$a['admin_level']<2 && ((int)$p['kupec_raven']>0 || (int)$p['prodajalec_raven']>0))) throw new DomainException('Za ta pogovor nimaš dovoljenja.');
    return $p;
}
function veljavniPodatki(string $name,string $email): void {
    if (!preg_match('/^[\p{L}\p{N}_. -]{3,30}$/u',$name) || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) throw new DomainException('Preveri uporabniško ime (3–30 znakov) in e-naslov.');
}
function adminDejanje(array $a): void {
    preveriCsrf();
    $action=postText('akcija',40); $id=(int)postText('id',20);
    // Ponovno branje vlog pod zapisovalno ključavnico prepreči dirke z odvzemom pravic.
    $db=db(); $db->exec('BEGIN IMMEDIATE');
    try {
        $a=sqlVrstica('SELECT * FROM uporabnik WHERE id=?',[$a['id']]);
        if (!$a || $a['blokiran'] || (int)$a['admin_level']<1 || (int)$a['session_version']!==(int)($_SESSION['sv']??-1)) throw new DomainException('Seja ni več veljavna.');
        if ($action==='nastavitve') {
            $enabled=postText('email_verification')==='1';
            if ($enabled && !postaPripravljena()) throw new DomainException('Najprej nastavi SMTP v config.local.php.');
            izvedi("UPDATE pat_nastavitve SET vrednost=? WHERE kljuc='email_verification'",[$enabled?'1':'0']);
            dnevnik($a,'Nastavitve','e-pošta',$enabled?'Preverjanje vključeno':'Preverjanje izključeno');
        } elseif (in_array($action,['uporabnik','izbrisi_uporabnika','preklic_sej','vloga'],true)) {
            $u=preveriCilj($a,$id);
            if ($action==='uporabnik') {
                $name=postText('ime',30); $email=postText('email',254); veljavniPodatki($name,$email);
                if (strcasecmp($name,'admin')===0 && (int)$u['admin_level']!==2) throw new DomainException('Ime admin je rezervirano.');
                $blocked=postText('blokiran')==='1'?1:0;
                $verified=postText('email_verified')==='1'?1:0;
                $active=postText('aktiviran')==='1'?1:0;
                if ($verified) $active=1;
                if ((int)$u['admin_level']===2 && ($name!=='admin' || $blocked || !$active)) throw new DomainException('Glavnega skrbnika ni dovoljeno blokirati, preimenovati ali deaktivirati.');
                $pw=postGeslo('novo_geslo');
                if ($pw!=='' && (strlen($pw)<10 || strlen($pw)>72)) throw new DomainException('Novo geslo naj ima 10–72 bajtov.');
                izvedi('UPDATE uporabnik SET uporabnisko_ime=?,email=?,blokiran=?,email_verified=?,aktiviran=?,opomba_admin=?,session_version=session_version+1 WHERE id=?',[$name,$email,$blocked,$verified,$active,postText('opomba',2000),$id]);
                if ($pw!=='') izvedi('UPDATE uporabnik SET geslo_hash=? WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$id]);
                izvedi('DELETE FROM pat_kode WHERE uporabnik_id=?',[$id]);
                dnevnik($a,'Ureditev uporabnika',(string)$id,'Profil, stanje in preklic sej'.($pw!==''?'; ponastavljeno geslo':''));
            } elseif ($action==='izbrisi_uporabnika') {
                if ((int)$u['admin_level']===2) throw new DomainException('Glavnega skrbnika ni dovoljeno izbrisati.');
                if (postText('potrditev')!==$u['uporabnisko_ime']) throw new DomainException('Za izbris vnesi točno uporabniško ime.');
                // Slike počistimo po uspešnem potrjenem izbrisu v nadrejeni funkciji.
                $files=array_column(sqlVse('SELECT slika FROM oglas WHERE uporabnik_id=?',[$id]),'slika');
                izvedi('DELETE FROM uporabnik WHERE id=?',[$id]);
                dnevnik($a,'Izbris uporabnika',(string)$id,$u['uporabnisko_ime']);
            } elseif ($action==='vloga') {
                if ((int)$a['admin_level']!==2 || (int)$u['admin_level']===2) throw new DomainException('Samo glavni skrbnik lahko spreminja ravni 0 in 1.');
                $level=postText('raven')==='1'?1:0;
                if (!$u['aktiviran'] || $u['blokiran']) throw new DomainException('Skrbnik mora imeti aktiven, neblokiran račun.');
                izvedi('UPDATE uporabnik SET admin_level=?,je_admin=?,session_version=session_version+1 WHERE id=?',[$level,$level,$id]);
                dnevnik($a,'Sprememba ravni',(string)$id,'Nova raven '.$level);
            } else {
                izvedi('UPDATE uporabnik SET session_version=session_version+1 WHERE id=?',[$id]);
                dnevnik($a,'Preklic sej',(string)$id);
            }
        } elseif (in_array($action,['oglas','izbrisi_oglas'],true)) {
            $o=sqlVrstica('SELECT * FROM oglas WHERE id=?',[$id]);
            if (!$o) throw new DomainException('Oglas ne obstaja.');
            preveriCilj($a,(int)$o['uporabnik_id']);
            if ($action==='izbrisi_oglas') {
                izvedi('DELETE FROM oglas WHERE id=?',[$id]); $files=[$o['slika']];
            } else {
                $title=postText('naslov',80); $price=str_replace(',','.',postText('cena',30));
                $type=postText('vrsta'); $cat=postText('kat'); $state=postText('stanje');
                if ($title==='' || !in_array($type,VRSTE,true) || !in_array($cat,KAT,true) || !in_array($state,STANJA,true) || !is_numeric($price) || (float)$price<0 || (float)$price>100000) throw new DomainException('Preveri podatke oglasa.');
                izvedi('UPDATE oglas SET naslov=?,opis=?,vrsta=?,kat=?,stanje=?,cena=? WHERE id=?',[$title,postText('opis',300),$type,$cat,$state,$type==='Izmenjava'?0:(float)$price,$id]);
                if (postText('odstrani_sliko')==='1') { $files=[$o['slika']]; izvedi('UPDATE oglas SET slika=NULL WHERE id=?',[$id]); }
            }
            dnevnik($a,$action==='oglas'?'Ureditev oglasa':'Izbris oglasa',(string)$id);
        } elseif (in_array($action,['sporocilo','izbrisi_sporocilo','izbrisi_pogovor'],true)) {
            if ($action==='izbrisi_pogovor') { preveriPogovor($a,$id); izvedi('DELETE FROM pogovor WHERE id=?',[$id]); }
            else {
                $s=sqlVrstica('SELECT * FROM sporocilo WHERE id=?',[$id]);
                if (!$s) throw new DomainException('Sporočilo ne obstaja.');
                preveriPogovor($a,(int)$s['pogovor_id']);
                if ($action==='sporocilo') {
                    $text=postText('besedilo',1000); if ($text==='') throw new DomainException('Sporočilo ne sme biti prazno.');
                    izvedi('UPDATE sporocilo SET besedilo=? WHERE id=?',[$text,$id]);
                } else izvedi('DELETE FROM sporocilo WHERE id=?',[$id]);
                izvedi('UPDATE pogovor SET ts_zadnje=COALESCE((SELECT MAX(ts) FROM sporocilo WHERE pogovor_id=?),ts_zadnje) WHERE id=?',[$s['pogovor_id'],$s['pogovor_id']]);
            }
            dnevnik($a,'Moderiranje sporočil',(string)$id,$action);
        } else throw new DomainException('Neznano dejanje.');
        $db->exec('COMMIT');
        foreach ($files??[] as $f) izbrisiSliko($f);
    } catch (Throwable $e) { try { $db->exec('ROLLBACK'); } catch (Throwable $ignored) {} throw $e; }
}
