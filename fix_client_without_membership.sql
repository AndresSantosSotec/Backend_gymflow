-- ======================================================================
-- SOLUCIÓN: Clientes sin membresía asignada
-- ======================================================================
-- Este archivo contiene consultas SQL para identificar y solucionar
-- el problema de clientes sin membresía asignada
-- ======================================================================

-- 1️⃣ IDENTIFICAR CLIENTES SIN MEMBRESÍA ACTIVA
-- ======================================================================
-- Esta consulta muestra todos los clientes que NO tienen ninguna membresía
SELECT
    c.id,
    c.first_name,
    c.last_name,
    c.email,
    c.phone,
    c.status,
    c.created_at
FROM clients c
LEFT JOIN memberships m ON c.id = m.client_id AND m.deleted_at IS NULL
WHERE m.id IS NULL
  AND c.deleted_at IS NULL
ORDER BY c.created_at DESC;


-- 2️⃣ IDENTIFICAR CLIENTES SIN MEMBRESÍA ACTIVA (solo las activas)
-- ======================================================================
-- Muestra clientes que no tienen membresías con status='active'
SELECT
    c.id,
    c.first_name,
    c.last_name,
    c.email,
    c.status,
    COUNT(m.id) as total_memberships,
    MAX(m.end_date) as last_membership_end
FROM clients c
LEFT JOIN memberships m ON c.id = m.client_id
    AND m.status = 'active'
    AND m.deleted_at IS NULL
WHERE c.deleted_at IS NULL
GROUP BY c.id
HAVING COUNT(m.id) = 0
ORDER BY c.created_at DESC;


-- 3️⃣ VER PLANES DE MEMBRESÍA DISPONIBLES
-- ======================================================================
-- Muestra los planes disponibles para asignar
SELECT
    id,
    name,
    slug,
    price,
    duration_days,
    published
FROM membership_plans
WHERE deleted_at IS NULL
  AND published = 1
ORDER BY price ASC;


-- 4️⃣ ASIGNAR MEMBRESÍA A UN CLIENTE ESPECÍFICO
-- ======================================================================
-- INSTRUCCIONES:
-- 1. Identifica el ID del cliente usando las consultas anteriores
-- 2. Elige un plan de membresía de la lista
-- 3. Reemplaza los valores en el INSERT a continuación
-- 4. Ejecuta el INSERT
--
-- EJEMPLO: Asignar membresía al cliente con ID = 1

-- Primero, obtén los datos del plan que quieres asignar
-- (cambiar el ID del plan según corresponda)
SELECT * FROM membership_plans WHERE id = 1;

-- Luego, inserta la membresía
-- IMPORTANTE: Ajusta estos valores según tu caso:
-- - client_id: ID del cliente
-- - name, description, price, duration_days: datos del plan elegido
-- - start_date: fecha de inicio (generalmente HOY)
-- - end_date: fecha de fin (start_date + duration_days)

INSERT INTO memberships (
    client_id,
    name,
    description,
    price,
    duration_days,
    start_date,
    end_date,
    status,
    auto_renew,
    created_at,
    updated_at
)
SELECT
    1 as client_id,                    -- ⚠️ CAMBIAR: ID del cliente
    mp.name,
    mp.description,
    mp.price,
    mp.duration_days,
    CURDATE() as start_date,
    DATE_ADD(CURDATE(), INTERVAL mp.duration_days DAY) as end_date,
    'active' as status,
    0 as auto_renew,
    NOW() as created_at,
    NOW() as updated_at
FROM membership_plans mp
WHERE mp.id = 1;                       -- ⚠️ CAMBIAR: ID del plan de membresía


-- 5️⃣ ASIGNAR MEMBRESÍA MANUALMENTE (sin usar un plan)
-- ======================================================================
-- Si prefieres crear la membresía manualmente sin basarte en un plan:

INSERT INTO memberships (
    client_id,
    name,
    description,
    price,
    duration_days,
    start_date,
    end_date,
    status,
    auto_renew,
    created_at,
    updated_at
) VALUES (
    1,                                 -- ⚠️ CAMBIAR: ID del cliente
    'Membresía Mensual',               -- Nombre de la membresía
    'Membresía asignada manualmente',  -- Descripción
    300.00,                            -- Precio en quetzales
    30,                                -- Duración en días
    CURDATE(),                         -- Fecha de inicio (hoy)
    DATE_ADD(CURDATE(), INTERVAL 30 DAY), -- Fecha de fin (30 días desde hoy)
    'active',                          -- Estado
    0,                                 -- Auto renovación (0=no, 1=sí)
    NOW(),
    NOW()
);


-- 6️⃣ ACTUALIZAR ESTADO DEL CLIENTE A 'ACTIVE'
-- ======================================================================
-- Si el cliente está inactivo o suspendido, activarlo:

UPDATE clients
SET status = 'active',
    updated_at = NOW()
WHERE id = 1                           -- ⚠️ CAMBIAR: ID del cliente
  AND status != 'active';


-- 7️⃣ VERIFICAR QUE LA MEMBRESÍA SE CREÓ CORRECTAMENTE
-- ======================================================================
-- Consulta para verificar la membresía recién creada:

SELECT
    c.id as client_id,
    c.first_name,
    c.last_name,
    c.email,
    c.status as client_status,
    m.id as membership_id,
    m.name as membership_name,
    m.price,
    m.start_date,
    m.end_date,
    m.status as membership_status,
    DATEDIFF(m.end_date, CURDATE()) as days_remaining
FROM clients c
JOIN memberships m ON c.id = m.client_id
WHERE c.id = 1                         -- ⚠️ CAMBIAR: ID del cliente
  AND m.deleted_at IS NULL
ORDER BY m.created_at DESC;


-- 8️⃣ ELIMINAR MEMBRESÍA DUPLICADA O INCORRECTA (CUIDADO)
-- ======================================================================
-- Si se creó una membresía por error y necesitas eliminarla:

-- Ver las membresías del cliente primero
SELECT * FROM memberships WHERE client_id = 1;

-- Soft delete (recomendado)
UPDATE memberships
SET deleted_at = NOW()
WHERE id = 123;                        -- ⚠️ CAMBIAR: ID de la membresía a eliminar

-- O hard delete (NO RECOMENDADO - solo si estás seguro)
-- DELETE FROM memberships WHERE id = 123;


-- ======================================================================
-- NOTAS IMPORTANTES:
-- ======================================================================
-- • Siempre haz un backup antes de modificar datos en producción
-- • Los valores marcados con ⚠️ deben ser ajustados según tu caso
-- • Las fechas se calculan automáticamente si usas las consultas con SELECT
-- • El soft delete (deleted_at) permite recuperar datos si es necesario
-- ======================================================================
