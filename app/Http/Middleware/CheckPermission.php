<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para verificar que el usuario autenticado tenga
 * uno o más permisos (OR — basta con tener al menos uno).
 *
 * Uso en rutas:
 *   ->middleware('permission:CLIENTS_VIEW')
 *   ->middleware('permission:CLIENTS_EDIT,CLIENTS_DELETE')   // OR
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        // Si el usuario no tiene rol, denegar
        if (!$user->role) {
            return response()->json(['message' => 'No tienes un rol asignado.'], 403);
        }

        // Cargar permisos si no están cargados
        if (!$user->relationLoaded('role') || !$user->role->relationLoaded('permissions')) {
            $user->load('role.permissions');
        }

        $userPermissions = $user->role->permissions->pluck('slug')->toArray();

        // Verificar si el usuario tiene AL MENOS uno de los permisos requeridos
        foreach ($permissions as $permission) {
            if (in_array($permission, $userPermissions)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'No tienes permisos para realizar esta acción.',
            'required_permissions' => $permissions,
        ], 403);
    }
}
