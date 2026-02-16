@extends('reports.layout')
@section('content')
<table>
    <thead>
        <tr>
            <th class="num-col">No.</th>
            <th>Producto</th>
            <th>Descripción</th>
            <th>Marca</th>
            <th>Presentación</th>
            <th class="right">P. Compra (Q)</th>
            <th class="right">P. Venta (Q)</th>
            <th class="center">Margen %</th>
            <th class="center">Stock</th>
            <th class="center">Estado</th>
        </tr>
    </thead>
    <tbody>
        @foreach($productos as $idx => $p)
        <tr>
            <td class="center">{{ $idx + 1 }}</td>
            <td>{{ $p['nombre'] }}</td>
            <td>{{ \Illuminate\Support\Str::limit($p['descripcion'], 30) }}</td>
            <td>{{ $p['marca'] }}</td>
            <td>{{ $p['presentacion'] }}</td>
            <td class="right nowrap">{{ number_format($p['precio_compra'], 2) }}</td>
            <td class="right nowrap">{{ number_format($p['precio_venta'], 2) }}</td>
            <td class="center">{{ $p['margen'] }}%</td>
            <td class="center">{{ $p['stock'] }}</td>
            <td class="center">{{ $p['estado'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
