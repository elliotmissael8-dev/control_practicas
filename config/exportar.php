<?php
declare(strict_types=1);

// Exportadores sin librerías externas: .xlsx y .docx (ZIP + XML con ZipArchive) y .pdf (escrito a mano).
// Reciben el arreglo $rep que arma reportes.php:
// ['titulo','subtitulo','cols','alumnos'=>[['matricula','nombre','datos'=>[],'filas'=>[],'resumen'=>[]]]]

function exp_xml(string $s): string {
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function exp_zip(array $archivos): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Falta la extensión zip de PHP. En XAMPP abre php.ini, activa "extension=zip" y reinicia Apache.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'rep');
    $zip = new ZipArchive();
    if ($tmp === false || $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('No se pudo crear el archivo temporal del reporte.');
    }
    foreach ($archivos as $ruta => $contenido) {
        $zip->addFromString($ruta, $contenido);
    }
    $zip->close();
    $bytes = (string)file_get_contents($tmp);
    unlink($tmp);
    return $bytes;
}

/* ===================== EXCEL (.xlsx) ===================== */

function exp_hoja_xml(array $filas, array $anchos): string {
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($anchos as $i => $w) {
        $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $x .= '</cols><sheetData>';
    foreach ($filas as $r => $fila) {
        $x .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($fila) as $c => $v) {
            $x .= '<c r="' . chr(65 + $c) . ($r + 1) . '" s="' . ($r === 0 ? 1 : 2) . '" t="inlineStr"><is><t xml:space="preserve">' . exp_xml((string)$v) . '</t></is></c>';
        }
        $x .= '</row>';
    }
    return $x . '</sheetData></worksheet>';
}

function exp_xlsx(array $rep): string {
    $detalle = [['Matrícula', 'Código', 'Alumno', 'Programa', 'Fecha', 'Entrada', 'Salida', 'Horas', 'Actividades', 'Horas acumuladas']];
    $resumen = [];
    foreach ($rep['alumnos'] as $a) {
        foreach ($a['filas'] as $f) {
            $detalle[] = [$a['matricula'], $a['datos']['Código'] ?? '', $a['nombre'], $a['datos']['Programa'] ?? '', $f[0], $f[1], $f[2], $f[3], $f[4], $f[5]];
        }
        if (!$resumen) {
            $resumen[] = array_merge(['Matrícula', 'Alumno'], array_keys($a['datos']), array_keys($a['resumen']));
        }
        $resumen[] = array_merge([$a['matricula'], $a['nombre']], array_values($a['datos']), array_values($a['resumen']));
    }
    $anchosResumen = array_fill(0, count($resumen[0] ?? []), 18);

    $decl = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $estilos = $decl . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF142B4A"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>'
        . '</styleSheet>';

    return exp_zip([
        '[Content_Types].xml' => $decl . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => $decl . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $decl . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Reporte" sheetId="1" r:id="rId1"/><sheet name="Resumen" sheetId="2" r:id="rId2"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $decl . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $estilos,
        'xl/worksheets/sheet1.xml' => exp_hoja_xml($detalle, [14, 16, 30, 24, 12, 9, 9, 11, 70, 16]),
        'xl/worksheets/sheet2.xml' => exp_hoja_xml($resumen ?: [['Sin datos']], $anchosResumen ?: [18]),
    ]);
}

/* ===================== WORD (.docx) ===================== */

function exp_run(string $t, int $sz, bool $b = false, string $color = ''): string {
    $rpr = '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>' . ($b ? '<w:b/>' : '')
        . ($color !== '' ? '<w:color w:val="' . $color . '"/>' : '') . '<w:sz w:val="' . $sz . '"/></w:rPr>';
    $x = '';
    foreach (preg_split('/\r\n|\r|\n/', $t) ?: [''] as $i => $linea) {
        $x .= '<w:r>' . $rpr . ($i > 0 ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . exp_xml($linea) . '</w:t></w:r>';
    }
    return $x;
}

function exp_par(string $runs, bool $salto = false, int $despues = 60, int $antes = 0): string {
    return '<w:p><w:pPr>' . ($salto ? '<w:pageBreakBefore/>' : '') . '<w:spacing w:before="' . $antes . '" w:after="' . $despues . '"/></w:pPr>' . $runs . '</w:p>';
}

function exp_celda(string $t, int $w, bool $enc = false): string {
    $shd = $enc ? '<w:shd w:val="clear" w:color="auto" w:fill="142B4A"/>' : '';
    return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . $shd . '</w:tcPr><w:p><w:pPr><w:spacing w:before="20" w:after="20"/></w:pPr>'
        . exp_run($t, 18, $enc, $enc ? 'FFFFFF' : '') . '</w:p></w:tc>';
}

function exp_pares(array $pares): string {
    $x = '';
    foreach ($pares as $k => $v) {
        $x .= exp_run($k . ': ', 18, true) . exp_run($v . '     ', 18);
    }
    return $x;
}

function exp_docx(array $rep): string {
    $anchos = [1300, 1000, 1000, 1300, 7600, 2200]; // suma 14400 (carta horizontal, márgenes de 0.5")
    $cuerpo = exp_par(exp_run($rep['titulo'], 32, true, '142B4A'), false, 40)
        . exp_par(exp_run($rep['subtitulo'], 20), false, 160);

    foreach ($rep['alumnos'] as $i => $a) {
        $cuerpo .= exp_par(exp_run($a['nombre'] . ' — ' . $a['matricula'], 26, true, '142B4A'), $i > 0, 60);
        $cuerpo .= exp_par(exp_pares($a['datos']), false, 120);
        if ($a['filas']) {
            $t = '<w:tbl><w:tblPr><w:tblW w:w="14400" w:type="dxa"/><w:tblBorders>';
            foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $l) {
                $t .= '<w:' . $l . ' w:val="single" w:sz="4" w:space="0" w:color="BFC8D6"/>';
            }
            $t .= '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>';
            foreach ($anchos as $w) {
                $t .= '<w:gridCol w:w="' . $w . '"/>';
            }
            $t .= '</w:tblGrid><w:tr><w:trPr><w:tblHeader/></w:trPr>';
            foreach ($rep['cols'] as $k => $c) {
                $t .= exp_celda($c, $anchos[$k], true);
            }
            $t .= '</w:tr>';
            foreach ($a['filas'] as $f) {
                $t .= '<w:tr>';
                foreach ($f as $k => $v) {
                    $t .= exp_celda((string)$v, $anchos[$k]);
                }
                $t .= '</w:tr>';
            }
            $cuerpo .= $t . '</w:tbl>';
        } else {
            $cuerpo .= exp_par(exp_run('Sin jornadas registradas en este periodo.', 18));
        }
        $cuerpo .= exp_par(exp_pares($a['resumen']), false, 0, 120);
    }

    $decl = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $doc = $decl . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $cuerpo
        . '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr></w:body></w:document>';

    return exp_zip([
        '[Content_Types].xml' => $decl . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        '_rels/.rels' => $decl . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/document.xml' => $doc,
    ]);
}

/* ===================== PDF ===================== */

// Generador mínimo: carta horizontal, Helvetica (WinAnsi), texto con ajuste de línea y tablas que cruzan páginas.
final class ExpPdf {
    private const W = 792;
    private const H = 612;
    private const M = 36;
    // Anchos de Helvetica (1/1000 em) para los caracteres ASCII 32..126.
    private const ANCHOS = [
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,
        278,278,584,584,584,556,1015,
        667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,
        278,278,278,469,556,333,
        556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,
        334,260,334,584,
    ];
    private array $paginas = [];
    private string $c = '';
    private float $y;

    public function __construct() {
        $this->y = self::H - self::M;
    }

    private static function cp(string $s): string {
        $s = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $s);
        $r = function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : iconv('UTF-8', 'CP1252//IGNORE', $s);
        return is_string($r) ? $r : $s;
    }

    private static function ancho(string $s, float $size, bool $b): float {
        $t = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $o = ord($s[$i]);
            $t += ($o >= 32 && $o <= 126) ? self::ANCHOS[$o - 32] : 556;
        }
        return $t * $size / 1000 * ($b ? 1.07 : 1.0);
    }

    private static function esc(string $s): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    private function t(float $x, float $y, string $s, float $size, bool $b = false, string $color = '0 g'): void {
        $this->c .= sprintf("BT %s /F%d %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $color, $b ? 2 : 1, $size, $x, $y, self::esc($s));
    }

    // $s ya viene en CP1252. Devuelve las líneas ajustadas al ancho $max.
    private function lineas(string $s, float $max, float $size, bool $b = false): array {
        $out = [];
        foreach (explode("\n", $s) as $parrafo) {
            $linea = '';
            foreach (preg_split('/ +/', trim($parrafo)) ?: [''] as $pal) {
                while (strlen($pal) > 1 && self::ancho($pal, $size, $b) > $max) {
                    $n = strlen($pal);
                    while ($n > 1 && self::ancho(substr($pal, 0, $n), $size, $b) > $max) {
                        $n--;
                    }
                    if ($linea !== '') {
                        $out[] = $linea;
                        $linea = '';
                    }
                    $out[] = substr($pal, 0, $n);
                    $pal = substr($pal, $n);
                }
                $prueba = $linea === '' ? $pal : $linea . ' ' . $pal;
                if ($linea !== '' && self::ancho($prueba, $size, $b) > $max) {
                    $out[] = $linea;
                    $linea = $pal;
                } else {
                    $linea = $prueba;
                }
            }
            $out[] = $linea;
        }
        return $out;
    }

    public function pagina(): void {
        if ($this->c !== '') {
            $this->paginas[] = $this->c;
        }
        $this->c = '';
        $this->y = self::H - self::M;
    }

    private function asegurar(float $alto): bool {
        if ($this->y - $alto < self::M + 14) {
            $this->pagina();
            return true;
        }
        return false;
    }

    public function texto(string $s, float $size = 9, bool $b = false, float $despues = 3): void {
        foreach ($this->lineas(self::cp($s), self::W - 2 * self::M, $size, $b) as $l) {
            $this->asegurar($size + 2);
            $this->y -= $size + 1;
            $this->t(self::M, $this->y, $l, $size, $b);
            $this->y -= 1;
        }
        $this->y -= $despues;
    }

    private function cabecera(array $cols, array $anchos): void {
        $this->y -= 14;
        $this->c .= sprintf("0.078 0.169 0.290 rg %.2F %.2F %.2F 14 re f\n", self::M, $this->y, array_sum($anchos));
        $x = self::M;
        foreach ($cols as $i => $nombre) {
            $this->t($x + 4, $this->y + 4, self::cp($nombre), 8, true, '1 g');
            $x += $anchos[$i];
        }
    }

    // La columna 4 (Actividades) se ajusta a varias líneas y puede continuar en la página siguiente.
    public function tabla(array $cols, array $anchos, array $filas): void {
        $iAct = 4;
        $this->asegurar(40);
        $this->cabecera($cols, $anchos);
        $xAct = self::M + array_sum(array_slice($anchos, 0, $iAct)) + 4;
        foreach ($filas as $f) {
            foreach ($this->lineas(self::cp((string)$f[$iAct]), $anchos[$iAct] - 8, 8) as $k => $l) {
                if ($this->asegurar(11)) {
                    $this->cabecera($cols, $anchos);
                }
                $this->y -= 10;
                if ($k === 0) {
                    $x = self::M;
                    foreach ($f as $i => $v) {
                        if ($i !== $iAct) {
                            $this->t($x + 4, $this->y, self::cp((string)$v), 8);
                        }
                        $x += $anchos[$i];
                    }
                }
                $this->t($xAct, $this->y, $l, 8);
                $this->y -= 1;
            }
            $this->y -= 3;
            $this->c .= sprintf("0.85 G 0.5 w %.2F %.2F m %.2F %.2F l S\n0 G\n", self::M, $this->y, self::M + array_sum($anchos), $this->y);
        }
        $this->y -= 6;
    }

    public function salida(string $titulo): string {
        $this->pagina();
        $n = count($this->paginas);
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (5 + 2 * $i) . ' 0 R';
        }
        $obj = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        foreach ($this->paginas as $i => $c) {
            $c .= sprintf("BT 0.4 g /F1 8 Tf %.2F 20 Td (%s) Tj ET\n", self::M, self::esc(self::cp($titulo . '  -  Página ' . ($i + 1) . ' de ' . $n)));
            $pg = 5 + 2 * $i;
            $obj[$pg] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ($pg + 1) . ' 0 R >>';
            $obj[$pg + 1] = '<< /Length ' . strlen($c) . " >>\nstream\n" . $c . 'endstream';
        }
        $pdf = "%PDF-1.4\n";
        $offs = [];
        for ($i = 1, $total = count($obj); $i <= $total; $i++) {
            $offs[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $obj[$i] . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($total + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $total; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offs[$i]);
        }
        return $pdf . "trailer\n<< /Size " . ($total + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }
}

function exp_pdf(array $rep): string {
    $pdf = new ExpPdf();
    $pdf->texto($rep['titulo'], 16, true, 2);
    $pdf->texto($rep['subtitulo'], 10, false, 8);
    foreach ($rep['alumnos'] as $i => $a) {
        if ($i > 0) {
            $pdf->pagina();
        }
        $pdf->texto($a['nombre'] . ' — ' . $a['matricula'], 12, true, 2);
        $d = [];
        foreach ($a['datos'] as $k => $v) {
            $d[] = $k . ': ' . $v;
        }
        $pdf->texto(implode('   |   ', $d), 9, false, 6);
        if ($a['filas']) {
            $pdf->tabla($rep['cols'], [62, 48, 48, 62, 380, 120], $a['filas']);
        } else {
            $pdf->texto('Sin jornadas registradas en este periodo.', 9, false, 6);
        }
        $r = [];
        foreach ($a['resumen'] as $k => $v) {
            $r[] = $k . ': ' . $v;
        }
        $pdf->texto(implode('   |   ', $r), 9, true);
    }
    return $pdf->salida($rep['titulo']);
}
