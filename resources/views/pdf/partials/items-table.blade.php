@php
    $formatQuantity = fn (float $quantity, int $decimals) => rtrim(rtrim(number_format($quantity, $decimals), '0'), '.');
@endphp
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
        @foreach ($items as $item)
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
