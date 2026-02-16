@extends('reports.layout')
@section('content')
<div class="legal-note">
    <strong>Base Legal:</strong> Decreto 10-2012, Ley de Actualización Tributaria, República de Guatemala.<br>
    <strong>Período:</strong> {{ $periodo }} &nbsp; | &nbsp; <strong>Fecha de Corte:</strong> {{ $fecha_corte }}
</div>

<table>
    <thead>
        <tr>
            <th class="num-col">No.</th>
            <th>Producto</th>
            <th>Marca</th>
            <th>Presentación</th>
            <th class="right">P. Compra (Q)</th>
            <th class="right">P. Venta (Q)</th>
            <th class="center">Stock</th>
            <th class="right">Valor Inv. (Q)</th>
            <th class="center">Ingresos</th>
            <th class="center">Egresos</th>
            <th class="center">Vendido</th>
            <th class="right">Ventas (Q)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($productos as $idx => $p)
        <tr>
            <td class="center">{{ $idx + 1 }}</td>
            <td>{{ $p['nombre'] }}</td>
            <td>{{ $p['marca'] }}</td>
            <td>{{ $p['presentacion'] }}</td>
            <td class="right nowrap">{{ number_format($p['precio_compra'], 2) }}</td>
            <td class="right nowrap">{{ number_format($p['precio_venta'], 2) }}</td>
            <td class="center">{{ $p['stock'] }}</td>
            <td class="right nowrap">{{ number_format($p['valor_inventario'], 2) }}</td>
            <td class="center">{{ $p['ingresos'] }}</td>
            <td class="center">{{ $p['egresos'] }}</td>
            <td class="center">{{ $p['unidades_vendidas'] }}</td>
            <td class="right nowrap">{{ number_format($p['valor_ventas'], 2) }}</td>
        </tr>
        @endforeach
        <tr class="totals-row">
            <td colspan="6">TOTALES</td>
            <td class="center">{{ $totales['total_stock'] }}</td>
            <td class="right nowrap">Q {{ number_format($totales['valor_inventario'], 2) }}</td>
            <td colspan="3"></td>
            <td class="right nowrap">Q {{ number_format($totales['total_ventas'], 2) }}</td>
        </tr>
    </tbody>
</table>

<div style="margin-top: 20px; font-size: 8px; color: #444;">
    <table style="width: auto; border: none;">
        <tr style="border: none;">
            <td style="border: none; padding: 20px 40px 0 0; text-align: center;">
                ___________________________<br>
                <strong>Firma Responsable</strong>
            </td>
            <td style="border: none; padding: 20px 40px 0 0; text-align: center;">
                ___________________________<br>
                <strong>Contador</strong>
            </td>
            <td style="border: none; padding: 20px 0 0 0; text-align: center;">
                ___________________________<br>
                <strong>Representante Legal</strong>
            </td>
        </tr>
    </table>
</div>
@endsection
