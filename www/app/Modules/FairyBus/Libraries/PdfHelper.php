<?php

/**
 * Copyright since 2026 Massimiliano Palermo
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to maxx.palermo@gmail.com so we can send you a copy immediately.
 *
 * @author    Massimiliano Palermo <maxx.palermo@gmail.com>
 * @copyright Since 2026 Massimiliano Palermo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace FairyBus\Libraries;

use App\Libraries\Twig;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Helper centralizzato per la generazione dei PDF.
 * Renderizza un template Twig e lo converte in PDF tramite Dompdf.
 */
class PdfHelper
{
    /**
     * Genera un PDF da un template Twig e restituisce il contenuto binario.
     */
    public static function generate(
        string $template,
        array $data = [],
        string $paper = 'a4',
        string $orientation = 'portrait',
    ): string {
        $html = Twig::render($template, $data);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', FCPATH);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        self::addPageNumbers($dompdf);

        return (string) $dompdf->output();
    }

    /**
     * Aggiunge "Pagina X di Y" in basso a destra su ogni pagina.
     * counter(pages) via CSS non è affidabile in Dompdf: si usa il canvas.
     */
    private static function addPageNumbers(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        $size = 7.0;
        $text = 'Pagina {PAGE_NUM} di {PAGE_COUNT}';

        $textWidth = $metrics->getTextWidth($text, $font, $size);
        $x = $canvas->get_width() - 45.35 - $textWidth; // margine destro 16mm
        $y = $canvas->get_height() - 28.0; // ~10mm dal bordo inferiore, allineato al footer

        $canvas->page_text($x, $y, $text, $font, $size, [0.44, 0.44, 0.47]);
    }

    /**
     * Nome file sicuro per il download/stream.
     */
    public static function filename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $name) ?: 'documento';

        return str_ends_with($name, '.pdf') ? $name : "{$name}.pdf";
    }
}
