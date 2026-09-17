-- Migration 011 : lots, FIFO, unites de vente et variantes
-- Executer apres une sauvegarde et apres 010_inventaires.sql.

ALTER TABLE produits
    ADD COLUMN IF NOT EXISTS unite_mesure ENUM('piece','kg','litre','carton') NOT NULL DEFAULT 'piece' AFTER codebarre,
    ADD COLUMN IF NOT EXISTS mode_vente ENUM('unite','poids','volume') NOT NULL DEFAULT 'unite' AFTER unite_mesure,
    ADD COLUMN IF NOT EXISTS produit_parent_id INT(11) DEFAULT NULL AFTER categorie_id;

ALTER TABLE produits
    MODIFY quantite DECIMAL(12,3) NOT NULL DEFAULT 0.000;

ALTER TABLE ligne_ventes
    MODIFY quantite DECIMAL(12,3) NOT NULL;

ALTER TABLE ligne_commandes
    MODIFY quantite DECIMAL(12,3) NOT NULL;

ALTER TABLE stock_mouvements
    MODIFY quantite DECIMAL(12,3) NOT NULL,
    MODIFY ancien_stock DECIMAL(12,3) NOT NULL,
    MODIFY nouveau_stock DECIMAL(12,3) NOT NULL;

CREATE TABLE IF NOT EXISTS lots_produits (
    id INT(11) NOT NULL AUTO_INCREMENT,
    produit_id INT(11) NOT NULL,
    magasin_id INT(11) NOT NULL,
    numero_lot VARCHAR(100) DEFAULT NULL,
    quantite_initiale DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    quantite_restante DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    prix_achat DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    date_reception DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_expiration DATE DEFAULT NULL,
    statut ENUM('actif','epuise','bloque','expire') NOT NULL DEFAULT 'actif',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lots_fifo (produit_id, magasin_id, statut, date_expiration, date_reception),
    KEY idx_lots_numero (numero_lot),
    CONSTRAINT fk_lots_produit FOREIGN KEY (produit_id) REFERENCES produits(id) ON DELETE CASCADE,
    CONSTRAINT fk_lots_magasin FOREIGN KEY (magasin_id) REFERENCES magasins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ligne_vente_lots (
    id INT(11) NOT NULL AUTO_INCREMENT,
    ligne_vente_id INT(11) NOT NULL,
    lot_id INT(11) NOT NULL,
    quantite DECIMAL(12,3) NOT NULL,
    prix_achat DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    KEY idx_ligne_vente_lots_ligne (ligne_vente_id),
    KEY idx_ligne_vente_lots_lot (lot_id),
    CONSTRAINT fk_ligne_vente_lots_ligne FOREIGN KEY (ligne_vente_id) REFERENCES ligne_ventes(id) ON DELETE CASCADE,
    CONSTRAINT fk_ligne_vente_lots_lot FOREIGN KEY (lot_id) REFERENCES lots_produits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS variantes_produits (
    id INT(11) NOT NULL AUTO_INCREMENT,
    produit_parent_id INT(11) NOT NULL,
    nom VARCHAR(150) NOT NULL,
    codebarre VARCHAR(100) NOT NULL,
    valeur VARCHAR(100) DEFAULT NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_variante_codebarre (codebarre),
    KEY idx_variantes_parent (produit_parent_id),
    CONSTRAINT fk_variantes_parent FOREIGN KEY (produit_parent_id) REFERENCES produits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO lots_produits (produit_id, magasin_id, numero_lot, quantite_initiale, quantite_restante, prix_achat, date_expiration, date_reception)
SELECT p.id, p.magasin_id, CONCAT('LEGACY-', p.id), p.quantite, p.quantite, p.prix_achat, p.date_peremption, COALESCE(p.created_at, NOW())
FROM produits p
LEFT JOIN lots_produits l ON l.produit_id=p.id AND l.magasin_id=p.magasin_id
WHERE l.id IS NULL AND p.quantite > 0;

ALTER TABLE produits
    ADD KEY IF NOT EXISTS idx_produits_parent (produit_parent_id),
    ADD KEY IF NOT EXISTS idx_produits_unite (unite_mesure, mode_vente);
