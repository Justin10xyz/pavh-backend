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

        // Imagen de la cédula (2148x1450): más angosta que la caja, así que se
        // ajusta por alto al interior de la caja (sin borde ni padding) y el
        // ancho sale de su proporción real.
        $cifImage = ['height' => $cifHeight - 8];
        $cifImage['width'] = round($cifImage['height'] * 2148 / 1450);

        // Logo compacto (ícono + "INNOVA", 889x539): se fija la altura (~10%
        // del alto de página) y el ancho sale de su proporción real.
        $logo = ['height' => round($page['height'] * 0.10)];
        $logo['width'] = round($logo['height'] * 889 / 539);

        // Capacidades medidas con el encabezado y el pie de esta plantilla;
        // ver PdfSheetLayout.
        $layout = \App\Services\PdfSheetLayout::for(
            $comprobante['items'],
            rowsAtFullSize: 6,
            rowsOnOnePage: 10,
            rowsFirstSheet: 20,
            rowsMiddleSheet: 32,
            rowsLastSheet: 21,
        );
        $sheetCount = count($layout->sheets);
    @endphp
    <style>
        @include('pdf.partials.sheet-styles')

        /* Escala de grises: el comprobante se imprime en blanco y negro. */
        .title {
            font-size: 9.5pt;
            font-weight: bold;
            letter-spacing: 0.4pt;
            margin-bottom: 3pt;
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

        /*
         * Cédula de Identificación Fiscal: recorte fijo de la Constancia de
         * Situación Fiscal (logos SHCP/SAT, QR, RFC, idCIF); no depende de
         * Configuración. El borde y el padding van dentro de las medidas de
         * la caja.
         */
        .cif-box {
            width: {{ $cifWidth - 8 }}pt;
            height: {{ $cifHeight - 8 }}pt;
            border: 0.75pt solid #1A1A1A;
            padding: 3.25pt;
            text-align: center;
        }

        .cif-image {
            width: {{ $cifImage['width'] }}pt;
            height: {{ $cifImage['height'] }}pt;
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
        $businessLines = \App\Services\PdfSheetLayout::businessLines($business);
        $hasPhone = filled($business['phone_primary'] ?? null) || filled($business['phone_secondary'] ?? null);
    @endphp

    @foreach ($layout->sheets as $sheetItems)
        <div @class(['sheet-break' => ! $loop->last])>
            @if ($loop->first)
                <table class="header">
                    <tr>
                        <td style="width: 28%;">
                            @include('pdf.partials.brand')
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
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                @include('pdf.partials.customer')
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

            @include('pdf.partials.items-table', ['items' => $sheetItems])

            @if ($loop->last)
                <table class="footer">
                    <tr>
                        <td rowspan="2" style="width: {{ $cifWidth }}pt; padding-right: 6pt;">
                            <div class="cif-box">
                                <img class="cif-image" src="{{ resource_path('images/branding/cedula_fiscal_final_bw.png') }}" alt="Cédula de Identificación Fiscal">
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
