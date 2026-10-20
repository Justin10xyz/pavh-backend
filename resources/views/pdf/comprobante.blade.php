<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante {{ $comprobante['folio'] }}</title>
    @php
        // Media carta horizontal; ver ComprobantePdfGenerator.
        $page ??= ['width' => \App\Services\ComprobantePdfGenerator::PAGE_WIDTH, 'height' => \App\Services\ComprobantePdfGenerator::PAGE_HEIGHT];

        // Cédula de Identificación Fiscal: fracción aproximada de la página
        // tomada del talonario real (~27% del ancho x ~22% del alto).
        $cifWidth = round($page['width'] * 0.27);
        $cifHeight = round($page['height'] * 0.22);

        // Logo compacto (ícono + "INNOVA", 889x539): se fija la altura (~10%
        // del alto de página) y el ancho sale de su proporción real.
        $logoHeight = round($page['height'] * 0.10);
        $logoWidth = round($logoHeight * 889 / 539);

        /*
         * Tamaño de la tabla de líneas según cuántas hay. Hasta
         * ROWS_AT_FULL_SIZE se ve a tamaño normal; de ahí a ROWS_ON_ONE_PAGE
         * la letra, el interlineado y el padding bajan en proporción hasta el piso legible,
         * para que todo siga en una hoja. Con más líneas ya no se comprime:
         * se reparte en varias hojas (al piso), ver $sheets.
         *
         * Las capacidades se midieron con líneas de un solo renglón; una
         * descripción muy larga que se parta en dos ocupa más.
         */
        $items = array_values($comprobante['items']);
        $itemCount = count($items);

        $rowsAtFullSize = 6;
        $rowsOnOnePage = 10;
        $fullFont = 7;
        $minFont = 6;
        $fullPadding = 1.5;
        $minPadding = 0.5;
        $fullLineHeight = 1.15;
        $minLineHeight = 1.0;

        $shrink = min(1, max(0, ($itemCount - $rowsAtFullSize) / ($rowsOnOnePage - $rowsAtFullSize)));
        $rowFont = round($fullFont - $shrink * ($fullFont - $minFont), 2);
        $rowPadding = round($fullPadding - $shrink * ($fullPadding - $minPadding), 2);
        $rowLineHeight = round($fullLineHeight - $shrink * ($fullLineHeight - $minLineHeight), 3);

        /*
         * Hojas: la primera lleva el encabezado completo y las siguientes uno
         * compacto (folio, fecha, cliente). El pie (Cédula, totales, aviso
         * legal) va solo en la última, que siempre lleva al menos una línea.
         * Capacidades al piso de letra:
         * - primera hoja sin pie: $rowsFirstSheet
         * - hoja intermedia (encabezado compacto, sin pie): $rowsMiddleSheet
         * - última hoja con encabezado compacto y pie: $rowsLastSheet
         */
        $rowsFirstSheet = 20;
        $rowsMiddleSheet = 32;
        $rowsLastSheet = 21;

        if ($itemCount <= $rowsOnOnePage) {
            $sheets = [$items];
        } else {
            $sheets = [array_splice($items, 0, min($rowsFirstSheet, count($items) - 1))];

            while (count($items) > $rowsLastSheet) {
                $sheets[] = array_splice($items, 0, min($rowsMiddleSheet, count($items) - 1));
            }

            $sheets[] = $items;
        }

        $sheetCount = count($sheets);
    @endphp
    <style>
        /*
         * Fuentes: mismas que pdf/document.blade.php (ver ahí por qué .woff y
         * por qué el src va sin format()).
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

        /* Escala de grises: el comprobante se imprime en blanco y negro. */
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
            width: {{ $logoWidth }}pt;
            height: {{ $logoHeight }}pt;
        }

        .subdistributor {
            margin-top: 3pt;
            font-size: 6pt;
            font-weight: bold;
        }

        .representative {
            font-size: 6pt;
        }

        .title {
            font-size: 9.5pt;
            font-weight: bold;
            letter-spacing: 0.4pt;
            margin-bottom: 3pt;
        }

        .business-line {
            font-size: 6.5pt;
        }

        .info-box {
            width: 100%;
            border: 0.75pt solid #1A1A1A;
        }

        .info-box td {
            padding: 2pt 4pt;
            border-bottom: 0.5pt solid #1A1A1A;
        }

        .info-box tr:last-child td {
            border-bottom: none;
        }

        .folio {
            font-size: 11pt;
            font-weight: bold;
        }

        /* Encabezado compacto de las hojas 2 en adelante. */
        .compact-header {
            width: 100%;
            border: 0.75pt solid #1A1A1A;
            margin-bottom: 6pt;
        }

        .compact-header td {
            padding: 3pt 5pt;
            vertical-align: middle;
        }

        .compact-title {
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 0.3pt;
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

        /* Líneas: letra y padding de las celdas según cuántas hay (ver arriba). */
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
            font-size: {{ $rowFont }}pt;
            line-height: {{ $rowLineHeight }};
            padding: {{ $rowPadding }}pt 4pt;
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

        /*
         * Cédula de Identificación Fiscal: espacio completo reservado (logos
         * SHCP/SAT, QR, RFC, idCIF). Por ahora solo el texto de RFC/idCIF
         * cuando existe en Configuración; el QR se agrega con su archivo.
         * El borde y el padding van dentro de las medidas de la caja.
         */
        .cif-box {
            width: {{ $cifWidth - 8 }}pt;
            height: {{ $cifHeight - 8 }}pt;
            border: 0.75pt solid #1A1A1A;
            padding: 3.25pt;
            font-size: 6.5pt;
        }

        /*
         * Alto fijo para que ambas cajas midan lo mismo, calculado para el
         * contenido más alto: etiqueta + monto a 10pt, o etiqueta + total con
         * letra en dos renglones. dompdf mide cada renglón con el alto
         * completo de la fuente (~1.2 em de Inter) por el line-height; con
         * menos alto el monto se salía por el borde inferior.
         */
        .amount-box {
            border: 0.75pt solid #1A1A1A;
            padding: 2.5pt 4pt;
            height: 28pt;
        }

        .total-amount {
            font-size: 10pt;
            line-height: 1.1;
        }

        .legal {
            padding-top: 3pt;
            font-size: 5pt;
            line-height: 1.15;
            text-align: justify;
        }
    </style>
</head>
<body>
    @php
        $business = $comprobante['business'];
        $customer = $comprobante['customer'];
        $formatQuantity = fn (float $quantity, int $decimals) => rtrim(rtrim(number_format($quantity, $decimals), '0'), '.');

        // Domicilio y contacto agrupados en renglones; los vacíos se omiten
        // sin dejar separadores sueltos.
        $joinFilled = fn (array $parts, string $glue) => collect($parts)->map(fn ($part) => trim((string) $part))->filter()->implode($glue);
        $businessLines = array_filter([
            $joinFilled([$business['address'] ?? null, filled($business['neighborhood'] ?? null) ? 'Col. '.$business['neighborhood'] : null], ', '),
            $joinFilled([$business['city'] ?? null, filled($business['postal_code'] ?? null) ? 'C.P. '.$business['postal_code'] : null], ', '),
            $joinFilled([$business['phone_primary'] ?? null, $business['phone_secondary'] ?? null], ' / '),
            trim((string) ($business['email'] ?? '')),
        ]);
        $hasPhone = filled($business['phone_primary'] ?? null) || filled($business['phone_secondary'] ?? null);
    @endphp

    @foreach ($sheets as $sheetItems)
        <div @class(['sheet-break' => ! $loop->last])>
            @if ($loop->first)
                <table class="header">
                    <tr>
                        <td style="width: 28%;">
                            <img class="logo" src="{{ resource_path('images/branding/logo-pdf-escala-grises.png') }}" alt="INNOVA">
                            <div class="subdistributor">SUB-DISTRIBUIDOR AUTORIZADO</div>
                            <div class="representative"><span class="label">*Representante:</span> Pedro López Laris</div>
                        </td>
                        <td class="text-center" style="width: 44%;">
                            <div class="title">COMPROBANTE FISCAL SIMPLIFICADO</div>
                            @foreach ($businessLines as $index => $line)
                                {{-- El tercer renglón (índice 2) es el de teléfonos. --}}
                                <div class="business-line">@if ($index === 2 && $hasPhone)Tel. @endif{{ $line }}</div>
                            @endforeach
                        </td>
                        <td style="width: 28%;">
                            <table class="info-box">
                                <tr>
                                    <td>
                                        <span class="label">Folio</span>
                                        <span class="folio">{{ $comprobante['folio'] }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="label">Lugar de expedición</div>
                                        <div>{{ $business['place_of_issue'] ?? '' }}&nbsp;</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="label">Fecha</span>
                                        {{ $comprobante['date'] }}
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <div class="label">Régimen de actividad empresarial</div>
                                        <div>{{ $business['business_regime'] ?? '' }}&nbsp;</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                {{-- Etiquetas siempre impresas, como en el papel; sin cliente quedan en blanco. --}}
                <table class="customer">
                    <tr>
                        <td class="label">Cliente:</td>
                        <td style="width: 48%;">{{ $customer['name'] ?? '' }}</td>
                        <td class="label">Colonia:</td>
                        <td>{{ $customer['neighborhood'] ?? '' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Domicilio:</td>
                        <td>{{ $customer['address'] ?? '' }}</td>
                        <td class="label">C.P.:</td>
                        <td>{{ $customer['postal_code'] ?? '' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Ciudad:</td>
                        <td>{{ $customer['city'] ?? '' }}</td>
                        <td class="label">Teléfono:</td>
                        <td>{{ $customer['phone'] ?? '' }}</td>
                    </tr>
                </table>
            @else
                <table class="compact-header">
                    <tr>
                        <td class="compact-title">COMPROBANTE FISCAL SIMPLIFICADO</td>
                        <td><span class="label">Folio</span> <strong>{{ $comprobante['folio'] }}</strong></td>
                        <td><span class="label">Fecha</span> {{ $comprobante['date'] }}</td>
                        <td><span class="label">Cliente</span> {{ $customer['name'] ?? '' }}</td>
                        <td class="text-right"><span class="label">Hoja</span> {{ $loop->iteration }} de {{ $sheetCount }}</td>
                    </tr>
                </table>
            @endif

            {{-- Mismo criterio de cantidad que pdf/document.blade.php: variantes
                 en cajas con los m² que cubren (precio por m²); productos simples
                 y variantes por pieza en unidades. --}}
            <table class="items">
                <thead>
                    <tr>
                        <th style="width: 19%;">Cant.</th>
                        <th>Descripción</th>
                        <th class="text-right" style="width: 15%;">Valor unitario</th>
                        <th class="text-right" style="width: 15%;">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sheetItems as $item)
                        <tr>
                            <td>
                                @if ($item['unit'] === 'uds')
                                    {{ $formatQuantity($item['quantity'], 2) }} uds.
                                @elseif ($item['boxes'] ?? null)
                                    {{ $item['boxes'] }} {{ $item['boxes'] === 1 ? 'caja' : 'cajas' }}
                                    <span class="muted">({{ $formatQuantity($item['quantity'], 3) }} m²)</span>
                                @else
                                    {{ $formatQuantity($item['quantity'], 3) }} m²
                                @endif
                            </td>
                            <td>{{ $item['description'] }}</td>
                            <td class="text-right">
                                ${{ number_format($item['unit_price'], 2) }}@if ($item['unit'] === 'm2')<span class="muted">/m²</span>@endif
                            </td>
                            <td class="text-right">${{ number_format($item['line_total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($loop->last)
                <table class="footer">
                    <tr>
                        <td rowspan="2" style="width: {{ $cifWidth }}pt; padding-right: 6pt;">
                            <div class="cif-box">
                                @if (filled($business['tax_id'] ?? null))
                                    <div><span class="label">RFC:</span> {{ $business['tax_id'] }}</div>
                                @endif
                                @if (filled($business['tax_id_number'] ?? null))
                                    <div><span class="label">idCIF:</span> {{ $business['tax_id_number'] }}</div>
                                @endif
                            </div>
                        </td>
                        <td style="padding-right: 6pt;">
                            <div class="amount-box">
                                <div class="label">Total con letra</div>
                                <div>{{ $comprobante['total_in_words'] ?? '' }}</div>
                            </div>
                        </td>
                        <td style="width: 100pt;">
                            <div class="amount-box text-right">
                                <div class="label">Total</div>
                                <div class="total-amount"><strong>${{ number_format($comprobante['total'], 2) }}</strong></div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" class="legal">
                            EL PRESENTE COMPROBANTE ACREDITA LA LEGITIMA PROPIEDAD DE LOS BIENES DE QUIEN LOS POSEÉ Y/O LA OPERACIÓN COMERCIAL REALIZADA. PARA SU EXPEDICIÓN SE CUMPLE CON LO PREVISTO EN LA REGLA DE CARÁCTER GENERAL I.2.7.1.22. DE LA RESOLUCIÓN MISCELANEA PARA EL 2014 CON RELACIÓN A LO ESTABLECIDO EN EL ARTÍCULO 29-A DEL CÓDIGO FISCAL DE LA FEDERACIÓN PARA LOS COMPROBANTES FISCALES. PARA PODER DEDUCIRSE O ACREDITARSE FISCALMENTE LAS CANTIDADES AMPARADAS EN ESTE COMPROBANTE SIMPLIFICADO DEBERÁ SOLICITAR OPORTUNAMENTE SE LE RESTITUYA POR UN COMPROBANTE FISCAL DIGITAL POR INTERNET DENTRO DEL PLAZO QUE ESTIPULE EL PROVEEDOR.
                        </td>
                    </tr>
                </table>
            @endif
        </div>
    @endforeach
</body>
</html>
