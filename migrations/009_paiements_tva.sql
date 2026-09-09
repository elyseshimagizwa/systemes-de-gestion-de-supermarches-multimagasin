CREATE TABLE IF NOT EXISTS paiements_tva (
  id int NOT NULL AUTO_INCREMENT,
  magasin_id int NOT NULL,
  mois char(7) NOT NULL,
  montant decimal(12,2) NOT NULL,
  paye_par int DEFAULT NULL,
  date_paiement timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_tva_magasin_mois (magasin_id, mois),
  KEY idx_paiements_tva_magasin (magasin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
