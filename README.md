# PAT.si

**PAT.si** je spletna platforma za objavljanje oglasov za prodajo in izmenjavo šolskih potrebščin. Uporabnikom omogoča ustvarjanje računov, pregledovanje in objavljanje oglasov ter neposredno komunikacijo med zainteresiranimi kupci in prodajalci.

## Tehnologije

- **PHP** – strežniška logika, uporabniški računi in obdelava obrazcev
- **SQLite** s **PDO** – shranjevanje podatkov
- **HTML in CSS** – struktura in oblikovanje uporabniškega vmesnika
- **JavaScript** – interaktivni elementi strani
- **Apache / `.htaccess`** – pravila dostopa do datotek

## Funkcionalnosti

- Registracija in prijava z uporabniškim imenom oziroma e-poštnim naslovom
- Objavljanje in pregledovanje oglasov za **prodajo** ali **izmenjavo**
- Kategorije šolskih potrebščin (učbeniki, zvezki, torbe, pisala, likovni material, športna oprema in drugo)
- Označevanje stanja izdelka kot **novo** ali **rabljeno**
- Dodajanje slik oglasom (JPG, PNG, WebP; največ 5 MB)
- Uporabniški račun in upravljanje lastnih oglasov
- Zasebni pogovori med kupcem in prodajalcem, povezani z oglasom
- Prikaz neprebranih sporočil
- Poskus pošiljanja e-poštnega obvestila ob novem sporočilu
- Skrbniške pravice za prvega registriranega uporabnika

## Struktura projekta

```text
PAT.si/
├── pat.si.php         # Glavna stran in oglasi
├── inc.php            # Skupne funkcije, seja, baza, varnost, glava in noga
├── baza.sql           # Shema podatkovne baze SQLite
├── prijava.php        # Prijava
├── registracija.php   # Registracija
├── odjava.php         # Odjava
├── moj-racun.php      # Uporabniški račun
├── sporocila.php      # Zasebna sporočila
├── stil.css           # Slogi spletne strani
├── logo.png           # Logotip
├── .htaccess          # Zaščita baze in datotek SQL
└── uploads/
    └── .htaccess      # Omejitve izvajanja kode v mapi s slikami
```

Datoteka `pat_baza.sqlite` nastane med delovanjem aplikacije in ni vključena v izvorni arhiv.

## Namestitev in zagon

### Zahteve

- Spletni strežnik s podporo za PHP (na primer Apache)
- PHP z omogočenima razširitvama **PDO SQLite** in **SQLite3** oziroma ustreznim gonilnikom `pdo_sqlite`
- Pravice pisanja za ustvarjanje datoteke SQLite in shranjevanje slik
- Za e-poštna obvestila: delujoča konfiguracija PHP-funkcije `mail()`

### Postopek

1. Prenesi datoteke projekta v mapo spletnega strežnika (npr. `htdocs/PAT.si/` v XAMPP).
2. Preveri, da PHP podpira SQLite prek PDO.
3. Poskrbi, da ima PHP dovoljenje za pisanje v mapo projekta in `uploads/`.
4. Odpri `pat.si.php` v brskalniku, npr. `http://localhost/PAT.si/pat.si.php`.
5. Ob prvi vzpostavitvi povezave z bazo aplikacija ustvari `pat_baza.sqlite` in izvede shemo iz `baza.sql`.
6. Registriraj prvi uporabniški račun; ta samodejno dobi skrbniške pravice.

> **Opomba:** V kodi je glavna stran poimenovana `pat.si.php`, ne `index.php`. Zato pri lokalnem zagonu uporabi njeno dejansko ime ali ustrezno nastavi privzeto stran strežnika.

## Podatkovna baza

Projekt uporablja SQLite. Shema je zapisana v `baza.sql` in vsebuje štiri glavne tabele:

| Tabela | Namen |
|---|---|
| `uporabnik` | Računi, zgoščena gesla in skrbniške pravice |
| `oglas` | Podatki o oglasih in njihovih avtorjih |
| `pogovor` | Pogovori med kupcem in prodajalcem za posamezen oglas |
| `sporocilo` | Posamezna sporočila, čas pošiljanja in stanje prebranosti |

Aplikacija vsebuje tudi logiko za prilagajanje nekaterih starejših struktur baze.

## E-poštna obvestila

Funkcija `obvestiEmail()` v `inc.php` uporablja PHP-funkcijo `mail()` za obveščanje o novih zasebnih sporočilih. Nastavitvi sta zapisani v isti datoteki:

```php
const OBVESTILA_EMAIL = 'info@pat.si';
const SPLETNO_MESTO = 'https://pat.si';
```

Obvestilo vsebuje pošiljateljevo uporabniško ime, naslov oglasa in povezavo do pogovora. Dejanska dostava **ni zagotovljena**: odvisna je od nastavitev poštnega sistema strežnika. Koda uporablja `@mail()`, zato opozorila te funkcije niso neposredno prikazana.

## Varnost

V izvorni kodi so med drugim uporabljeni:

- Zgoščevanje gesel s `password_hash()`
- Pripravljene SQL-poizvedbe prek PDO
- CSRF-žetoni pri obrazcih
- Preverjanje prijave in uporabniških dovoljenj
- Ubežanje izhodnega HTML-ja
- Preverjanje vrste in velikosti naloženih slik
- Nastavitve sej, vključno s `HttpOnly` in `SameSite=Lax`
- Pravila `.htaccess`, ki omejujejo dostop do baze in izvajanje skript v mapi `uploads/`

To so obstoječi zaščitni ukrepi, ne potrditev, da je bila aplikacija celovito varnostno pregledana.

## Nastavitve

Glavne nastavitve so na začetku `inc.php`: pot do baze, naslov spletnega mesta, e-poštni naslov za obvestila, kategorije, vrste in stanja oglasov ter omejitev velikosti slike.

## Status projekta

Projekt je funkcionalno zasnovan kot preprosta oglasna platforma za šolske potrebščine. Pred javno uporabo je priporočljivo preveriti pravice datotek, delovanje e-poštnih obvestil in konfiguracijo produkcijskega strežnika.
