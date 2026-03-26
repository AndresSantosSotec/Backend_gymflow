-- ============================================================
-- fix_fingerprints_reenroll.sql
-- Borra los registros de huella de los clientes que sólo
-- tienen 1 scan (el antiguo), para que puedan re-registrarse
-- con el nuevo flujo de 4 scans.
--
-- Ejecutar en producción ANTES de pedir a los clientes que
-- vuelvan a registrar su huella desde el panel de admin.
--
-- SEGURO: no borra al cliente, sólo limpia sus campos de huella.
-- ============================================================

-- Ver estado antes de limpiar
SELECT id, first_name, last_name, fingerprint_id, fingerprint_quality
FROM clients
WHERE fingerprint_id IS NOT NULL;

SELECT COUNT(*) AS extras_actuales FROM fingerprint_extra_templates;

-- Limpiar extras (por si hubiera alguno inconsistente)
DELETE fet FROM fingerprint_extra_templates fet
JOIN clients c ON c.id = fet.client_id
WHERE c.fingerprint_id IS NOT NULL;

-- Resetear campos de huella en clients
UPDATE clients
SET fingerprint_id            = NULL,
    fingerprint_template      = NULL,
    fingerprint_device_id     = NULL,
    fingerprint_quality       = NULL,
    fingerprint_registered_at = NULL
WHERE fingerprint_id IS NOT NULL;

-- Verificar resultado
SELECT id, first_name, last_name, fingerprint_id
FROM clients
WHERE id IN (1, 5, 7);  -- ajusta los IDs según los clientes afectados

SELECT COUNT(*) AS clientes_con_huella FROM clients WHERE fingerprint_id IS NOT NULL;
SELECT COUNT(*) AS extras              FROM fingerprint_extra_templates;
