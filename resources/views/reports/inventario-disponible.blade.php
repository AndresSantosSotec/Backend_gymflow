@extends('reports.layout')
@section('content')
<table>
    <thead>
        <tr>
            <th class="num-col">No.</th>
            <th>Producto</th>
            <th>Marca</th>
            <th>Presentación</th>
            <th class="center">Stock</th>
            <th class="right">P. Compra (Q)</th>
            <th class="right">P. Venta (Q)</th>
            <th class="right">Valor Costo (Q)</th>
            <th class="right">Valor Venta (Q)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($productos as $idx => $p)
        <tr>
            <td class="center">{{ $idx + 1 }}</td>
            <td>{{ $p['nombre'] }}</td>
            <td>{{ $p['marca'] }}</td>
            <td>{{ $p['presentacion'] }}</td>
            <td class="center">{{ $p['stock'] }}</td>
            <td class="right nowrap">{{ number_format($p['precio_compra'], 2) }}</td>
            <td class="right nowrap">{{ number_format($p['precio_venta'], 2) }}</td>
            <td class="right nowrap">{{ number_format($p['valor_costo'], 2) }}</td>
            <td class="right nowrap">{{ number_format($p['valor_venta'], 2) }}</td>
        </tr>
        @endforeach
        <tr class="totals-row">
            <td colspan="4">TOTALES</td>
            <td class="center">{{ $totales['total_stock'] }}</td>
            <td></td>
            <td></td>
            <td class="right nowrap">Q {{ number_format($totales['valor_total_costo'], 2) }}</td>
            <td class="right nowrap">Q {{ number_format($totales['valor_total_venta'], 2) }}</td>
        </tr>
    </tbody>
</table>
@endsection
