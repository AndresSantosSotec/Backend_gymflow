-- BACKUP producción huellas — 2026-03-23_172208
-- Total clientes: 1

-- id=5  Pablo Andres Santos Gonzalez  (template en backup_prod_fp_client5.b64)
UPDATE clients SET
  fingerprint_id            = 'FP-5-1773931100-PEFKgNU2',
  fingerprint_template      = LOAD_FILE('/ruta/backup_prod_fp_client5.b64'),
  fingerprint_quality       = NULL,
  fingerprint_registered_at = '2026-03-19 14:38:20'
WHERE id = 5;
