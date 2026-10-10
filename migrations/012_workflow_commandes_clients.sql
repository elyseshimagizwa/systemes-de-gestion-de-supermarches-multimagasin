-- Migration 012 : workflow des commandes clients (reservation, expiration, liberation)
-- A executer apres une sauvegarde. Compatible re-execution (IF NOT EXISTS).

-- 1. Tracabilite des mouvements de reservation et de liberation
ALTER TABLE stock_mouvements
  MODIFY type ENUM('entree','sortie','entree_commande','sortie_vente','transfert_entree','transfert_sortie','retour_client','perte','inventaire_correctif','reservation','liberation') NOT NULL;

-- 2. Stock reserve par produit et decimales (rattrape la migration 011 non appliquee)
ALTER TABLE produits
  MODIFY quantite DECIMAL(12,3) NOT NULL DEFAULT 0.000;

ALTER TABLE produits
  ADD COLUMN IF NOT EXISTS quantite_reservee DECIMAL(12,3) NOT NULL DEFAULT 0.000 AFTER quantite;

ALTER TABLE stock_mouvements
  MODIFY quantite DECIMAL(12,3) NOT NULL,
  MODIFY ancien_stock DECIMAL(12,3) NOT NULL,
  MODIFY nouveau_stock DECIMAL(12,3) NOT NULL;

-- 3. Expiration et liberation unique par commande
ALTER TABLE commandes_clients
  ADD COLUMN IF NOT EXISTS date_expiration DATETIME NULL AFTER date_annulation,
  ADD COLUMN IF NOT EXISTS stock_libere TINYINT(1) NOT NULL DEFAULT 0 AFTER date_expiration;

ALTER TABLE commandes_clients
  ADD KEY IF NOT EXISTS idx_commandes_clients_expiration (statut, date_expiration);

-- 4. Delai de retrait configurable (heures)
ALTER TABLE settings
  ADD COLUMN IF NOT EXISTS delai_retrait_heures INT(11) NOT NULL DEFAULT 48;

-- 5. Reconciliation : reserver le stock des commandes actives existantes
CREATE TABLE IF NOT EXISTS migration_backup_commandes_clients_012 AS SELECT * FROM commandes_clients;
CREATE TABLE IF NOT EXISTS migration_backup_produits_012 AS SELECT * FROM produits;
CREATE TABLE IF NOT EXISTS migration_backup_stock_mouvements_012 AS SELECT * FROM stock_mouvements;
CREATE TABLE IF NOT EXISTS migration_backup_settings_012 AS SELECT * FROM settings;

-- Les anciennes commandes ont deja debite le stock disponible.
-- Ne pas additionner leurs lignes a quantite_reservee lors d'une migration.
-- Leur reprise necessite un traitement metier explicite et une tracabilite des lots.

