# PAT.si

PAT.si je spletna platforma za prodajo in izmenjavo šolskih potrebščin. Uporabnikom omogoča, da učbenikom, zvezkom, torbam in drugemu šolskemu priboru poiščejo novega lastnika ter se neposredno dogovorijo za nakup ali zamenjavo.

## Glavne funkcionalnosti

- Registracija in prijava s preverjanjem e-pošte s potrditveno kodo.
- Objavljanje oglasov s ceno oziroma predlogom zamenjave ter galerijo do osmih slik.
- Iskanje in filtriranje oglasov po kategoriji, stanju in vrsti ponudbe.
- Osebni profil s pregledom lastnih oglasov in njihovega stanja.
- Zasebni pogovori med uporabniki ter obvestila o odobritvi ali zavrnitvi oglasa.
- Ročni pregled novih oglasov pred javno objavo.
- Skrbniška nadzorna plošča za upravljanje uporabnikov, oglasov in pogovorov, nastavitve ter dnevnik dejanj. Dovoljenja so razdeljena med uporabnike, skrbnike in glavnega skrbnika.
- Slovenski vmesnik, prilagojen različnim velikostim zaslona, in pregledni URL-ji brez končnice `.php`.

## Tehnologija in namestitev

Projekt uporablja PHP 8.1 ali novejši, SQLite in PHPMailer. Potreben je Apache z omogočenimi prepisovanjem URL-jev (`mod_rewrite`) in pravili `.htaccess` ter razširitvama PDO SQLite in OpenSSL.

Izvorne datoteke prenesi v spletni koren skupaj z datotekami `.htaccess`. Kopiraj `config.example.php` v `config.local.php` in nastavi naslov strani ter podatke SMTP. Strežnik mora imeti dovoljenje za zapisovanje baze in naloženih slik. Baza se ob prvem zagonu ustvari oziroma nadgradi samodejno; obstoječi račun `admin` postane glavni skrbnik.

Produkcijska baza, zasebna konfiguracija in uporabniške slike niso vključene v repozitorij. PHPMailer je priložen v `lib/PHPMailer` skupaj z licenco.

## Različice

Izdaje in kratki povzetki sprememb so na voljo v [GitHub Releases](https://github.com/PatrickComic/4br_5.sk/releases).
