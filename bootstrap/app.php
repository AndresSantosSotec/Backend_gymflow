<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Alias para middleware de permisos
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);

        // Excluir el endpoint de webhooks de Recurrente del CSRF
        // (Recurrente hace POST sin token de sesión)
        $middleware->validateCsrfTokens(except: [
            'webhooks/recurrente',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
