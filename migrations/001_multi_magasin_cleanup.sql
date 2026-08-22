-- Migration 001: normalisation multi-magasins et nettoyage des donnees
-- Executer apres une sauvegarde complete de la base.

SET @default_magasin_id := (
    SELECT id FROM magasins
    WHERE statut = 'actif'
    ORDER BY id
    LIMIT 1
);

-- Sauvegarde locale avant normalisation. Les tables sont conservees si la migration est relancee.
CREATE TABLE IF NOT EXISTS migration_backup_commandes_001 AS SELECT * FROM commandes;
CREATE TABLE IF NOT EXISTS migration_backup_produits_001 AS SELECT * FROM produits;
CREATE TABLE IF NOT EXISTS migration_backup_fournisseurs_001 AS SELECT * FROM fournisseurs;
CREATE TABLE IF NOT EXISTS migration_backup_stock_mouvements_001 AS SELECT * FROM stock_mouvements;
CREATE TABLE IF NOT EXISTS migration_backup_sessions_caisse_001 AS SELECT * FROM sessions_caisse;
CREATE TABLE IF NOT EXISTS migration_backup_ventes_001 AS SELECT * FROM ventes;

-- Valeurs de statut absentes ou vides.
UPDATE magasins SET statut = 'actif' WHERE statut IS NULL OR statut = '';
UPDATE connexions_utilisateurs SET statut = 'offline' WHERE statut IS NULL OR statut = '';
UPDATE sessions_caisse SET statut_validation = 'attente'
WHERE statut_validation IS NULL OR statut_validation = '';

-- Rattachement des anciennes lignes sans magasin a l'agence active principale.
UPDATE fournisseurs
SET magasin_id = @default_magasin_id
WHERE magasin_id IS NULL OR magasin_id <= 0;

UPDATE produits
SET magasin_id = @default_magasin_id
WHERE magasin_id IS NULL OR magasin_id <= 0;

UPDATE categories
SET magasin_id = @default_magasin_id
WHERE magasin_id IS NULL OR magasin_id <= 0;

UPDATE stock_mouvements
SET magasin_id = @default_magasin_id
WHERE magasin_id IS NULL OR magasin_id <= 0;

UPDATE sessions_caisse
SET magasin_id = @default_magasin_id
WHERE magasin_id IS NULL OR magasin_id <= 0;

UPDATE ventes
SET magasin_id = @default_magasin_id
WHERE magasin_id IS NULL OR magasin_id <= 0;

UPDATE transactions_financieres
SET magasin_id = @default_magasin_id
WHERE magasin_id IS NULL OR magasin_id <= 0;

UPDATE historiques
SET magasin_id = @default_magasin_id
WHERE magasin_id IS NULL
  AND action NOT IN ('LOGIN', 'LOGOUT', 'TIMEOUT');

-- Les anciennes ventes sans session sont conservees, mais leur lien devient explicitement nullable.
ALTER TABLE ventes MODIFY session_caisse_id INT(11) NULL;
UPDATE ventes SET session_caisse_id = NULL WHERE session_caisse_id = 0;

-- Les commandes deviennent rattachees a un magasin et a leur createur.
ALTER TABLE commandes
    ADD COLUMN IF NOT EXISTS magasin_id INT(11) NULL AFTER fournisseur_id,
    ADD COLUMN IF NOT EXISTS utilisateur_id INT(11) NULL AFTER magasin_id;

UPDATE commandes c
LEFT JOIN fournisseurs f ON f.id = c.fournisseur_id
SET c.magasin_id = COALESCE(f.magasin_id, @default_magasin_id)
WHERE c.magasin_id IS NULL OR c.magasin_id <= 0;

-- Chaque recette ou depense est rattachee a la session de caisse qui l'a saisie.
ALTER TABLE transactions_financieres
    ADD COLUMN IF NOT EXISTS session_caisse_id INT(11) NULL AFTER magasin_id;

-- Alignement des valeurs acceptees par les ecritures PHP.
ALTER TABLE stock_mouvements
MODIFY type ENUM(
    'entree',
    'sortie',
    'entree_commande',
    'sortie_vente',
    'transfert_entree',
    'transfert_sortie',
    'retour_client',
    'perte',
    'inventaire_correctif'
) NOT NULL;

UPDATE stock_mouvements
SET type = 'inventaire_correctif'
WHERE type IS NULL OR type = '';

ALTER TABLE ventes
MODIFY mode_paiement ENUM(
    'Espèces',
    'Carte',
    'Mobile Money',
    'Orange Money',
    'Wave',
    'Chèque'
) NOT NULL;

-- Nettoyage des valeurs invalides sans suppression d'historique.
UPDATE produits SET quantite = 0 WHERE quantite IS NULL OR quantite < 0;
UPDATE produits SET seuil_alerte = 0 WHERE seuil_alerte IS NULL OR seuil_alerte < 0;
UPDATE produits SET prix_achat = 0 WHERE prix_achat IS NULL OR prix_achat < 0;
UPDATE produits SET prix_vente = 0 WHERE prix_vente IS NULL OR prix_vente < 0;
UPDATE ligne_commandes SET quantite = 0 WHERE quantite IS NULL OR quantite < 0;
UPDATE ligne_ventes SET quantite = 0 WHERE quantite IS NULL OR quantite < 0;
UPDATE retours SET quantite = 0 WHERE quantite IS NULL OR quantite < 0;

-- Index utiles pour les filtrages par magasin. Les doublons existants ne sont pas supprimes.
ALTER TABLE ventes ADD INDEX IF NOT EXISTS idx_ventes_magasin_date (magasin_id, date_vente);
ALTER TABLE produits ADD INDEX IF NOT EXISTS idx_produits_magasin (magasin_id);
ALTER TABLE stock_mouvements ADD INDEX IF NOT EXISTS idx_mouvements_magasin_date (magasin_id, date_mouvement);
ALTER TABLE historiques ADD INDEX IF NOT EXISTS idx_historiques_magasin_date (magasin_id, created_at);
ALTER TABLE commandes ADD INDEX IF NOT EXISTS idx_commandes_magasin (magasin_id);
