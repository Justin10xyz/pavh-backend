<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de Comprobantes {{ $report['period_label'] }}</title>
    @php
        // Carta vertical; ver ComprobanteReportPdfGenerator.
        $page ??= ['width' => \App\Services\ComprobanteReportPdfGenerator::PAGE_WIDTH, 'height' => \App\Services\ComprobanteReportPdfGenerator::PAGE_HEIGHT];

        // Logo compacto (889x539) a la misma altura que en el Comprobante
        // (~40pt): sheet-styles lo dimensiona con $logo, y el 10% del alto que
        // usa el Comprobante aquí lo duplicaría.
        $logo = ['height' => round($page['height'] * 0.05)];
        $logo['width'] = round($logo['height'] * 889 / 539);

        // Capacidades medidas con el encabezado y el pie de esta plantilla
        // (montos de $99,999,999.99), con ~5% de margen sobre el máximo que
        // cupo: 42 a letra completa, 64 al piso en una hoja, y 70/75/68 en
        // primera/intermedia/última. Ver PdfSheetLayout.
        $layout = \App\Services\PdfSheetLayout::for(
            $report['items'],
            rowsAtFullSize: 40,
            rowsOnOnePage: 60,
            rowsFirstSheet: 66,
            rowsMiddleSheet: 71,
            rowsLastSheet: 64,
        );
        $sheetCount = count($layout->sheets);
        $summary = $report['summary'];
    @endphp
    <style>
        @include('pdf.partials.sheet-styles')

        /*
         * sheet-styles está pensado para media carta; en carta vertical solo
         * cambia el logo (arriba, con $logo). El resto de sus reglas (márgenes
         * de @page, fuentes, tabla .items) aplica igual.
         */
        .title {
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.4pt;
            margin-bottom: 3pt;
        }

        .period {
            font-size: 10pt;
            font-weight: bold;
            margin-bottom: 2pt;
        }

        .header .sheet-number {
            vertical-align: bottom;
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

        .summary-box {
            border: 0.75pt solid #1A1A1A;
        }

        .summary-box td {
            padding: 3pt 6pt;
            font-size: 8pt;
            border-bottom: 0.5pt solid #BBBBBB;
        }

        .summary-box tr:last-child td {
            border-bottom: none;
            font-size: 10pt;
        }
    </style>
</head>
<body>
    @foreach ($layout->sheets as $sheetItems)
        <div @class(['sheet-break' => ! $loop->last])>
            @if ($loop->first)
                <table class="header">
                    <tr>
                        <td style="width: 32%;">
                            @include('pdf.partials.brand')
                        </td>
                        <td class="text-center" style="width: 46%;">
                            <div class="title">REPORTE DE COMPROBANTES</div>
                            <div class="period">{{ $report['period_label'] }}</div>
                            <div class="muted">Generado: {{ $report['generated_at'] }}</div>
                        </td>
                        <td class="text-right sheet-number" style="width: 22%;">
                            @if ($sheetCount > 1)
                                <span class="label">Hoja</span> 1 de {{ $sheetCount }}
                            @endif
                        </td>
                    </tr>
                </table>
            @else
                <table class="compact-header">
                    <tr>
                        <td class="compact-title">REPORTE DE COMPROBANTES</td>
                        <td><span class="label">Periodo</span> {{ $report['period_label'] }}</td>
                        <td class="text-right"><span class="label">Hoja</span> {{ $loop->iteration }} de {{ $sheetCount }}</td>
                    </tr>
                </table>
            @endif

            <table class="items">
                <thead>
                    <tr>
                        <th style="width: 15%;">Folio</th>
                        <th style="width: 13%;">Fecha</th>
                        <th>Cliente</th>
                        <th class="text-right" style="width: 16%;">Subtotal</th>
                        <th class="text-right" style="width: 16%;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sheetItems as $item)
                        <tr>
                            <td>{{ $item['folio'] }}</td>
                            <td>{{ $item['date'] }}</td>
                            <td>{{ $item['customer'] }}</td>
                            <td class="text-right">${{ number_format((float) $item['subtotal'], 2) }}</td>
                            <td class="text-right">${{ number_format((float) $item['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center muted">Sin comprobantes en este periodo.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($loop->last)
                <table class="footer">
                    <tr>
                        <td></td>
                        <td style="width: 200pt;">
                            <table class="summary-box" style="width: 100%;">
                                <tr>
                                    <td colspan="2"><strong>{{ $summary['count'] }} {{ $summary['count'] === 1 ? 'comprobante' : 'comprobantes' }}</strong></td>
                                </tr>
                                <tr>
                                    <td><span class="label">Subtotal:</span></td>
                                    <td class="text-right">${{ number_format((float) $summary['subtotal'], 2) }}</td>
                                </tr>
                                <tr>
                                    <td><span class="label">Total:</span></td>
                                    <td class="text-right"><strong>${{ number_format((float) $summary['total'], 2) }}</strong></td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            @endif
        </div>
    @endforeach
</body>
</html>
