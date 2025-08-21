<?php
/**
 * Filmuntertitel Bullshit-Bingo – PDF-Generator (TCPDF)
 * Features:
 *  - Raster wählbar (4x4 oder 5x5)
 *  - Footer mit Regeln auf JEDER Seite, ganz unten
 *  - PDF öffnet in neuem Tab (target="_blank" im Formular)
 *  - Seed für reproduzierbare Karten
 *  - Deutsche Vorlagen + eigene Begriffe (kommagetrennt)
 *
 * Voraussetzung: TCPDF (Composer: composer require tecnickcom/tcpdf)
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ---- TCPDF laden (ggf. Pfad anpassen) -------------------------
$tcpdfPathCandidates = [
    __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php', // Composer
    __DIR__ . '/tcpdf/tcpdf.php',
    __DIR__ . '/tcpdf_min/tcpdf.php',
];
$tcpdfLoaded = false;
foreach ($tcpdfPathCandidates as $p) {
    if (is_file($p)) { require_once $p; $tcpdfLoaded = true; break; }
}
if (!$tcpdfLoaded) {
    die("TCPDF nicht gefunden. Bitte Pfad in \$tcpdfPathCandidates anpassen.");
}

// ---- Deutsche Vorlagen ----------------------------------------
$presets = [
    // 16+ Buzzwords – verwenden wir je nach Raster
    'filmuntertitel_de' => [
        "Blutlinien","Auferstehung","Requiem","Genesis",
        "Wiedergeburt","Der Anfang","Vergeltung","Rache",
        "Erlösung","Das letzte Kapitel","Revolution","Evolution",
        "Auslöschung","Apokalypse","Nachspiel","Vermächtnis",
        "Rückkehr","Ursprung","Erwachen","Endzeit","Erbe","Fluch","Erinnerungen","Dämmerung"
    ],
    'rollen_typen' => [
        "Das Final Girl","Der Cop","Der Wissenschaftler","Der Killer",
        "Der Mentor","Der Sidekick","Die Reporterin","Die Nachbarin",
        "Der Hausmeister","Der Sheriff","Der Barkeeper","Der Pfarrer",
        "Der Nerd","Der Draufgänger","Der Milliardär","Die Verschwörerin",
        "Der Hacker","Die Ärztin","Der Soldat","Die Pilotin","Der Nachfolger","Die Erbin","Der Fremde","Der Nachbar"
    ],
    'floskeln_de' => [
        "Wir trennen uns!","Hier stimmt etwas nicht.","Haben Sie das gehört?","Lauf!",
        "Du bleibst hier.","Was war das?","Das kann nicht sein.","Wir sind nicht allein.",
        "Nicht da lang!","Hinter dir!","Ich habe ein schlechtes Gefühl.","Was soll schon schiefgehen?",
        "Wir kommen in Frieden.","Ich bin gleich wieder da.","Es ist zu ruhig.","Kein Empfang.",
        "Wir müssen sofort weg.","Ich kenne eine Abkürzung.","Vertrau mir.","Das wirst du bereuen.",
        "Mach das Licht an!","Bleib leise.","Das ist unmöglich.","Das ist nur der Wind."
    ],
];

// ---- Helfer ----------------------------------------------------
function normalizeList(string $csv, array $fallback, int $needed): array {
    $list = [];
    if (trim($csv) !== '') {
        $raw = array_map('trim', explode(',', $csv));
        $list = array_values(array_unique(array_filter($raw, fn($v)=>$v!=='')));
    }
    if (count($list) < $needed) {
        // Mit Fallback auffüllen (ohne Duplikate)
        $rest = array_values(array_diff($fallback, $list));
        while (count($list) < $needed) {
            if (empty($rest)) $rest = $fallback; // zur Not wiederverwenden
            $take = array_splice($rest, 0, min($needed - count($list), count($rest)));
            $list = array_merge($list, $take);
        }
    }
    return $list;
}

// TCPDF mit Footer
class BingoPDF extends TCPDF {
    public string $ruleText = '';
    public function Footer() {
        $this->SetY(-18); // Abstand vom Seitenende
        $this->SetFont('dejavusans', '', 9);

        // MultiCell erlaubt Zeilenumbrüche
        $this->MultiCell(
            0, 10,
            $this->ruleText,
            0, 'C', false, 1,
            '', '', true, 0, false, true, 10, 'M'
        );
    }
}

// ---- PDF-Erzeugung --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_pdf'])) {
    $count    = max(1, min(60, (int)($_POST['count'] ?? 6)));     // 1..60 Karten
    $grid     = (int)($_POST['grid'] ?? 4);                       // 4 oder 5
    if (!in_array($grid, [4,5], true)) $grid = 4;

    $needed   = $grid * $grid;
    $preset   = $_POST['preset'] ?? 'filmuntertitel_de';
    $custom   = trim($_POST['custom'] ?? '');
    $seed     = trim($_POST['seed'] ?? '');

    $fallback = $presets[$preset] ?? $presets['filmuntertitel_de'];
    $wordList = normalizeList($custom, $fallback, max($needed, count($fallback)));

    if ($seed !== '') {
        // Reproduzierbare Mischungen (gleicher Seed => gleiche Kartensätze)
        mt_srand(crc32($seed));
    }

    // PDF aufsetzen
    $pdf = new BingoPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Bullshit-Bingo');
    $pdf->SetAuthor('Bullshit-Bingo');
    $pdf->SetTitle('Bullshit-Bingo');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(12, 16, 12);
    $pdf->SetAutoPageBreak(true, 20); // genug Platz für Footer
    $pdf->SetFont('dejavusans', '', 12);
    $pdf->ruleText = "Regeln: Feld abhaken, wenn der Begriff im Filmtitel/Film vorkommt.\n"
               . "Wer eine Reihe (waagrecht, senkrecht oder diagonal) voll hat, ruft: Straight-to-DVD!";

    // Layout
    $gridSizeMM = 180;               // Gesamtgröße des Rasters
    $cell = $gridSizeMM / $grid;     // Zellgröße
    for ($k = 1; $k <= $count; $k++) {
        // Board erzeugen
        $pool = $wordList;
        shuffle($pool);
        $board = array_slice($pool, 0, $needed);

        $pdf->AddPage();
        // Titel
        $pdf->SetFont('', 'B', 18);
        $pdf->Cell(0, 10, "Bullshit-Bingo – Karte $k ({$grid}×{$grid})", 0, 1, 'C');
        $pdf->Ln(2);

        // Infozeile klein
        $pdf->SetFont('', '', 9);
        $info = 'Vorlage: ' . ($preset === 'filmuntertitel_de' ? 'Filmuntertitel (DE)'
                : ($preset === 'rollen_typen' ? 'Rollen/Typen'
                : ($preset === 'floskeln_de' ? 'Film-Floskeln/Sätze' : 'Custom')));
        if ($seed !== '') $info .= ' • Seed: ' . $seed;
        $pdf->Cell(0, 5, $info, 0, 1, 'C');

        // Raster mittig
        $pageW = $pdf->getPageWidth();
        $x0 = ($pageW - $gridSizeMM) / 2;
        $y0 = 38;

        $pdf->SetFont('', '', ($grid === 5 ? 11 : 12)); // etwas kleiner bei 5x5

        $i = 0;
        for ($r = 0; $r < $grid; $r++) {
            for ($c = 0; $c < $grid; $c++, $i++) {
                $txt = $board[$i] ?? '';
                $x   = $x0 + $c * $cell;
                $y   = $y0 + $r * $cell;

                $pdf->SetXY($x, $y);
                $pdf->MultiCell(
                    $cell, $cell, $txt,
                    1, 'C', false, 0, '', '', true, 0, false, true, $cell, 'M'
                );
            }
        }
    }

    // Ausgabe im Browser (Formular öffnet neuen Tab)
    $pdf->Output('bullshit_bingo.pdf', 'I');
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bullshit-Bingo – PDF-Generator</title>
<style>
  :root{--ink:#e5e7eb;--bg:#0f172a;--card:#0b1220cc;--accent:#22d3ee;--muted:#9ca3af}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;background:linear-gradient(120deg,#0f172a,#111827);color:var(--ink);min-height:100vh;display:grid;place-items:center;padding:24px}
  .card{width:min(980px,100%);background:var(--card);border:1px solid #1f2937;border-radius:16px;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,.35)}
  h1{margin:.2rem 0 1rem;font-weight:800;letter-spacing:.2px}
  label{display:block;margin:.75rem 0 .35rem;color:#cbd5e1;font-size:.95rem}
  input,select,textarea{width:100%;background:#0b1325;border:1px solid #243042;color:#e5e7eb;border-radius:12px;padding:12px}
  textarea{min-height:120px;resize:vertical}
  .row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
  .row2{display:grid;grid-template-columns:1fr 2fr;gap:16px}
  .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px;align-items:center}
  button{background:var(--accent);color:#001016;border:0;border-radius:12px;padding:12px 16px;font-weight:800;cursor:pointer}
  .muted{color:var(--muted);font-size:.9rem}
  .hint{font-size:.85rem;color:#a3a3a3;margin-top:.35rem}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
</style>
<script>
  const PRESETS = {
    filmuntertitel_de: [
      "Blutlinien","Auferstehung","Requiem","Genesis",
      "Wiedergeburt","Der Anfang","Vergeltung","Rache",
      "Erlösung","Das letzte Kapitel","Revolution","Evolution",
      "Auslöschung","Apokalypse","Nachspiel","Vermächtnis",
      "Rückkehr","Ursprung","Erwachen","Endzeit","Erbe","Fluch","Erinnerungen","Dämmerung"
    ],
    rollen_typen: [
      "Das Final Girl","Der Cop","Der Wissenschaftler","Der Killer",
      "Der Mentor","Der Sidekick","Die Reporterin","Die Nachbarin",
      "Der Hausmeister","Der Sheriff","Der Barkeeper","Der Pfarrer",
      "Der Nerd","Der Draufgänger","Der Milliardär","Die Verschwörerin",
      "Der Hacker","Die Ärztin","Der Soldat","Die Pilotin","Der Nachfolger","Die Erbin","Der Fremde","Der Nachbar"
    ],
    floskeln_de: [
      "Wir trennen uns!","Hier stimmt etwas nicht.","Haben Sie das gehört?","Lauf!",
      "Du bleibst hier.","Was war das?","Das kann nicht sein.","Wir sind nicht allein.",
      "Nicht da lang!","Hinter dir!","Ich habe ein schlechtes Gefühl.","Was soll schon schiefgehen?",
      "Wir kommen in Frieden.","Ich bin gleich wieder da.","Es ist zu ruhig.","Kein Empfang.",
      "Wir müssen sofort weg.","Ich kenne eine Abkürzung.","Vertrau mir.","Das wirst du bereuen.",
      "Mach das Licht an!","Bleib leise.","Das ist unmöglich.","Das ist nur der Wind."
    ]
  };

  function fillPreset() {
    const sel = document.getElementById('preset');
    const area = document.getElementById('custom');
    const key = sel.value;
    if (PRESETS[key]) {
      area.value = PRESETS[key].join(', ');
    }
  }
</script>
</head>
<body>
  <!-- target="_blank" -> PDF im neuen Tab -->
  <form class="card" method="post" target="_blank">
    <h1>🎬 Bullshit-Bingo – PDF-Generator</h1>

    <div class="row">
      <div>
        <label for="count">Anzahl Karten (1–60)</label>
        <input type="number" id="count" name="count" min="1" max="60" value="6"/>
      </div>
      <div>
        <label for="grid">Raster</label>
        <select id="grid" name="grid">
          <option value="4">4 × 4</option>
          <option value="5">5 × 5</option>
        </select>
        <div class="hint">5×5 setzt kleinere Schrift in den Zellen.</div>
      </div>
      <div>
        <label for="seed">Seed (optional)</label>
        <input type="text" id="seed" name="seed" placeholder="z. B. movie-night-01" />
        <div class="hint">Gleicher Seed + gleiche Begriffe ⇒ gleiche Kartensätze (reproduzierbar).</div>
      </div>
    </div>

    <div class="row2">
      <div>
        <label for="preset">Vorlage</label>
        <select id="preset" name="preset" onchange="fillPreset()">
          <option value="filmuntertitel_de">Filmuntertitel (DE)</option>
          <option value="rollen_typen">Rollen/Typen</option>
          <option value="floskeln_de">Film-Floskeln/Sätze</option>
          <option value="__custom">Nur eigene Begriffe</option>
        </select>
        <div class="hint">Mit Klick fülle ich die Liste rechts vor (du kannst sie danach anpassen).</div>
      </div>
      <div>
        <label for="custom">Eigene/angepasste Begriffe (kommagetrennt)</label>
        <textarea id="custom" name="custom" placeholder="Begriffe, durch Komma getrennt …"></textarea>
        <div class="hint">Wenn leer, wird nur die gewählte Vorlage genutzt.</div>
      </div>
    </div>

    <div class="actions">
      <button type="submit" name="make_pdf" value="1">PDF erzeugen</button>
      <span class="muted">Tipp: Mit Seed kannst du später denselben Kartensatz erneut generieren.</span>
    </div>
  </form>

  <script>fillPreset();</script>
</body>
</html>
