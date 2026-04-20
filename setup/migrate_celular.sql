-- Migración: telefono → celular + agregar correo antes de celular
-- Ejecutar una sola vez sobre la BD existente

-- Tabla clientes: renombrar telefono → celular y mover correo antes de celular
ALTER TABLE clientes
  CHANGE telefono celular VARCHAR(40) DEFAULT '',
  MODIFY correo VARCHAR(150) DEFAULT NULL AFTER nombre;

-- Tabla pedidos: renombrar telefono → celular y agregar correo antes de celular
ALTER TABLE pedidos
  CHANGE telefono celular VARCHAR(40) DEFAULT '',
  ADD COLUMN IF NOT EXISTS correo VARCHAR(120) DEFAULT NULL AFTER cliente;

-- Reordenar celular para que quede después de correo en pedidos
ALTER TABLE pedidos
  MODIFY celular VARCHAR(40) DEFAULT '' AFTER correo;
