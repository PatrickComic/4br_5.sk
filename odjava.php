<?php
require __DIR__ . '/inc.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Uporabi gumb Odjava.'); }
preveriCsrf();
$_SESSION = [];
session_regenerate_id(true);
flash('ok', 'Odjavljen si.');
preusmeri(DOMACA);
