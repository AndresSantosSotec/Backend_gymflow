@extends('reports.layout')
@section('content')
<table style="margin-bottom: 15px; width: auto;">
    <tr>
        <td style="border: 1px solid #ccc; padding: 6px 12px; background: #f5f5f5;"><strong>Ingresos:</strong> {{ $resumen['count_ingresos'] }} movimientos ({{ $resumen['total_ingresos'] }} uds.)</td>
        <td style="border: 1px solid #ccc; padding: 6px 12px; background: #f5f5f5;"><strong>Egresos:</strong> {{ $resumen['count_egresos'] }} movimientos ({{ $resumen['total_egresos'] }} uds.)</td>
    </tr>
</table>

<table>
    <thead>
        <tr>
            <th class="num-col">No.</th>
            <th>Fecha</th>
            <th class="center">Tipo</th>
            <th>Producto</th>
            <th class="center">Cantidad</th>
            <th>Motivo</th>
            <th class="center">Ref.</th>
        </tr>
    </thead>
    <tbody>
        @foreach($movimientos as $idx => $m)
        <tr>
            <td class="center">{{ $idx + 1 }}</td>
            <td class="nowrap">{{ $m->created_at->format('d/m/Y H:i') }}</td>
            <td class="center"><strong>{{ $m->tipo }}</strong></td>
            <td>{{ $m->producto?->nombre ?? 'N/A' }}</td>
            <td class="center">{{ $m->cantidad }}</td>
            <td>{{ $m->motivo }}</td>
            <td class="center">{{ $m->referencia_id ? '#'.$m->referencia_id : '-' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
