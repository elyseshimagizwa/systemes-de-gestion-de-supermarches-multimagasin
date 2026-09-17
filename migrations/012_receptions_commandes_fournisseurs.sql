CREATE TABLE IF NOT EXISTS receptions_fournisseurs (
    id INT NOT NULL AUTO_INCREMENT,
    commande_id INT NOT NULL,
    magasin_id INT NOT NULL,
    utilisateur_id INT NOT NULL,
    numero_bon VARCHAR(40) NOT NULL,
    commentaire TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reception_numero_bon (numero_bon),
    KEY idx_receptions_commande (commande_id),
    KEY idx_receptions_magasin (magasin_id),
    CONSTRAINT fk_reception_commande FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE RESTRICT,
    CONSTRAINT fk_reception_magasin FOREIGN KEY (magasin_id) REFERENCES magasins (id) ON DELETE RESTRICT,
    CONSTRAINT fk_reception_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lignes_receptions_fournisseurs (
    id INT NOT NULL AUTO_INCREMENT,
    reception_id INT NOT NULL,
    ligne_commande_id INT NOT NULL,
    quantite_recue DECIMAL(12,3) NOT NULL DEFAULT 0,
    quantite_endommagee DECIMAL(12,3) NOT NULL DEFAULT 0,
    quantite_manquante DECIMAL(12,3) NOT NULL DEFAULT 0,
    prix_prevu DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    prix_recu DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reception_ligne (reception_id, ligne_commande_id),
    KEY idx_lignes_receptions_commande (ligne_commande_id),
    CONSTRAINT fk_ligne_reception FOREIGN KEY (reception_id) REFERENCES receptions_fournisseurs (id) ON DELETE CASCADE,
    CONSTRAINT fk_ligne_reception_commande FOREIGN KEY (ligne_commande_id) REFERENCES ligne_commandes (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
