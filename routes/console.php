<?php

use App\Jobs\ReactivarSuscripcionesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── 00:01 — Reactivar suscripciones y procesar pausas vencidas ──────────
// El más crítico: reactiva Recurrente cuando vencen los meses adelantados
Schedule::job(new ReactivarSuscripcionesJob)
    ->dailyAt('00:01')
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::critical('[Schedule] ReactivarSuscripcionesJob FALLÓ al ejecutarse. Revisar /admin/memberships/risk');
    })
    ->appendOutputTo(storage_path('logs/reactivar-suscripciones.log'));

// ─── 03:00 — Reconciliar suscripciones con Recurrente ───────────────────
Schedule::command('recurrente:sync-subscriptions --grace-days=5')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/recurrente-sync.log'));

// ─── 06:00 — Conciliación de cobros duplicados ──────────────────────────
Schedule::command('recurrente:conciliar --days=7')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/recurrente-conciliacion.log'));
