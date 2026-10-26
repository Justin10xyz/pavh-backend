{{--
    Estilos compartidos por los PDFs de media carta horizontal (Comprobante y
    Presupuesto): fuentes, base, logo, bloque de negocio, cliente y tabla de
    líneas. Recibe $layout (App\Services\PdfSheetLayout) y $logo
    (['width' => pt, 'height' => pt]).
--}}
/*
 * Fuentes: mismas que el frontend (Inter), copiadas de @fontsource a
 * resources/fonts. dompdf no soporta woff2, por eso se usan los .woff (subset
 * latin, que cubre acentos y ñ). Si una fuente no carga, dompdf cae a
 * Helvetica.
 *
 * Ojo: NO agregar format('woff') al src — el parser de @font-face de dompdf
 * 3.x descarta en silencio cualquier fuente cuyo format() no sea 'truetype'.
 * Sin format() la toma como truetype y php-font-lib detecta que es WOFF por
 * el encabezado del archivo.
 *
 * Inter 600 se registra como 'bold' porque dompdf solo distingue
 * normal/bold.
 */
@font-face {
    font-family: 'Inter';
    font-weight: normal;
    src: url('{{ resource_path('fonts/inter-latin-400-normal.woff') }}');
}
@font-face {
    font-family: 'Inter';
    font-weight: bold;
    src: url('{{ resource_path('fonts/inter-latin-600-normal.woff') }}');
}

@page {
    margin: 16pt 20pt;
}

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
