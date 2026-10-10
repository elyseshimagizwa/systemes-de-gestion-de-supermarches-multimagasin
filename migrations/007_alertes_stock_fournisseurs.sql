CREATE TABLE IF NOT EXISTS alertes_stock_fournisseurs (
  id int(11) NOT NULL AUTO_INCREMENT,
  produit_id int(11) NOT NULL,
  magasin_id int(11) NOT NULL,
  fournisseur_id int(11) DEFAULT NULL,
  dernier_envoi datetime DEFAULT NULL,
  tentatives int(11) NOT NULL DEFAULT 0,
  derniere_erreur text DEFAULT NULL,
  resolue tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_alerte_stock_produit_magasin (produit_id, magasin_id),
  KEY idx_alerte_stock_pending (resolue, dernier_envoi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;