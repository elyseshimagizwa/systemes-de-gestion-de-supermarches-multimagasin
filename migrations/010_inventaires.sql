-- Migration 010 : sessions d'inventaire par magasin
-- Executer apres une sauvegarde complete de la base.

CREATE TABLE IF NOT EXISTS inventaires (
    id INT(11) NOT NULL AUTO_INCREMENT,
    reference VARCHAR(40) NOT NULL,
    magasin_id INT(11) NOT NULL,
    utilisateur_id INT(11) NOT NULL,
    validateur_id INT(11) DEFAULT NULL,
    statut ENUM('comptage','attente_validation','validee','annulee') NOT NULL DEFAULT 'comptage',
    notes TEXT DEFAULT NULL,
    date_ouverture DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_soumission DATETIME DEFAULT NULL,
    date_validation DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventaires_reference (reference),
    KEY idx_inventaires_magasin_statut (magasin_id, statut),
    KEY idx_inventaires_date (date_ouverture),
    CONSTRAINT fk_inventaires_magasin FOREIGN KEY (magasin_id) REFERENCES magasins(id),
    CONSTRAINT fk_inventaires_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id),
    CONSTRAINT fk_inventaires_validateur FOREIGN KEY (validateur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventaire_lignes (
    id INT(11) NOT NULL AUTO_INCREMENT,
    inventaire_id INT(11) NOT NULL,
    produit_id INT(11) NOT NULL,
    stock_theorique INT(11) NOT NULL,
    quantite_comptee INT(11) NOT NULL,
    ecart INT(11) NOT NULL,
    utilisateur_id INT(11) NOT NULL,
    compte_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventaire_produit (inventaire_id, produit_id),
    KEY idx_inventaire_lignes_produit (produit_id),
    CONSTRAINT fk_inventaire_lignes_inventaire FOREIGN KEY (inventaire_id) REFERENCES inventaires(id) ON DELETE CASCADE,
    CONSTRAINT fk_inventaire_lignes_produit FOREIGN KEY (produit_id) REFERENCES produits(id),
    CONSTRAINT fk_inventaire_lignes_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
