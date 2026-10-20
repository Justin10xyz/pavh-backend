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
