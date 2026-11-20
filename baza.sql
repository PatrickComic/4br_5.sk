-- =====================================================
--  pat.si – zasnova baze (SQLite)
--  Datoteka se samodejno izvede ob prvem obisku strani
--  (ali ob posodobitvi, če tabel za sporočila še ni).
--  Podatki se shranjujejo v pat_baza.sqlite.
-- =====================================================

PRAGMA foreign_keys = ON;

-- Uporabniki: prijava z uporabniškim imenom ali e-naslovom.
-- Prvi registrirani uporabnik je samodejno skrbnik (je_admin = 1).
CREATE TABLE IF NOT EXISTS uporabnik (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    uporabnisko_ime     TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    email               TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    geslo_hash          TEXT    NOT NULL,
    je_admin            INTEGER NOT NULL DEFAULT 0,
    datum_registracije  INTEGER NOT NULL
);

-- Oglasi: ime in e-naslov avtorja se vzameta iz tabele uporabnik.
CREATE TABLE IF NOT EXISTS oglas (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    uporabnik_id  INTEGER NOT NULL,
    naslov        TEXT    NOT NULL,
    vrsta         TEXT    NOT NULL CHECK (vrsta IN ('Prodaja', 'Izmenjava')),
    kat           TEXT    NOT NULL,
    stanje        TEXT    NOT NULL CHECK (stanje IN ('Novo', 'Rabljeno')),
    cena          REAL    NOT NULL DEFAULT 0,
    opis          TEXT    NOT NULL DEFAULT '',
    ts            INTEGER NOT NULL,
    slika         TEXT,
    FOREIGN KEY (uporabnik_id) REFERENCES uporabnik(id) ON DELETE CASCADE
);

-- Pogovori (predalnik sporočil): en pogovor med kupcem in prodajalcem
-- za posamezen oglas. Ko je oglas izbrisan, pogovor ostane (oglas_id = NULL),
-- naslov oglasa pa se ohrani v oglas_naslov.
CREATE TABLE IF NOT EXISTS pogovor (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    oglas_id       INTEGER,
    oglas_naslov   TEXT    NOT NULL,
    kupec_id       INTEGER NOT NULL,
    prodajalec_id  INTEGER NOT NULL,
    ts_zadnje      INTEGER NOT NULL,
    UNIQUE (oglas_id, kupec_id),
    FOREIGN KEY (oglas_id)      REFERENCES oglas(id)      ON DELETE SET NULL,
    FOREIGN KEY (kupec_id)      REFERENCES uporabnik(id)  ON DELETE CASCADE,
    FOREIGN KEY (prodajalec_id) REFERENCES uporabnik(id)  ON DELETE CASCADE
);

-- Sporočila znotraj pogovora. prebrano = 0 pomeni, da ga prejemnik še ni odprl.
CREATE TABLE IF NOT EXISTS sporocilo (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    pogovor_id      INTEGER NOT NULL,
    posiljatelj_id  INTEGER NOT NULL,
    besedilo        TEXT    NOT NULL,
    ts              INTEGER NOT NULL,
    prebrano        INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (pogovor_id)     REFERENCES pogovor(id)    ON DELETE CASCADE,
    FOREIGN KEY (posiljatelj_id) REFERENCES uporabnik(id)  ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_oglas_ts          ON oglas(ts);
CREATE INDEX IF NOT EXISTS idx_oglas_uporabnik   ON oglas(uporabnik_id);
CREATE INDEX IF NOT EXISTS idx_pog_kupec         ON pogovor(kupec_id, ts_zadnje);
CREATE INDEX IF NOT EXISTS idx_pog_prodajalec    ON pogovor(prodajalec_id, ts_zadnje);
CREATE INDEX IF NOT EXISTS idx_sp_pogovor        ON sporocilo(pogovor_id, id);
CREATE INDEX IF NOT EXISTS idx_sp_neprebrano     ON sporocilo(pogovor_id, prebrano);
