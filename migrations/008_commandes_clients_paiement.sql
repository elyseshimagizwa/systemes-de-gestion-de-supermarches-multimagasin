ALTER TABLE commandes_clients
  ADD COLUMN mode_paiement varchar(40) NOT NULL DEFAULT 'Paiement au retrait' AFTER statut;