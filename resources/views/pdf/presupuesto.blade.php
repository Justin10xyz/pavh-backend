<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Presupuesto {{ $quote['folio'] }}</title>
    @php
        // Media carta horizontal, mismo tamaño que el Comprobante; ver QuotePdfGenerator.
        $page ??= ['width' => \App\Services\QuotePdfGenerator::PAGE_WIDTH, 'height' => \App\Services\QuotePdfGenerator::PAGE_HEIGHT];

        // Mismo logo y proporción que el Comprobante.
        $logo = ['height' => round($page['height'] * 0.10)];
        $logo['width'] = round($logo['height'] * 889 / 539);

        // Capacidades medidas con el encabezado y la caja de totales completa
        // (los 7 renglones) de esta plantilla; ver PdfSheetLayout.
        $layout = \App\Services\PdfSheetLayout::for(
            $quote['items'],
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

        /*
         * Color de acento: lo que distingue al Presupuesto del Comprobante (que
         * va en escala de grises, sin banda ni caja de saldo).
         */
        .title-band {
            background-color: #A67C3D;
            color: #FFFFFF;
            font-size: 10pt;
            font-weight: bold;
            letter-spacing: 2pt;
            padding: 3pt 0;
            margin-bottom: 3pt;
        }

        .info-box {
            width: 100%;
            border: 0.75pt solid #A67C3D;
        }

        .info-box td {
            padding: 2pt 4pt;
            border-bottom: 0.5pt solid #A67C3D;
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
            border: 0.75pt solid #A67C3D;
            margin-bottom: 6pt;
        }

        .compact-header td {
            padding: 3pt 5pt;
            vertical-align: middle;
        }

        .compact-title {
            background-color: #A67C3D;
            color: #FFFFFF;
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 1pt;
        }

        /* Caja de totales */
        .totals {
            width: 100%;
            border: 0.75pt solid #A67C3D;
        }

        .totals td {
            padding: 1.5pt 5pt;
        }

        .totals .grand-total td {
            font-weight: bold;
            font-size: 8.5pt;
            border-top: 0.75pt solid #A67C3D;
        }

        .totals .balance td {
            background-color: #A67C3D;
            color: #FFFFFF;
            font-weight: bold;
            font-size: 9.5pt;
            padding-top: 2.5pt;
            padding-bottom: 2.5pt;
        }

        .totals .payment td {
            border-top: 0.5pt solid #A67C3D;
            font-size: 6.5pt;
        }
    </style>
</head>
<body>
    @php
        $business = $quote['business'];
        $customer = $quote['customer'];
        $businessLines = \App\Services\PdfSheetLayout::businessLines($business);
        $hasPhone = filled($business['phone_primary'] ?? null) || filled($business['phone_secondary'] ?? null);

        // Saldo negativo (anticipo mayor al total): signo explícito antes del $.
        $formatSigned = fn (float $amount) => ($amount < 0 ? '-' : '').'$'.number_format(abs($amount), 2);
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
                            <div class="title-band">PRESUPUESTO</div>
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
                                        <span class="folio">{{ $quote['folio'] }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <span class="label">Fecha</span>
                                        {{ $quote['date'] }}
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
                        <td class="compact-title">PRESUPUESTO</td>
                        <td><span class="label">Folio</span> <strong>{{ $quote['folio'] }}</strong></td>
                        <td><span class="label">Fecha</span> {{ $quote['date'] }}</td>
                        <td><span class="label">Cliente</span> {{ $customer['name'] ?? '' }}</td>
                        <td class="text-right"><span class="label">Hoja</span> {{ $loop->iteration }} de {{ $sheetCount }}</td>
                    </tr>
                </table>
            @endif

            @include('pdf.partials.items-table', ['items' => $sheetItems])

            @if ($loop->last)
                <table class="footer">
                    <tr>
                        <td></td>
                        <td style="width: 200pt;">
                            <table class="totals">
                                <tr>
                                    <td class="label">Subtotal</td>
                                    <td class="text-right">${{ number_format($quote['subtotal'], 2) }}</td>
                                </tr>
                                @if ($quote['discount'] > 0)
                                    <tr>
                                        <td class="label">Descuento</td>
                                        <td class="text-right">-${{ number_format($quote['discount'], 2) }}</td>
                                    </tr>
                                @endif
                                @if ($quote['shipping_cost'] > 0)
                                    <tr>
                                        <td class="label">Costo de envío</td>
                                        <td class="text-right">${{ number_format($quote['shipping_cost'], 2) }}</td>
                                    </tr>
                                @endif
                                <tr class="grand-total">
                                    <td>TOTAL</td>
                                    <td class="text-right">${{ number_format($quote['total'], 2) }}</td>
                                </tr>
                                @if ($quote['down_payment'] > 0)
                                    <tr>
                                        <td class="label">Anticipo</td>
                                        <td class="text-right">-${{ number_format($quote['down_payment'], 2) }}</td>
                                    </tr>
                                @endif
                                <tr class="balance">
                                    <td>SALDO</td>
                                    <td class="text-right">{{ $formatSigned($quote['balance']) }}</td>
                                </tr>
                                <tr class="payment">
                                    <td class="label">Forma de pago</td>
                                    <td class="text-right">
                                        @if ($quote['payment_terms'] !== 'credit')
                                            Contado
                                        @elseif ($quote['credit_due_date'])
                                            {{-- Sin guion largo: el subset de Inter no lo trae. --}}
                                            Crédito (vence {{ $quote['credit_due_date'] }})
                                        @else
                                            Crédito
                                        @endif
                                    </td>
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
