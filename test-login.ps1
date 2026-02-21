$body = @{ email = "admin@gymflow.com"; password = "admin123" } | ConvertTo-Json

Write-Host "Probando login en http://127.0.0.1:8000/api/login ..."

try {
    $r = Invoke-RestMethod `
        -Uri "http://127.0.0.1:8000/api/login" `
        -Method POST `
        -ContentType "application/json" `
        -Body $body `
        -ErrorAction Stop

    Write-Host "LOGIN OK" -ForegroundColor Green
    Write-Host "Token (primeros 40 chars): $($r.token.Substring(0, [Math]::Min(40, $r.token.Length)))"
    Write-Host "Usuario: $($r.user.email)"

    # Test GET /api/user con el token
    $token = $r.token
    $headers = @{ Authorization = "Bearer $token"; Accept = "application/json" }
    $user = Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/user" -Headers $headers -UseBasicParsing

    Write-Host "GET /api/user OK: $($user.name)" -ForegroundColor Green

} catch {
    Write-Host "ERROR en login:" -ForegroundColor Red
    Write-Host $_.Exception.Message
    Write-Host "Detalle HTTP:" -ForegroundColor Yellow
    Write-Host $_.ErrorDetails.Message
}
