<?php
/* Predloga potrditvene pošte: HTML in enakovredna besedilna različica. */
function potrditvenaPosta(string $ime, string $koda): array {
    if (!preg_match('/^[0-9]{6}$/D', $koda)) {
        throw new InvalidArgumentException('Potrditvena koda mora imeti šest števk.');
    }
    $osnova = rtrim(patConfig()['site_url'], '/');
    $potrditev = $osnova . '/potrditev';
    $domov = $osnova . '/domov';
    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $imeHtml = $esc($ime);
    $potrditevHtml = $esc($potrditev);
    $domovHtml = $esc($domov);
    $leto = date('Y');
    $besedilo = "Živjo, {$ime}!\n\nHvala, ker si se pridružil PAT.si. Za dokončanje registracije vnesi spodnjo potrditveno kodo:\n\n{$koda}\n\nKoda velja 10 minut. Nova koda razveljavi prejšnjo.\n\nPotrdi e-pošto: {$potrditev}\n\nČe registracije nisi zahteval, lahko to sporočilo prezreš. Kode ne deli z drugimi.\n\nSe vidimo na PAT.si!\nEkipa PAT.si\n\nObišči spletno stran: {$domov}\n";
    $html = <<<HTML
<!doctype html>
<html lang="sl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="supported-color-schemes" content="dark">
  <title>Potrdi svoj e-poštni naslov – PAT.si</title>
  <style>
    body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
    table{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt}img{border:0;outline:none;text-decoration:none}
    .email-okvir{width:100%!important;max-width:640px!important;margin:0 auto!important;table-layout:fixed}
    .vsebina p,.noga p{overflow-wrap:anywhere;word-break:break-word}
    @media only screen and (max-width:480px){.zunaj{padding:12px 6px!important}.vsebina{padding:26px 20px!important}.glava{padding:28px 24px!important}.koda{font-size:34px!important;letter-spacing:8px!important}.gumb{display:block!important;padding:16px 18px!important}.noga{padding:24px 20px!important}}
  </style>
</head>
<body style="margin:0;padding:0;background-color:#17181a;color:#e8eaed;font-family:Arial,Helvetica,sans-serif;">
  <div style="display:none;font-size:1px;color:#17181a;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">Potrdi svoj e-poštni naslov in dokončaj registracijo na PAT.si. Koda velja 10 minut.</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#17181a" style="width:100%;background-color:#17181a;">
    <tr><td class="zunaj" align="center" style="padding:32px 12px;text-align:center;">
      <!--[if mso]><table role="presentation" width="640" align="center" cellpadding="0" cellspacing="0"><tr><td><![endif]-->
      <table role="presentation" class="email-okvir" align="center" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;margin:0 auto;table-layout:fixed;text-align:left;background-color:#202124;" bgcolor="#202124">
        <tr><td class="glava" align="center" bgcolor="#dce8f5" style="padding:34px 32px;background-color:#dce8f5;">
          <a href="{$domovHtml}" style="text-decoration:none;"><img src="cid:pat-logo" width="180" height="90" alt="PAT.si" style="display:block;width:180px;height:auto;max-width:100%;color:#0b2a55;font-size:32px;font-weight:bold;"></a>
        </td></tr>
        <tr><td class="vsebina" style="padding:38px 36px 34px;color:#e8eaed;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:26px;">
          <p style="margin:0 0 24px;">Živjo, {$imeHtml}!</p>
          <p style="margin:0 0 24px;">Hvala, ker si se pridružil PAT.si. Za dokončanje registracije potrdi svoj e-poštni naslov s spodnjo kodo.</p>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr><td align="center" bgcolor="#292b30" style="padding:24px 12px;background-color:#292b30;border:1px solid #41454d;border-radius:4px;">
              <p style="margin:0 0 10px;color:#bdc1c6;font-size:12px;line-height:18px;letter-spacing:2px;">TVOJA POTRDITVENA KODA</p>
              <p class="koda" style="margin:0;color:#67b5ff;font-family:Arial,Helvetica,sans-serif;font-size:40px;font-weight:bold;line-height:52px;letter-spacing:10px;white-space:nowrap;">{$koda}</p>
            </td></tr>
          </table>
          <p style="margin:0 0 28px;color:#bdc1c6;font-size:14px;line-height:22px;">Koda velja <strong style="color:#e8eaed;">10 minut</strong>. Nova koda razveljavi prejšnjo.</p>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr><td align="center" style="padding:0 0 30px;">
              <table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" bgcolor="#1976d2" style="background-color:#1976d2;border-radius:4px;mso-padding-alt:16px 40px;"><a class="gumb" href="{$potrditevHtml}" style="display:inline-block;box-sizing:border-box;max-width:100%;padding:16px 40px;border:1px solid #1976d2;border-radius:4px;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:17px;line-height:22px;font-weight:bold;text-align:center;text-decoration:none;">Potrdi e-pošto</a></td></tr></table>
            </td></tr>
          </table>
          <p style="margin:0 0 12px;">Če gumb ne deluje, v brskalnik kopiraj spodnjo povezavo:</p>
          <p style="margin:0 0 28px;word-break:break-all;"><a href="{$potrditevHtml}" style="color:#67b5ff;text-decoration:none;">{$potrditevHtml}</a></p>
          <p style="margin:0 0 24px;color:#bdc1c6;font-size:14px;line-height:22px;">Če registracije nisi zahteval, lahko to sporočilo prezreš. Kode ne deli z drugimi.</p>
          <p style="margin:0;">Se vidimo na PAT.si!<br>Ekipa PAT.si</p>
        </td></tr>
        <tr><td class="noga" align="center" style="padding:28px 36px;border-top:1px solid #303238;color:#bdc1c6;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:20px;">
          <table role="presentation" cellpadding="0" cellspacing="0"><tr><td align="center" style="border:1px solid #67b5ff;border-radius:4px;mso-padding-alt:10px 24px;"><a href="{$domovHtml}" style="display:inline-block;padding:10px 24px;color:#67b5ff;font-size:14px;font-weight:bold;text-decoration:none;">Obišči PAT.si</a></td></tr></table>
          <p style="margin:20px 0 0;">Prodaja in izmenjava šolskih potrebščin.<br>© {$leto} PAT.si</p>
        </td></tr>
      </table>
      <!--[if mso]></td></tr></table><![endif]-->
    </td></tr>
  </table>
</body>
</html>
HTML;
    return ['besedilo' => $besedilo, 'html' => $html];
}
