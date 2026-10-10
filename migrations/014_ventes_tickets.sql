-- Migration 014 : references de tickets et compatibilite des statuts de vente.
-- A executer apres une sauvegarde de la base.

ALTER TABLE ventes
  ADD COLUMN IF NOT EXISTS numero_ticket varchar(40) NULL AFTER id;

UPDATE ventes
SET numero_ticket = CONCAT(
  'TK-',
  DATE_FORMAT(date_vente, '%Y%m%d%H%i%s'),
  '-',
  LPAD(id, 8, '0')
)
WHERE numero_ticket IS NULL OR numero_ticket = '';

ALTER TABLE ventes
  MODIFY numero_ticket varchar(40) NOT NULL,
  ADD UNIQUE KEY IF NOT EXISTS uq_ventes_numero_ticket (numero_ticket);

ALTER TABLE ventes
  ADD COLUMN IF NOT EXISTS statut varchar(50) NOT NULL DEFAULT 'validee' AFTER copies_imprimees;

ALTER TABLE ventes
  MODIFY statut varchar(50) NOT NULL DEFAULT 'validee';

ALTER TABLE ventes
  ADD COLUMN IF NOT EXISTS annule_par int(11) NULL,
  ADD COLUMN IF NOT EXISTS date_annulation datetime NULL;
