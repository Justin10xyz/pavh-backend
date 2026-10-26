<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function topProducts(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Cuenta como vendido lo de las cotizaciones pagadas: el pago es lo
        // que descuenta el stock. El rango (inclusivo, solo fecha) se aplica
        // a paid_at, no a la fecha en que se cotizó; whereHas respeta el
        // SoftDeletes de Quote.
        //
        // Agrupa por el par (variante, producto simple): cada línea apunta a
        // solo uno de los dos, así que cada grupo es un único producto.
        //
        // Se ordena por revenue porque es lo único comparable entre tipos:
        // quantity_sold suma unidades distintas según el tipo (m² para
        // variantes, unidades para productos simples), así que solo es
        // informativo por fila. boxes_sold es la cantidad real de las variantes
        // (se venden por caja); en productos simples es null.
        $from = $request->query('from');
        $to = $request->query('to');

        $rows = QuoteItem::query()
            ->select('product_variant_id', 'simple_product_id')
            ->selectRaw('SUM(quantity) as quantity_sold, SUM(boxes) as boxes_sold, SUM(line_total) as revenue')
            ->whereHas('quote', fn (Builder $query) => $query
                ->whereNotNull('paid_at')
                ->when($from, fn (Builder $query) => $query->whereDate('paid_at', '>=', $from))
                ->when($to, fn (Builder $query) => $query->whereDate('paid_at', '<=', $to)))
            ->groupBy('product_variant_id', 'simple_product_id')
            ->orderByDesc('revenue')
            ->limit($request->integer('limit') ?: 5)
            ->get();

        // withTrashed: un producto vendido sigue contando en el ranking aunque
        // se haya dado de baja después.
        $rows->load([
            'productVariant' => fn ($query) => $query->withTrashed(),
            'productVariant.product' => fn ($query) => $query->withTrashed(),
            'simpleProduct' => fn ($query) => $query->withTrashed(),
        ]);

        return response()->json([
            'data' => $rows->map(fn (QuoteItem $row) => [
                // Producto simple: solo su nombre (no tiene línea/color/medida).
                'name' => $row->simpleProduct
                    ? $row->simpleProduct->name
                    : trim(implode(' ', [
                        $row->productVariant->product->name,
                        $row->productVariant->color,
                        $row->productVariant->size,
                    ])),
                'type' => $row->simple_product_id ? 'simple' : 'variant',
                'product_variant_id' => $row->product_variant_id,
                'simple_product_id' => $row->simple_product_id,
                'quantity_sold' => round((float) $row->getAttribute('quantity_sold'), 2),
                'boxes_sold' => $row->simple_product_id ? null : (int) $row->getAttribute('boxes_sold'),
                'revenue' => round((float) $row->getAttribute('revenue'), 2),
            ])->all(),
        ]);
    }
}
