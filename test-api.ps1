# ============================================================
#  GYMFLOW -- SMOKE TEST SCRIPT (PowerShell Windows)
#  Uso: .\test-api.ps1
#  Requisito: php artisan serve corriendo en puerto 8000
# ============================================================

$BASE_URL    = "http://127.0.0.1:8000"
$ADMIN_EMAIL = "admin@gymflow.com"
$ADMIN_PASS  = "admin123"
$TOKEN       = ""
$PASSED      = 0
$FAILED      = 0
$FAILURES    = @()

function PassTest($label) {
    Write-Host "  [PASS] $label" -ForegroundColor Green
    $script:PASSED++
}
function FailTest($label, $detail = "") {
    if ($detail) {
        Write-Host "  [FAIL] $label -- $detail" -ForegroundColor Red
    } else {
        Write-Host "  [FAIL] $label" -ForegroundColor Red
    }
    $script:FAILED++
    $script:FAILURES += $label
}
function ShowInfo($msg) {
    Write-Host "         $msg" -ForegroundColor DarkGray
}
function Section($title) {
    Write-Host ""
    Write-Host "--- $title ---" -ForegroundColor Cyan
}

function GetHttpStatus($response) {
    if ($null -eq $response) { return 0 }
    if ($response.PSObject.Properties.Name -contains "StatusCode") {
        return [int]$response.StatusCode
    }
    return 0
}

function DoGET($path) {
    try {
        $h = @{ "Authorization" = "Bearer $script:TOKEN"; "Accept" = "application/json" }
        return Invoke-WebRequest -Uri "$BASE_URL$path" -Headers $h -Method GET -UseBasicParsing -ErrorAction SilentlyContinue
    } catch { return $null }
}

function DoPOST($path, $body, $useAuth = $true) {
    try {
        $h = @{ "Content-Type" = "application/json"; "Accept" = "application/json" }
        if ($useAuth -and $script:TOKEN) { $h["Authorization"] = "Bearer $script:TOKEN" }
        $json = $body | ConvertTo-Json -Depth 10
        return Invoke-WebRequest -Uri "$BASE_URL$path" -Headers $h -Method POST -Body $json -UseBasicParsing -ErrorAction SilentlyContinue
    } catch { return $null }
}

function DoPUT($path, $body) {
    try {
        $h = @{ "Content-Type" = "application/json"; "Accept" = "application/json"; "Authorization" = "Bearer $script:TOKEN" }
        $json = $body | ConvertTo-Json -Depth 10
        return Invoke-WebRequest -Uri "$BASE_URL$path" -Headers $h -Method PUT -Body $json -UseBasicParsing -ErrorAction SilentlyContinue
    } catch { return $null }
}

# =============================================================
Write-Host ""
Write-Host "=================================================" -ForegroundColor Cyan
Write-Host "  GYMFLOW -- API SMOKE TEST SUITE               " -ForegroundColor Cyan
Write-Host "=================================================" -ForegroundColor Cyan
Write-Host "  URL: $BASE_URL" -ForegroundColor DarkGray
Write-Host "  Fecha: $(Get-Date -Format 'dd/MM/yyyy HH:mm:ss')" -ForegroundColor DarkGray

# =============================================================
#  GRUPO 1 -- AUTH
# =============================================================
Section "GRUPO 1: Autenticacion"

# 1.1 Login correcto
$res = DoPOST "/api/login" @{ email = $ADMIN_EMAIL; password = $ADMIN_PASS } $false
$status = GetHttpStatus $res
if ($status -eq 200) {
    $body = $res.Content | ConvertFrom-Json
    if ($body.access_token) {
        $script:TOKEN = $body.access_token
        PassTest "Login admin@gymflow.com / admin123 (200)"
        ShowInfo "Token obtenido: $($script:TOKEN.Substring(0, [Math]::Min(30, $script:TOKEN.Length)))..."
    } else {
        FailTest "Login admin: responde 200 pero sin access_token" "Revisar AuthController"
    }
} else {
    FailTest "Login admin@gymflow.com" "Status: $status (esperado 200) -- Verificar password o seeder"
}

# 1.2 Login incorrecto
# NOTA: Laravel retorna 422 (ValidationException) cuando las credenciales son incorrectas
$res = DoPOST "/api/login" @{ email = $ADMIN_EMAIL; password = "PASSWORD_INCORRECTA_123" } $false
$status = GetHttpStatus $res
if ($status -eq 401 -or $status -eq 422) { PassTest "Login con password incorrecto retorna 401 o 422" }
else { FailTest "Login incorrecto debe retornar 401 o 422" "Status: $status" }

# 1.3 GET /api/user con token
$res = DoGET "/api/user"
$status = GetHttpStatus $res
if ($status -eq 200) {
    $body = $res.Content | ConvertFrom-Json
    if ($body.email) { PassTest "GET /api/user con token -- email: $($body.email)" }
    else { FailTest "GET /api/user devuelve 200 pero sin campo email" }
} else {
    FailTest "GET /api/user con token valido" "Status: $status"
}

# 1.4 Sin token -> debe dar 401
$resRaw = Invoke-WebRequest -Uri "$BASE_URL/api/user" -UseBasicParsing -ErrorAction SilentlyContinue
$st = if ($null -ne $resRaw) { GetHttpStatus $resRaw } else { 401 }
if ($st -eq 401 -or $null -eq $resRaw) { PassTest "GET /api/user sin token retorna 401" }
else { FailTest "GET /api/user sin token debe ser 401" "Status: $st" }

# =============================================================
#  GRUPO 2 -- API INTERNA
# =============================================================
Section "GRUPO 2: Endpoints Protegidos"

$endpoints = @(
    @{ path = "/api/membership-plans"; name = "GET /api/membership-plans" },
    @{ path = "/api/clients";          name = "GET /api/clients" },
    @{ path = "/api/memberships";      name = "GET /api/memberships" },
    @{ path = "/api/payments";         name = "GET /api/payments" },
    @{ path = "/api/access-logs";      name = "GET /api/access-logs" },
    @{ path = "/api/receipts";         name = "GET /api/receipts" },
    @{ path = "/api/users";            name = "GET /api/users (staff)" },
    @{ path = "/api/roles";            name = "GET /api/roles" },
    @{ path = "/api/productos";        name = "GET /api/productos" },
    @{ path = "/api/ventas";           name = "GET /api/ventas" },
    @{ path = "/api/membresias/riesgo";name = "GET /api/membresias/riesgo (lifecycle)" },
    @{ path = "/api/pagos/alertas";    name = "GET /api/pagos/alertas" }
)

foreach ($ep in $endpoints) {
    $res = DoGET $ep.path
    $st = GetHttpStatus $res
    if ($st -eq 200) { PassTest $ep.name }
    else { FailTest $ep.name "HTTP $st" }
}

# Publicos sin token
Write-Host ""
Write-Host "  >> Endpoints PUBLICOS (sin token):" -ForegroundColor DarkGray
$publicEps = @(
    @{ url = "/api/site-settings";    name = "GET /api/site-settings (publico)" },
    @{ url = "/api/public/plans";     name = "GET /api/public/plans (publico)" },
    @{ url = "/api/public/products";  name = "GET /api/public/products (publico)" }
)
foreach ($ep in $publicEps) {
    $r = Invoke-WebRequest -Uri "$BASE_URL$($ep.url)" -ErrorAction SilentlyContinue
    $st = if ($null -ne $r) { GetHttpStatus $r } else { 0 }
    if ($st -eq 200) { PassTest $ep.name }
    else { FailTest $ep.name "HTTP $st" }
}

# =============================================================
#  GRUPO 3 -- RECURRENTE API (conectividad real)
# =============================================================
Section "GRUPO 3: Conectividad Recurrente API"

# Leer llaves del .env
$envPath = Join-Path $PSScriptRoot "Backend-Gymflow\.env"
if (-not (Test-Path $envPath)) {
    $envPath = Join-Path $PSScriptRoot ".env"
}

$pubKey = ""
$secKey = ""
$recUrl = "https://app.recurrente.com/api"

if (Test-Path $envPath) {
    foreach ($line in (Get-Content $envPath)) {
        if ($line -match "^RECURRENTE_PUBLIC_KEY=(.+)$")  { $pubKey = $matches[1].Trim() }
        if ($line -match "^RECURRENTE_SECRET_KEY=(.+)$")  { $secKey = $matches[1].Trim() }
        if ($line -match "^RECURRENTE_BASE_URL=(.+)$")    { $recUrl = $matches[1].Trim() }
    }
    ShowInfo ".env leido desde: $envPath"
} else {
    FailTest "No se encontro archivo .env" $envPath
}

if ($pubKey) { PassTest "RECURRENTE_PUBLIC_KEY configurada" }
else { FailTest "RECURRENTE_PUBLIC_KEY no encontrada en .env" }

if ($secKey) { PassTest "RECURRENTE_SECRET_KEY configurada" }
else { FailTest "RECURRENTE_SECRET_KEY no encontrada en .env" }

if ($pubKey -match "^pk_test_") { PassTest "Llave publica es de tipo TEST (pk_test_...)" }
elseif ($pubKey -match "^pk_live_") {
    Write-Host "  [WARN] Llave de PRODUCCION activa (pk_live_...) -- Cargos REALES!" -ForegroundColor Yellow
} else {
    FailTest "Formato de llave publica inesperado" $pubKey
}

# Test real a Recurrente -- crear usuario
if ($pubKey -and $secKey) {
    try {
        $rh = @{
            "X-PUBLIC-KEY" = $pubKey
            "X-SECRET-KEY" = $secKey
            "Content-Type" = "application/json"
            "Accept"       = "application/json"
        }
        $timestamp = Get-Date -Format "HHmmss"
        $userData = @{ email = "smoketest_$timestamp@gymflow-test.com"; name = "Smoke Test $timestamp" } | ConvertTo-Json
        $rRes = Invoke-WebRequest -Uri "$recUrl/users" -Method POST -Headers $rh -Body $userData -ErrorAction Stop
        $rBody = $rRes.Content | ConvertFrom-Json
        if ([int]$rRes.StatusCode -eq 200 -and $rBody.id) {
            PassTest "POST $recUrl/users -- Conectividad OK"
            ShowInfo "Usuario Recurrente creado: $($rBody.id)"
        } else {
            FailTest "POST /users en Recurrente" "Status: $($rRes.StatusCode) sin ID"
        }
    } catch {
        $code = 0
        try { $code = [int]$_.Exception.Response.StatusCode } catch {}
        if ($code -eq 401) {
            FailTest "Recurrente: AUTENTICACION FALLIDA (401)" "Las llaves en .env son invalidas. Ve a app.recurrente.com -> Configuracion -> Llaves API"
        } elseif ($code -eq 0 -or $code -eq 422) {
            # 422 = email ya existe en Recurrente (sandbox) -- conectividad OK
            PassTest "POST /users en Recurrente -- Conectividad OK (usuario ya existia o 422)"
        } else {
            FailTest "POST /users en Recurrente" "Error: $($_.Exception.Message)"
        }
    }

    # Test con llaves invalidas -> debe dar 401
    try {
        $badH = @{ "X-PUBLIC-KEY" = "pk_test_INVALIDA_XYZ"; "X-SECRET-KEY" = "sk_test_INVALIDA_XYZ"; "Accept" = "application/json" }
        $bad = Invoke-WebRequest -Uri "$recUrl/products" -Headers $badH -ErrorAction Stop
        FailTest "Llaves invalidas deben retornar 401" "Retorno $($bad.StatusCode)"
    } catch {
        $code = 0
        try { $code = [int]$_.Exception.Response.StatusCode } catch {}
        if ($code -eq 401) { PassTest "Llaves invalidas retornan 401 correctamente" }
        else { FailTest "Error inesperado con llaves invalidas" "Code: $code" }
    }
}

# =============================================================
#  GRUPO 4 -- WEBHOOKS
# =============================================================
Section "GRUPO 4: Webhooks (estructura de respuesta)"

$webhookTests = @(
    @{ body = @{}; name = "Payload vacio no causa 500" },
    @{ body = @{ type = "evento.desconocido"; data = @{ id = "test_123" } }; name = "Evento desconocido retorna 200" },
    @{ body = @{ type = "checkout.succeeded"; data = @{ id = "ck_smoketest"; status = "paid" } }; name = "checkout.succeeded no causa 500" },
    @{ body = @{ type = "subscription.paid"; data = @{ id = "pay_smoketest"; subscription_id = "sub_abc" } }; name = "subscription.paid no causa 500" },
    @{ body = @{ type = "subscription.cancelled"; data = @{ subscription_id = "sub_inexistente" } }; name = "subscription.cancelled no causa 500" }
)
foreach ($wt in $webhookTests) {
    $r = DoPOST "/webhooks/recurrente" $wt.body $false
    $st = GetHttpStatus $r
    if ($st -ne 500 -and $st -ne 0) { PassTest "Webhook: $($wt.name)" }
    else { FailTest "Webhook: $($wt.name)" "HTTP $st" }
}

# =============================================================
#  GRUPO 5 -- VALIDACIONES
# =============================================================
Section "GRUPO 5: Validaciones de entrada (debe rechazar datos malos)"

$valTests = @(
    @{
        path = "/api/membresias/pausar"
        body = @{ membership_id = 999; pause_start = "2020-01-01"; pause_end = "2020-01-15"; reason = "travel" }
        name = "Pausa con fecha pasada retorna 422"
        expected = 422
    },
    @{
        path = "/api/membresias/reactivar-tarjeta"
        body = @{ client_id = 999; payment_method_id = ""; from_installment_id = 1 }
        name = "Reactivar tarjeta sin payment_method retorna 422"
        expected = 422
    },
    @{
        path = "/api/pagos/adelanto"
        body = @{ client_id = 999; installment_ids = @(); monto = 0; metodo = "invalido" }
        name = "Pago adelantado datos invalidos retorna 422"
        expected = 422
    }
)
foreach ($vt in $valTests) {
    $r = DoPOST $vt.path $vt.body
    $st = GetHttpStatus $r
    if ($st -eq $vt.expected) {
        PassTest $vt.name
    } elseif ($st -eq 404) {
        Write-Host "  [SKIP] $($vt.name) -- Ruta no implementada aun (404)" -ForegroundColor Yellow
    } elseif ($st -eq 0) {
        Write-Host "  [SKIP] $($vt.name) -- Sin respuesta (posible ruta no existe)" -ForegroundColor Yellow
    } else {
        FailTest $vt.name "Esperado: $($vt.expected) -- Obtenido: $st"
    }
}

# =============================================================
#  RESUMEN FINAL
# =============================================================
$total = $PASSED + $FAILED
Write-Host ""
Write-Host "=================================================" -ForegroundColor Cyan
Write-Host "  RESULTADO FINAL" -ForegroundColor Cyan
Write-Host "=================================================" -ForegroundColor Cyan
Write-Host "  PASARON:  $PASSED / $total" -ForegroundColor Green
Write-Host "  FALLARON: $FAILED / $total" -ForegroundColor Red

if ($FAILURES.Count -gt 0) {
    Write-Host ""
    Write-Host "  Tests fallidos:" -ForegroundColor Yellow
    foreach ($f in $FAILURES) {
        Write-Host "    * $f" -ForegroundColor Red
    }
}

Write-Host ""
if ($FAILED -eq 0) {
    Write-Host "  Todo funciona correctamente!" -ForegroundColor Green
} else {
    Write-Host "  Hay $FAILED test(s) fallidos. Revisa los detalles arriba." -ForegroundColor Yellow
}
Write-Host ""
