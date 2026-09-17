-- Migration 013 : inventaires en quantites decimales (kg, litres)
-- A executer apres 010_inventaires.sql et 011_produits_lots_unites.sql.

ALTER TABLE inventaire_lignes
  MODIFY stock_theorique DECIMAL(12,3) NOT NULL,
  MODIFY quantite_comptee DECIMAL(12,3) NOT NULL,
  MODIFY ecart DECIMAL(12,3) NOT NULL;
