ALTER TABLE commandes_clients
  ADD COLUMN code_retrait varchar(20) NULL AFTER numero,
  ADD COLUMN date_confirmation datetime NULL,
  ADD COLUMN date_preparation datetime NULL,
  ADD COLUMN date_prete datetime NULL,
  ADD COLUMN date_retrait datetime NULL,
  ADD COLUMN date_annulation datetime NULL,
  ADD COLUMN traite_par int(11) NULL,
  ADD UNIQUE KEY uq_commande_code_retrait (code_retrait),
  ADD KEY idx_client_orders_status (statut);

ALTER TABLE settings
  ADD COLUMN smtp_host varchar(150) DEFAULT NULL,
  ADD COLUMN smtp_port int(11) DEFAULT 587,
  ADD COLUMN smtp_username varchar(150) DEFAULT NULL,
  ADD COLUMN smtp_password varchar(255) DEFAULT NULL,
  ADD COLUMN smtp_secure varchar(20) DEFAULT 'tls',
  ADD COLUMN smtp_from_email varchar(150) DEFAULT NULL;

UPDATE commandes_clients
SET code_retrait = CONCAT('RET-', LPAD(id, 6, '0'))
WHERE code_retrait IS NULL;

CREATE TABLE IF NOT EXISTS historique_commandes_clients (
  id int(11) NOT NULL AUTO_INCREMENT,
  commande_id int(11) NOT NULL,
  ancien_statut varchar(30) DEFAULT NULL,
  nouveau_statut varchar(30) NOT NULL,
  utilisateur_id int(11) DEFAULT NULL,
  commentaire varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_order_status_order (commande_id),
  KEY idx_order_status_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications_clients (
  id int(11) NOT NULL AUTO_INCREMENT,
  utilisateur_id int(11) NOT NULL,
  commande_id int(11) DEFAULT NULL,
  titre varchar(150) NOT NULL,
  message text NOT NULL,
  lu tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_client_notifications_user (utilisateur_id),
  KEY idx_client_notifications_unread (utilisateur_id, lu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO historique_commandes_clients (commande_id, nouveau_statut, commentaire)
SELECT id, statut, 'Historique initial'
FROM commandes_clients c
WHERE NOT EXISTS (
  SELECT 1 FROM historique_commandes_clients h WHERE h.commande_id=c.id
);