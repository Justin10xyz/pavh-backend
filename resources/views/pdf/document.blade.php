<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $document['document_type'] }} {{ $document['folio'] }}</title>
    <style>
        /*
         * Fuentes: mismas que el frontend (Inter para texto, Source Serif 4 solo
         * para el wordmark/título), copiadas de @fontsource a resources/fonts.
         * dompdf no soporta woff2, por eso se usan los .woff (subset latin, que
         * cubre acentos y ñ). Solo se incluyen los pesos que usa la plantilla:
         * Inter 400/600 y Source Serif 4 600. Si una fuente no carga, dompdf
         * cae a Helvetica / Times (alternativas web-safe más cercanas).
         *
         * Ojo: NO agregar format('woff') al src — el parser de @font-face de
         * dompdf 3.x descarta en silencio cualquier fuente cuyo format() no sea
         * 'truetype'. Sin format() la toma como truetype y php-font-lib detecta
         * que es WOFF por el encabezado del archivo.
         *
         * Inter 600 se registra como 'bold' porque es el único peso fuerte que
         * usa la plantilla (dompdf solo distingue normal/bold).
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
        @font-face {
            font-family: 'Source Serif 4';
            font-weight: bold;
            src: url('{{ resource_path('fonts/source-serif-4-latin-600-normal.woff') }}');
        }

        /* Tokens de AGENT.md: primary #14293D, border #E4E7EC, text #1D2939, text-muted #667085 */
        @page {
            margin: 28pt 26pt;
        }

        body {
            font-family: 'Inter', Helvetica, Arial, sans-serif;
            font-size: 9pt;
            color: #1D2939;
            margin: 0;
        }

        .muted {
            color: #667085;
        }

        .header {
            width: 100%;
            border-bottom: 1.5pt solid #14293D;
            padding-bottom: 8pt;
            margin-bottom: 12pt;
        }

        .header td {
            vertical-align: bottom;
        }

        .wordmark {
            font-family: 'Source Serif 4', 'Times New Roman', Times, serif;
            font-weight: bold;
            font-size: 16pt;
            color: #14293D;
        }

        .document-type {
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5pt;
            color: #667085;
        }

        .folio {
            font-family: 'Source Serif 4', 'Times New Roman', Times, serif;
            font-weight: bold;
            font-size: 14pt;
            color: #14293D;
        }

        .section-title {
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5pt;
            color: #667085;
            margin: 0 0 4pt;
        }

        .customer {
            margin-bottom: 12pt;
        }

        .customer-name {
            font-weight: bold;
        }

        .items {
            width: 100%;
            border-collapse: collapse;
        }

        .items th {
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #667085;
            text-align: left;
            padding: 4pt 3pt;
            border-bottom: 1pt solid #E4E7EC;
        }

        .items td {
            padding: 5pt 3pt;
            border-bottom: 1pt solid #E4E7EC;
            vertical-align: top;
        }

        .text-right,
        .items th.text-right {
            text-align: right;
        }

        .totals {
            width: 55%;
            margin-left: 45%;
            margin-top: 8pt;
            border-collapse: collapse;
        }

        .totals td {
            padding: 2pt 3pt;
        }

        .totals .grand-total td {
            font-weight: bold;
            font-size: 10.5pt;
            color: #14293D;
            border-top: 1pt solid #14293D;
            padding-top: 4pt;
        }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="wordmark">PAVH</div>
            </td>
            <td class="text-right">
                <div class="document-type">{{ $document['document_type'] }}</div>
                <div class="folio">{{ $document['folio'] }}</div>
                <div class="muted">{{ $document['date'] }}</div>
            </td>
        </tr>
    </table>

    <div class="customer">
        <p class="section-title">Cliente</p>
        @if ($document['customer'])
            <div class="customer-name">{{ $document['customer']['name'] }}</div>
            @if (! empty($document['customer']['phone']))
                <div class="muted">{{ $document['customer']['phone'] }}</div>
            @endif
            @if (! empty($document['customer']['email']))
                <div class="muted">{{ $document['customer']['email'] }}</div>
            @endif
        @else
            <div class="muted">Sin cliente</div>
        @endif
    </div>

    {{-- Cada línea trae su unidad: m² (variantes) o unidades (productos simples) --}}
    <table class="items">
        <thead>
            <tr>
                <th>Producto</th>
                <th class="text-right">Cant.</th>
                <th class="text-right">Precio</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document['items'] as $item)
                <tr>
                    <td>{{ $item['variant_label'] }}</td>
                    <td class="text-right">
                        @if ($item['unit'] === 'uds')
                            {{ rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') }} uds.
                        @else
                            {{ number_format($item['quantity'], 2) }} m²
                        @endif
                    </td>
                    <td class="text-right">${{ number_format($item['unit_price'], 2) }}</td>
                    <td class="text-right">${{ number_format($item['line_total'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">Subtotal</td>
            <td class="text-right">${{ number_format($document['subtotal'], 2) }}</td>
        </tr>
        <tr class="grand-total">
            <td>Total</td>
            <td class="text-right">${{ number_format($document['total'], 2) }}</td>
        </tr>
    </table>
</body>
</html>
