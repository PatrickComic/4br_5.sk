<?php
// Kopiraj v config.local.php. Te datoteke ne objavljaj v GitHubu.
return [
    'database' => __DIR__ . '/pat_baza.sqlite',
    'site_url' => 'https://pat.site.je',
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_username' => '',
    'smtp_password' => '',
    'mail_from' => '',
    'mail_name' => 'PAT.si',
    // Samo če račun admin še ne obstaja: naključen skrivni niz vsaj 32 znakov.
    // Po ustvarjanju lastnika izbriši vrednost in datoteko namestitev.php.
    'setup_token' => '',
];
