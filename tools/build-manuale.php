<?php

/**
 * Genera il manuale d'uso Fairy Bus.
 *
 * Legge manuale.md (root del progetto), converte in HTML, produce:
 *  - www/public/manuale.pdf
 *  - www/public/assets/fairy-bus/manuale-pages.json  (route => pagina PDF)
 *
 * Convenzione: le intestazioni `##`/`###` iniziano una nuova pagina e possono
 * portare un marker `<!-- route: admin/xxx -->` che mappa la pagina dell'app
 * alla pagina del PDF (`manuale.pdf#page=N`). `route: *` = pagina iniziale.
 *
 * Uso: php tools/build-manuale.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$mdFile = $root . '/manuale.md';
$pdfOut = $root . '/www/public/manuale.pdf';
$jsonOut = $root . '/www/public/assets/fairy-bus/manuale-pages.json';
require $root . '/www/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!is_file($mdFile)) {
    fwrite(STDERR, "manuale.md non trovato in {$root}\n");
    exit(1);
}

/* ---------------- Markdown minimale -> HTML ---------------- */

function inlineMd(string $s): string
{
    $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $s);
    $s = preg_replace('/`(.+?)`/', '<code>$1</code>', $s);
    return $s;
}

/** Risolve il src di un'immagine md in path filesystem assoluto per Dompdf. */
function imgSrc(string $src): string
{
    $src = trim($src);
    if (preg_match('#^https?://#', $src)) {
        return $src;
    }
    // Le immagini del manuale vivono in public/assets/fairy-bus/manuale/img
    $root = dirname(__DIR__);
    $candidates = [
        $root . '/www/public' . ($src[0] === '/' ? $src : '/' . $src),
        $root . '/' . ltrim($src, '/'),
        $root . '/www/public/assets/fairy-bus/manuale/' . ltrim($src, '/'),
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return $src;
}

/** Converte un blocco di linee markdown in HTML (h4, ul, ol, p, code). */
function blockMd(array $lines): string
{
    $html = '';
    $para = [];
    $list = null; // 'ul' | 'ol'

    $flush = function () use (&$html, &$para, &$list) {
        if ($para !== []) {
            $html .= '<p>' . inlineMd(implode(' ', $para)) . '</p>';
            $para = [];
        }
        if ($list !== null) {
            $html .= "</{$list}>";
            $list = null;
        }
    };

    foreach ($lines as $line) {
        $t = rtrim($line);
        if (trim($t) === '') {
            $flush();
            continue;
        }
        if (preg_match('/^!\[(.*)\]\((.+)\)$/', trim($t), $m)) {
            $flush();
            $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $src = htmlspecialchars(imgSrc($m[2]), ENT_QUOTES, 'UTF-8');
            $html .= '<figure class="shot"><img src="' . $src . '">' . ($alt !== '' ? '<figcaption>' . $alt . '</figcaption>' : '') . '</figure>';
            continue;
        }
        if (preg_match('/^####\s+(.+)$/', $t, $m)) {
            $flush();
            $html .= '<h4>' . inlineMd($m[1]) . '</h4>';
            continue;
        }
        if (preg_match('/^-\s+(.+)$/', ltrim($t), $m)) {
            if ($list !== 'ul') {
                $flush();
                $list = 'ul';
                $html .= '<ul>';
            }
            $para = [];
            $html .= '<li>' . inlineMd($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\d+\.\s+(.+)$/', ltrim($t), $m)) {
            if ($list !== 'ol') {
                $flush();
                $list = 'ol';
                $html .= '<ol>';
            }
            $para = [];
            $html .= '<li>' . inlineMd($m[1]) . '</li>';
            continue;
        }
        if ($list !== null) {
            $flush();
        }
        $para[] = trim($t);
    }
    $flush();
    return $html;
}

/* ---------------- Parsing in sezioni (## e ### = nuova pagina) ---------------- */

$blocks = [];
$current = null; // ['level'=>int,'title'=>string,'route'=>?string,'lines'=>[]]
$docTitle = 'Manuale';

foreach (file($mdFile, FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^#\s+(.+)$/', $line, $m)) {
        $docTitle = trim($m[1]);
        continue;
    }
    if (preg_match('/^(##|###)\s+(.+)$/', $line, $m)) {
        if ($current !== null) {
            $blocks[] = $current;
        }
        $title = $m[2];
        $route = null;
        if (preg_match('/<!--\s*route:\s*([^\s>]+)\s*-->/', $title, $rm)) {
            $route = trim($rm[1]);
            $title = trim(preg_replace('/<!--.*?-->/', '', $title));
        }
        $current = ['level' => strlen($m[1]), 'title' => $title, 'route' => $route, 'lines' => []];
        continue;
    }
    if ($current === null) {
        continue; // testo prima del primo ##: ignorato (copertina dedicata)
    }
    if ($current['route'] === null && preg_match('/<!--\s*route:\s*([^\s>]+)\s*-->/', $line, $rm)) {
        $current['route'] = trim($rm[1]);
        continue;
    }
    $current['lines'][] = $line;
}
if ($current !== null) {
    $blocks[] = $current;
}

/* ---------------- HTML ---------------- */

$css = <<<'CSS'
<style>
@page { margin: 18mm 16mm 16mm 16mm; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.5; }
.cover { text-align: center; padding-top: 38%; }
.cover h1 { font-size: 26pt; color: #0f172a; margin: 0 0 6mm 0; }
.cover .sub { font-size: 12pt; color: #64748b; }
.cover .date { margin-top: 30mm; font-size: 9pt; color: #94a3b8; }
.toc h2 { font-size: 15pt; color: #0f172a; border-bottom: 2px solid #3b82f6; padding-bottom: 2mm; }
.toc ul { list-style: none; padding: 0; margin: 4mm 0 0 0; }
.toc li { padding: 1.1mm 0; border-bottom: 0.2pt dotted #cbd5e1; }
.toc .lv3 { padding-left: 7mm; font-size: 8.7pt; }
.toc .pg { float: right; color: #64748b; }
h2 { font-size: 15pt; color: #0f172a; border-bottom: 2px solid #3b82f6; padding-bottom: 2mm; margin: 0 0 4mm 0; }
h3 { font-size: 12pt; color: #1d4ed8; margin: 0 0 3mm 0; }
h4 { font-size: 10pt; color: #0f172a; margin: 4mm 0 1.5mm 0; }
p { margin: 0 0 2.4mm 0; }
ul, ol { margin: 0 0 2.4mm 0; padding-left: 5.5mm; }
li { margin-bottom: 1.1mm; }
code { font-family: 'DejaVu Sans Mono', monospace; font-size: 8.5pt; background: #f1f5f9; padding: 0 1mm; border-radius: 1mm; }
figure.shot { margin: 3mm 0 4mm 0; text-align: center; page-break-inside: avoid; }
figure.shot img { max-width: 165mm; max-height: 100mm; border: 0.3pt solid #cbd5e1; border-radius: 2mm; }
figure.shot figcaption { font-size: 8pt; color: #64748b; margin-top: 1.5mm; font-style: italic; }
</style>
CSS;

function renderPages(string $inner): Dompdf
{
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', dirname(__DIR__) . '/www/public');
    $d = new Dompdf($options);
    $d->loadHtml('<!DOCTYPE html><html><head><meta charset="utf-8">' . $GLOBALS['css'] . '</head><body>' . $inner . '</body></html>', 'UTF-8');
    $d->setPaper('a4', 'portrait');
    $d->render();
    return $d;
}

/* Pass 1: numero pagine per sezione (ogni blocco inizia a pagina nuova) */
$blockPages = [];
foreach ($blocks as $i => $b) {
    $tag = $b['level'] === 2 ? 'h2' : 'h3';
    $html = "<{$tag}>" . htmlspecialchars($b['title']) . "</{$tag}>" . blockMd($b['lines']);
    $blocks[$i]['html'] = $html;
    $blockPages[$i] = renderPages($html)->getCanvas()->get_page_count();
    echo "  sezione '{$b['title']}': {$blockPages[$i]} pagine\n";
}

/* Copertina (1 pagina) + indice (con pagine fittizie per contare) */
$cover = '<div class="cover"><h1>' . htmlspecialchars($docTitle) . '</h1>'
    . '<div class="sub">Gestione officina e magazzino</div>'
    . '<div class="date">Aggiornato il ' . date('d/m/Y') . '</div></div>';
$coverPages = renderPages($cover)->getCanvas()->get_page_count();

$tocItems = '';
foreach ($blocks as $i => $b) {
    $cls = $b['level'] === 3 ? 'lv3' : '';
    $tocItems .= "<li class=\"{$cls}\">" . htmlspecialchars($b['title']) . '<span class="pg">0</span></li>';
}
$toc = '<div class="toc"><h2>Indice</h2><ul>' . $tocItems . '</ul></div>';
$tocPages = renderPages($toc)->getCanvas()->get_page_count();

/* Pass 2: mappa route -> pagina assoluta */
$offset = $coverPages + $tocPages;
$page = $offset;
$map = [];
foreach ($blocks as $i => $b) {
    $page += $blockPages[$i];
    $b['page'] = $page - $blockPages[$i] + 1;
    if ($b['route'] !== null) {
        $map[$b['route']] = $b['page'];
    }
}

/* Indice definitivo con i numeri reali */
$tocItems = '';
foreach ($blocks as $i => $b) {
    $cls = $b['level'] === 3 ? 'lv3' : '';
    $pg = $offset + array_sum(array_slice($blockPages, 0, $i)) + 1;
    $tocItems .= "<li class=\"{$cls}\">" . htmlspecialchars($b['title']) . "<span class=\"pg\">{$pg}</span></li>";
}
$toc = '<div class="toc"><h2>Indice</h2><ul>' . $tocItems . '</ul></div>';

/* Render finale */
$inner = $cover . '<div style="page-break-before:always">' . $toc . '</div>';
foreach ($blocks as $b) {
    $inner .= '<div style="page-break-before:always">' . $b['html'] . '</div>';
}
$dompdf = renderPages($inner);

// Numeri di pagina in basso a destra
$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
$dompdf->getCanvas()->page_text(
    $canvas->get_width() - 80,
    $canvas->get_height() - 28,
    '{PAGE_NUM} / {PAGE_COUNT}',
    $font,
    7.5,
    [0.44, 0.44, 0.47]
);

if (!is_dir(dirname($jsonOut))) {
    mkdir(dirname($jsonOut), 0775, true);
}
file_put_contents($pdfOut, $dompdf->output());
file_put_contents($jsonOut, json_encode([
    'generated' => date('c'),
    'pages' => $dompdf->getCanvas()->get_page_count(),
    'routes' => $map,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "OK: {$pdfOut} ({$dompdf->getCanvas()->get_page_count()} pagine)\n";
echo "OK: {$jsonOut}\n";
foreach ($map as $r => $p) {
    echo "    {$r} -> pagina {$p}\n";
}
