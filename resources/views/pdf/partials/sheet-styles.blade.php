{{--
    Estilos compartidos por los PDFs de media carta horizontal (Comprobante y
    Presupuesto): fuentes, base, logo, bloque de negocio, cliente y tabla de
    líneas. Recibe $layout (App\Services\PdfSheetLayout) y $logo
    (['width' => pt, 'height' => pt]); las plantillas de media carta además
    $sheet y $paper (ver ComprobantePdfGenerator::sheetViewData), el reporte no.
--}}
/*
 * Fuentes: mismas que el frontend (Inter), TTF estáticos (no variable) del
 * release oficial de Inter (github.com/rsms/inter, extras/ttf) en
 * resources/fonts. Si una fuente no carga, dompdf cae a Helvetica.
 *
 * Tienen que ser TrueType: dompdf no convierte WOFF/WOFF2, los incrusta tal
 * cual en /FontFile2 y el visor de iOS (Quick Look) los rechaza y muestra
 * símbolos raros, aunque en escritorio se vean bien. Tampoco usar la versión
 * variable (InterVariable): dompdf solo toma la instancia por defecto.
 *
 * El parser de @font-face de dompdf 3.x descarta en silencio cualquier
 * fuente cuyo format() no sea 'truetype'.
 *
 * Inter 600 se registra como 'bold' porque dompdf solo distingue
 * normal/bold.
 *
 * Al cambiar estos archivos, borrar la caché de dompdf (storage/fonts/inter_*)
 * para que regenere los .ufm.
 */
@font-face {
    font-family: 'Inter';
    font-weight: normal;
    src: url('{{ resource_path('fonts/Inter-Regular.ttf') }}') format('truetype');
}
@font-face {
    font-family: 'Inter';
    font-weight: bold;
    src: url('{{ resource_path('fonts/Inter-SemiBold.ttf') }}') format('truetype');
}

@isset($paper)
/*
 * Hojas de media carta ($sheet) sobre papel carta ($paper): sin márgenes de
 * página; cada hoja es un .sheet de exactamente $sheet['height'] de alto
 * (padding incluido) que lleva sus propios márgenes, así que dos hojas
 * llenan una carta y la segunda empieza justo en la mitad. Arriba lleva más
 * que abajo para librar la zona no imprimible del borde de la carta.
 *
 * El contenido de una hoja no puede pasar de su alto interior (358pt): las
 * capacidades de PdfSheetLayout están medidas para que quepa (peor caso
 * medido: ~357.96pt, Presupuesto con caja de totales de 8 renglones y
 * abonos). Si se rebasara (p. ej. descripciones en dos renglones), dompdf
 * no lo resuelve igual en las dos mitades: la hoja de abajo se pasa entera
 * a la mitad superior de una carta nueva, y la de arriba se queda y se
 * recorta en su borde (overflow: hidden) para no invadir a la de abajo.
 */
@php
    $sheetPadding = ['top' => 22, 'side' => 20, 'bottom' => 16];
@endphp
@page {
    margin: 0;
}

.sheet {
    height: {{ $sheet['height'] - $sheetPadding['top'] - $sheetPadding['bottom'] }}pt;
    padding: {{ $sheetPadding['top'] }}pt {{ $sheetPadding['side'] }}pt {{ $sheetPadding['bottom'] }}pt;
    overflow: hidden;
    page-break-inside: avoid;
}
@else
/* Carta vertical (reporte): márgenes de página. */
@page {
    margin: 16pt 20pt;
}
@endisset

body {
    font-family: 'Inter', Helvetica, Arial, sans-serif;
    font-size: 7pt;
    line-height: 1.15;
    color: #1A1A1A;
    margin: 0;
}

table {
    border-collapse: collapse;
}

.sheet-break {
    page-break-after: always;
}

.muted {
    color: #555555;
}

.label {
    font-size: 5.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.2pt;
}

.text-right {
    text-align: right;
}

.text-center {
    text-align: center;
}

/* Encabezado */
.header {
    width: 100%;
    margin-bottom: 6pt;
}

.header td {
    vertical-align: top;
}

.logo {
    width: {{ $logo['width'] }}pt;
    height: {{ $logo['height'] }}pt;
}

.subdistributor {
    margin-top: 3pt;
    font-size: 6pt;
    font-weight: bold;
}

.subdistributor td {
    padding: 0;
    vertical-align: middle;
    white-space: nowrap;
}

/*
 * interceramic-logo-pdf.png es el logo oficial recortado a su contenido
 * (288x46): el PNG original trae ~85% de margen transparente y a esta altura
 * el logo quedaría ilegible. Se fija la altura (menos de 1/4 de la de INNOVA)
 * y el ancho sale de su proporción; texto + logo (~154pt) caben en la
 * columna del logo (28% de la página) sin invadir la del centro.
 */
.interceramic-logo {
    height: 9pt;
    width: {{ round(9 * 288 / 46, 1) }}pt;
    margin-left: 3pt;
}

.representative {
    font-size: 6pt;
}

.business-line {
    font-size: 6.5pt;
}

/* Cliente */
.customer {
    width: 100%;
    border: 0.75pt solid #1A1A1A;
    margin-bottom: 6pt;
}

.customer td {
    padding: 1.5pt 4pt;
    vertical-align: top;
}

.customer .label {
    width: 44pt;
}

/* Líneas: letra y padding de las celdas según cuántas hay (ver PdfSheetLayout). */
.items {
    width: 100%;
    border: 0.75pt solid #1A1A1A;
    margin-bottom: 6pt;
}

.items th {
    font-size: 5.5pt;
    font-weight: bold;
    text-transform: uppercase;
    padding: 2.5pt 4pt;
    border-bottom: 0.75pt solid #1A1A1A;
    border-right: 0.5pt solid #1A1A1A;
}

.items td {
    font-size: {{ $layout->rowFont }}pt;
    line-height: {{ $layout->rowLineHeight }};
    padding: {{ $layout->rowPadding }}pt 4pt;
    vertical-align: top;
    border-bottom: 0.5pt solid #BBBBBB;
    border-right: 0.5pt solid #1A1A1A;
}

.items tr {
    page-break-inside: avoid;
}

.items th:last-child,
.items td:last-child {
    border-right: none;
}

/* Pie: no se parte entre páginas. */
.footer {
    width: 100%;
    page-break-inside: avoid;
}

.footer td {
    vertical-align: top;
}
