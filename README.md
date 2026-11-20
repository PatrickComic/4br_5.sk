# PAT.si

Spletna platforma za prodajo in izmenjavo šolskih potrebščin.

Ta različica vsebuje izvorne datoteke, prenesene z InfinityFree 20. 11. 2026.

## Funkcionalnosti

- Registracija s potrditveno kodo po e-pošti in omejitvami pošiljanja.
- Skrbniške ravni 0, 1 in 2; edini glavni skrbnik je račun admin.
- Upravljanje uporabnikov, oglasov in pogovorov ter dnevnik skrbniških dejanj.
- Podrobni pogled oglasa, galerija do 8 slik in samodejno menjavanje.
- Zasebni pogovori med kupci in prodajalci.

## Namestitev

Potreben je PHP 8.1 ali novejši z razširitvami PDO SQLite in OpenSSL ter spletni strežnik Apache. Datoteke prenesi v spletni root. Kopiraj config.example.php v config.local.php in nastavi lastne podatke SMTP (STARTTLS, port 587). Baza pat_baza.sqlite se ustvari samodejno, obstoječa baza pa se nadgradi ob prvem dostopu. Obstoječi račun admin dobi raven 2.

Produkcijska baza, gesla SMTP in uporabniške slike niso del repozitorija. PHPMailer je priložen v lib/PHPMailer z licenco LICENSE.

## Različice

Prejšnje različice so ohranjene z Gitovimi oznakami in GitHub Releases. Mape s podvojenimi različicami niso potrebne.
