<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #1a1a1a;
            line-height: 1.4;
        }
        .page-header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .empresa-name {
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #1a1a1a;
        }
        .report-title {
            font-size: 12px;
            font-weight: bold;
            margin-top: 4px;
            color: #333;
        }
        .report-meta {
            font-size: 8px;
            color: #666;
            margin-top: 3px;
        }
        .legal-note {
            font-size: 8px;
            font-style: italic;
            color: #555;
            border: 1px solid #ccc;
            padding: 6px 10px;
            margin-bottom: 12px;
            background: #fafafa;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        th {
            background: #4a4a4a;
            color: #fff;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 5px 4px;
            text-align: left;
            letter-spacing: 0.3px;
        }
        th.right, td.right {
            text-align: right;
        }
        th.center, td.center {
            text-align: center;
        }
        td {
            padding: 4px;
            border-bottom: 1px solid #ddd;
            font-size: 8px;
        }
        tr:nth-child(even) td {
            background: #f7f7f7;
        }
        .totals-row td {
            background: #e5e5e5 !important;
            font-weight: bold;
            font-size: 9px;
            border-top: 2px solid #333;
            border-bottom: none;
        }
        .summary-box {
            margin-top: 15px;
            border: 1px solid #999;
            padding: 10px;
        }
        .summary-box h4 {
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 6px;
            text-transform: uppercase;
            color: #333;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            padding: 2px 0;
        }
        .summary-label { color: #555; }
        .summary-value { font-weight: bold; }
        .page-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7px;
            color: #999;
            padding: 8px 0;
            border-top: 1px solid #ddd;
        }
        .num-col { width: 30px; text-align: center; }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="empresa-name">{{ $empresa }}</div>
        <div class="report-title">{{ $titulo }}</div>
        <div class="report-meta">Generado: {{ $fecha_generacion }}</div>
        @if(isset($fecha_inicio) && isset($fecha_fin))
            <div class="report-meta">Período: {{ $fecha_inicio }} al {{ $fecha_fin }}</div>
        @endif
    </div>

    @yield('content')

    <div class="page-footer">
        {{ $empresa }} — {{ $titulo }} — Generado el {{ $fecha_generacion }}
    </div>
</body>
</html>
