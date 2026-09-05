ALTER TABLE utilisateurs
  MODIFY role enum('admin','caissier','client') NOT NULL DEFAULT 'caissier';

CREATE TABLE IF NOT EXISTS commandes_clients (
  id int(11) NOT NULL AUTO_INCREMENT,
  numero varchar(40) NOT NULL,
  utilisateur_id int(11) NOT NULL,
  magasin_id int(11) NOT NULL,
  total decimal(12,2) NOT NULL DEFAULT 0.00,
  statut enum('En attente','Confirmée','Préparée','Récupérée','Annulée') NOT NULL DEFAULT 'En attente',
  date_commande timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_commandes_clients_numero (numero),
  KEY idx_commandes_clients_user (utilisateur_id),
  KEY idx_commandes_clients_magasin (magasin_id),
  CONSTRAINT fk_commandes_clients_user FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id),
  CONSTRAINT fk_commandes_clients_magasin FOREIGN KEY (magasin_id) REFERENCES magasins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lignes_commandes_clients (
  id int(11) NOT NULL AUTO_INCREMENT,
  commande_id int(11) NOT NULL,
  produit_id int(11) NOT NULL,
  nom_produit varchar(150) NOT NULL,
  quantite int(11) NOT NULL,
  prix_unitaire decimal(10,2) NOT NULL,
  sous_total decimal(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_lignes_commandes_clients_commande (commande_id),
  CONSTRAINT fk_lignes_commandes_clients_commande FOREIGN KEY (commande_id) REFERENCES commandes_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
