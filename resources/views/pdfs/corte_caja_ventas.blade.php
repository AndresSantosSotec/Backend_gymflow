<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Corte de Caja Ventas</title>
    <style>
        @page { size: letter; margin: 14mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #222;
            font-size: 10px;
            line-height: 1.35;
        }
        .header {
            width: 100%;
            border-bottom: 2px solid #333;
            margin-bottom: 12px;
            padding-bottom: 8px;
        }
        .header td { vertical-align: top; }
        .company-name { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .report-title { font-size: 14px; font-weight: bold; text-align: right; text-transform: uppercase; }
        .report-meta { font-size: 9px; color: #555; text-align: right; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .data-table th {
            background-color: #333;
            color: #fff;
            padding: 6px 8px;
            text-align: left;
            font-size: 9px;
            font-weight: bold;
        }
        .data-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #ddd;
            font-size: 9px;
            vertical-align: top;
        }
        .data-table tr:nth-child(even) td { background-color: #f9f9f9; }
        .data-table .r { text-align: right; }
        .data-table .c { text-align: center; }
        .summary-wrap { margin-top: 12px; }
        .summary {
            width: 320px;
            margin-left: auto;
            border-collapse: collapse;
        }
        .summary td { padding: 5px 10px; font-size: 10px; border-bottom: 1px solid #ddd; }
        .summary .lbl { font-weight: bold; }
        .summary .val { text-align: right; }
        .summary tr.grand td {
            border-top: 2px solid #333;
            font-size: 12px;
            font-weight: bold;
            padding: 8px 10px;
        }
        .footer {
            border-top: 1px solid #ccc;
            padding-top: 8px;
            text-align: center;
            font-size: 8px;
            color: #888;
            margin-top: 12px;
        }
    </style>
</head>
<body>
    <table class="header" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width:55%;">
                <div class="company-name">{{ $companyName }}</div>
                <div style="font-size:9px; color:#555;">{{ $companyAddress }}</div>
                <div style="font-size:9px; color:#555;">Tel: {{ $companyPhone }} | {{ $companyEmail }}</div>
            </td>
            <td style="width:45%;">
                <div class="report-title">Corte de Caja Ventas</div>
                <div class="report-meta">
                    <p>Del {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</p>
                    <p>Generado: {{ now()->format('d/m/Y H:i') }}</p>
                    <p>{{ $count }} venta(s) pagada(s)</p>
                </div>
            </td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width:5%;">#</th>
                <th style="width:12%;">Fecha</th>
                <th style="width:22%;">Cliente</th>
                <th style="width:26%;">Productos</th>
                <th style="width:18%;">Métodos</th>
                <th class="r" style="width:17%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($sales as $idx => $sale)
            <tr>
                <td class="c">{{ $idx + 1 }}</td>
                <td>{{ \Carbon\Carbon::parse($sale->created_at)->timezone('America/Guatemala')->format('d/m/Y H:i') }}</td>
                <td>{{ $sale->cliente->nombre ?? 'Consumidor Final' }}</td>
                <td>
                    @foreach($sale->detalles as $detail)
                        {{ $detail->producto->nombre ?? 'Producto' }} x{{ $detail->cantidad }}@if(!$loop->last)<br>@endif
                    @endforeach
                </td>
                <td>
                    @forelse($sale->pagos as $payment)
                        {{ $payment->metodoPago->nombre ?? 'SIN_METODO' }}: Q {{ number_format((float) $payment->monto, 2) }}@if(!$loop->last)<br>@endif
                    @empty
                        SIN_METODO
                    @endforelse
                </td>
                <td class="r" style="font-weight:bold;">Q {{ number_format((float) $sale->total, 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="6" style="text-align:center; padding:24px; color:#999;">No hay ventas pagadas en este periodo.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    @if($count > 0)
    <div class="summary-wrap">
        <table class="summary">
            @foreach($by_method as $methodTotal)
            <tr>
                <td class="lbl">{{ $methodTotal['method'] }}:</td>
                <td class="val">Q {{ number_format($methodTotal['amount'], 2) }}</td>
            </tr>
            @endforeach
            <tr>
                <td class="lbl">Productos vendidos</td>
                <td class="val">{{ $total_items }}</td>
            </tr>
            <tr class="grand">
                <td class="lbl">TOTAL VENTAS</td>
                <td class="val">Q {{ number_format($total_revenue, 2) }}</td>
            </tr>
        </table>
    </div>
    @endif

    @if(!empty($top_products))
    <div style="margin-top:16px;">
        <div style="font-weight:bold; margin-bottom:6px;">Productos más vendidos</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="c">Cantidad</th>
                    <th class="r">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach($top_products as $product)
                <tr>
                    <td>{{ $product['name'] }}</td>
                    <td class="c">{{ $product['quantity'] }}</td>
                    <td class="r">Q {{ number_format($product['amount'], 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="footer">
        {{ $companyName }} | Corte de caja de ventas generado el {{ now()->format('d/m/Y H:i') }} | {{ $companyEmail }}
    </div>
</body>
</html>
