@extends('reports.layout')
@section('content')
<div class="legal-note">
    Período de análisis: {{ $meses }} meses ({{ $fecha_inicio }} a {{ $fecha_fin }})
</div>

<table>
    <thead>
        <tr>
            <th class="num-col">No.</th>
            <th>Producto</th>
            <th class="center">Clasif.</th>
            <th class="center">Stock</th>
            <th class="center">Vendido</th>
            <th class="center">Vta./Mes</th>
            <th class="center">Índ. Rot.</th>
            <th class="center">Días Inv.</th>
            <th class="right">Ingreso (Q)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($productos as $idx => $p)
        <tr>
            <td class="center">{{ $idx + 1 }}</td>
            <td>{{ $p['nombre'] }}</td>
            <td class="center"><strong>{{ $p['clasificacion'] }}</strong></td>
            <td class="center">{{ $p['stock'] }}</td>
            <td class="center">{{ $p['cantidad_vendida'] }}</td>
            <td class="center">{{ $p['vta_mes'] }}</td>
            <td class="center">{{ $p['indice_rotacion'] }}x</td>
            <td class="center">{{ $p['dias_inventario'] >= 999 ? '∞' : $p['dias_inventario'] . 'd' }}</td>
            <td class="right nowrap">{{ number_format($p['ingreso'], 2) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<div style="margin-top: 15px; font-size: 8px; color: #666; border-top: 1px solid #ccc; padding-top: 6px;">
    <strong>Clasificación ABC:</strong> A = Alta rotación (índ. ≥ 3), B = Media rotación (índ. ≥ 1), C = Baja rotación (índ. < 1)
</div>
@endsection
