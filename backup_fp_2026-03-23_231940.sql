-- ============================================================
-- BACKUP huellas digitales — generado 2026-03-23_231940
-- Para restaurar: ejecuta este SQL en gymflow_db
-- ============================================================

-- Primero limpia los extras huerfanos del periodo de prueba
DELETE FROM fingerprint_extra_templates WHERE client_id IN (5);

-- Cliente id=5: Pablo Andres Santos Gonzalez
-- Template en archivo: backup_fp_template_client5.b64
UPDATE clients SET
  fingerprint_id            = 'FP-5-1773931100-PEFKgNU2',
  fingerprint_template      = LOAD_FILE('/path/to/backup_fp_template_client5.b64'),
  fingerprint_device_id     = 'default',
  fingerprint_quality       = NULL,
  fingerprint_registered_at = '2026-03-19 14:38:20'
WHERE id = 5;

-- Verificar restauración
SELECT id, first_name, last_name, fingerprint_id, fingerprint_registered_at
FROM clients WHERE fingerprint_id IS NOT NULL;