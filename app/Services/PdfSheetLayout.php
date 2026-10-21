<?php

namespace App\Services;

/**
 * Tamaño de la tabla de líneas y reparto en hojas de los PDFs de media carta
 * horizontal (Comprobante y Presupuesto). Hasta $rowsAtFullSize líneas se ven
 * a tamaño normal; de ahí a $rowsOnOnePage la letra, el interlineado y el
 * padding bajan en proporción hasta el piso legible, para que todo siga en una
 * hoja. Con más líneas ya no se comprime: se reparte en varias hojas (al piso).
 *
 * Hojas: la primera lleva el encabezado completo y las siguientes uno
 * compacto. El pie va solo en la última, que siempre lleva al menos una línea.
 * Capacidades al piso de letra (cada plantilla mide las suyas, dependen de su
 * encabezado y su pie):
 * - primera hoja sin pie: $rowsFirstSheet
 * - hoja intermedia (encabezado compacto, sin pie): $rowsMiddleSheet
 * - última hoja con encabezado compacto y pie: $rowsLastSheet
 *
 * Las capacidades se miden con líneas de un solo renglón; una descripción muy
 * larga que se parta en dos ocupa más.
 */
final class PdfSheetLayout
{
    private const FULL_FONT = 7;

    private const MIN_FONT = 6;

    private const FULL_PADDING = 1.5;

    private const MIN_PADDING = 0.5;

    private const FULL_LINE_HEIGHT = 1.15;

    private const MIN_LINE_HEIGHT = 1.0;

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $sheets  Líneas de cada hoja.
     */
    private function __construct(
        public readonly float $rowFont,
        public readonly float $rowPadding,
        public readonly float $rowLineHeight,
        public readonly array $sheets,
    ) {}

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     */
    public static function for(
        array $items,
        int $rowsAtFullSize,
        int $rowsOnOnePage,
        int $rowsFirstSheet,
        int $rowsMiddleSheet,
        int $rowsLastSheet,
    ): self {
        $items = array_values($items);
        $itemCount = count($items);

        $shrink = min(1, max(0, ($itemCount - $rowsAtFullSize) / ($rowsOnOnePage - $rowsAtFullSize)));

        if ($itemCount <= $rowsOnOnePage) {
            $sheets = [$items];
        } else {
            $sheets = [array_splice($items, 0, min($rowsFirstSheet, count($items) - 1))];

            while (count($items) > $rowsLastSheet) {
                $sheets[] = array_splice($items, 0, min($rowsMiddleSheet, count($items) - 1));
            }

            $sheets[] = $items;
        }

        return new self(
            round(self::FULL_FONT - $shrink * (self::FULL_FONT - self::MIN_FONT), 2),
            round(self::FULL_PADDING - $shrink * (self::FULL_PADDING - self::MIN_PADDING), 2),
            round(self::FULL_LINE_HEIGHT - $shrink * (self::FULL_LINE_HEIGHT - self::MIN_LINE_HEIGHT), 3),
            $sheets,
        );
    }

    /**
     * Domicilio y contacto del negocio agrupados en renglones; los vacíos se
     * omiten sin dejar separadores sueltos. El renglón de teléfonos (índice 2)
     * se conserva en su llave para poder anteponerle "Tel.".
     *
     * @param  array<string, ?string>  $business
     * @return array<int, string>
     */
    public static function businessLines(array $business): array
    {
        $joinFilled = fn (array $parts, string $glue) => collect($parts)->map(fn ($part) => trim((string) $part))->filter()->implode($glue);

        return array_filter([
            $joinFilled([$business['address'] ?? null, filled($business['neighborhood'] ?? null) ? 'Col. '.$business['neighborhood'] : null], ', '),
            $joinFilled([$business['city'] ?? null, filled($business['postal_code'] ?? null) ? 'C.P. '.$business['postal_code'] : null], ', '),
            $joinFilled([$business['phone_primary'] ?? null, $business['phone_secondary'] ?? null], ' / '),
            trim((string) ($business['email'] ?? '')),
        ]);
    }
}
