@extends('reports.layout')
@section('content')
<table>
    <thead>
        <tr>
            <th class="num-col">No.</th>
            <th>Producto</th>
            <th class="center">Stock</th>
            <th class="right">Valor Inv. (Q)</th>
            <th class="center">Vendido</th>
            <th class="right">Ingreso Vta. (Q)</th>
            <th class="right">Costo Vta. (Q)</th>
            <th class="right">Utilidad (Q)</th>
            <th class="center">Margen %</th>
        </tr>
    </thead>
    <tbody>
        @foreach($productos as $idx => $p)
        <tr>
            <td class="center">{{ $idx + 1 }}</td>
            <td>{{ $p['nombre'] }}</td>
            <td class="center">{{ $p['stock'] }}</td>
            <td class="right nowrap">{{ number_format($p['valor_inventario'], 2) }}</td>
            <td class="center">{{ $p['cantidad_vendida'] }}</td>
            <td class="right nowrap">{{ number_format($p['ingreso_ventas'], 2) }}</td>
            <td class="right nowrap">{{ number_format($p['costo_ventas'], 2) }}</td>
            <td class="right nowrap">{{ number_format($p['utilidad_bruta'], 2) }}</td>
            <td class="center">{{ $p['margen'] }}%</td>
        </tr>
        @endforeach
        <tr class="totals-row">
            <td colspan="3">TOTALES</td>
            <td class="right nowrap">Q {{ number_format($totales['valor_inventario'], 2) }}</td>
            <td></td>
            <td class="right nowrap">Q {{ number_format($totales['ingreso_ventas'], 2) }}</td>
            <td class="right nowrap">Q {{ number_format($totales['costo_ventas'], 2) }}</td>
            <td class="right nowrap">Q {{ number_format($totales['utilidad_bruta'], 2) }}</td>
            <td></td>
        </tr>
    </tbody>
</table>
@endsection
