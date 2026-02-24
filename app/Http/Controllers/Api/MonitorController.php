<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * MonitorController — Módulo de monitoreo para administradores.
 *
 * Solo usuarios con permiso MONITOR_VIEW (rol admin según seeder) pueden ver
 * los logs detallados del sistema para verificar errores y depuración.
 */
class MonitorController extends Controller
{
    /** Ruta del archivo de log de Laravel */
    private function logPath(): string
    {
        return storage_path('logs/laravel.log');
    }

    /**
     * GET /api/monitor/logs
     *
     * Devuelve las últimas líneas del laravel.log.
     * Query: lines (default 500), level (opcional: error, warning, info, all).
     */
    public function logs(Request $request)
    {
        $lines = (int) $request->input('lines', 500);
        $lines = min(max($lines, 50), 5000);
        $level = $request->input('level', 'all');

        $path = $this->logPath();

        if (! File::exists($path)) {
            return response()->json([
                'entries' => [],
                'message' => 'No hay archivo de log aún.',
                'path' => $path,
            ]);
        }

        try {
            $content = File::get($path);
        } catch (\Throwable $e) {
            Log::warning('[Monitor] No se pudo leer el log: ' . $e->getMessage());
            return response()->json([
                'entries' => [],
                'error' => 'No se pudo leer el archivo de log.',
            ], 500);
        }

        // Laravel log format: [YYYY-MM-DD HH:MM:SS] environment.LEVEL: message ...
        $allLines = explode("\n", $content);
        $allLines = array_filter($allLines);
        $entries = array_slice($allLines, -$lines);
        $entries = array_values($entries);

        // Filtrar por nivel si se pide
        if ($level !== 'all' && in_array($level, ['error', 'warning', 'info', 'debug'], true)) {
            $entries = array_filter($entries, function ($line) use ($level) {
                $upper = strtoupper($level);
                return str_contains($line, ".{$upper}:") || str_contains($line, ".{$upper}]");
            });
            $entries = array_values($entries);
        }

        return response()->json([
            'entries' => $entries,
            'total_lines' => count($entries),
            'level' => $level,
            'path' => $path,
        ]);
    }

    /**
     * GET /api/monitor/stats
     *
     * Resumen rápido: tamaño del log, última modificación, cantidad de ERROR en últimas 24h (aproximado).
     */
    public function stats(Request $request)
    {
        $path = $this->logPath();

        if (! File::exists($path)) {
            return response()->json([
                'exists' => false,
                'size_bytes' => 0,
                'modified_at' => null,
            ]);
        }

        $size = File::size($path);
        $modified = date('c', File::lastModified($path));

        $content = '';
        try {
            $content = File::get($path);
        } catch (\Throwable $e) {
            // ignore
        }

        $errorCount = substr_count($content, '.ERROR:');
        $warningCount = substr_count($content, '.WARNING:');

        return response()->json([
            'exists' => true,
            'size_bytes' => $size,
            'size_human' => $this->humanSize($size),
            'modified_at' => $modified,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
        ]);
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
