-- Caisses physiques separees par magasin.
CREATE TABLE IF NOT EXISTS caisses (
  id int(11) NOT NULL AUTO_INCREMENT,
  magasin_id int(11) NOT NULL,
  nom varchar(100) NOT NULL,
  code varchar(50) NOT NULL,
  statut enum('active','inactive') NOT NULL DEFAULT 'active',
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_caisse_code_magasin (magasin_id, code),
  UNIQUE KEY uq_caisse_nom_magasin (magasin_id, nom),
  KEY idx_caisses_magasin (magasin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO caisses (magasin_id, nom, code)
SELECT m.id, 'Caisse principale', CONCAT('C-', m.id)
FROM magasins m
WHERE NOT EXISTS (
    SELECT 1 FROM caisses c WHERE c.magasin_id=m.id
);

ALTER TABLE sessions_caisse
  ADD COLUMN caisse_id int(11) NULL AFTER magasin_id;

UPDATE sessions_caisse sc
JOIN caisses c ON c.magasin_id=sc.magasin_id
SET sc.caisse_id=c.id
WHERE sc.caisse_id IS NULL;

UPDATE sessions_caisse SET statut='fermee' WHERE statut='fermée';

ALTER TABLE sessions_caisse
  MODIFY statut enum('ouverte','fermee') NOT NULL DEFAULT 'ouverte';

ALTER TABLE sessions_caisse
  MODIFY caisse_id int(11) NOT NULL,
  ADD KEY idx_sessions_caisse (caisse_id),
  ADD CONSTRAINT fk_sessions_caisse_physique
    FOREIGN KEY (caisse_id) REFERENCES caisses(id);

ALTER TABLE ventes
  ADD COLUMN caisse_id int(11) NULL AFTER magasin_id,
  ADD KEY idx_ventes_caisse (caisse_id);

UPDATE ventes v
LEFT JOIN sessions_caisse sc ON sc.id=v.session_caisse_id
SET v.caisse_id=sc.caisse_id
WHERE v.caisse_id IS NULL AND sc.caisse_id IS NOT NULL;

UPDATE ventes v
JOIN caisses c ON c.magasin_id=v.magasin_id
SET v.caisse_id=c.id
WHERE v.caisse_id IS NULL;

ALTER TABLE ventes
  ADD CONSTRAINT fk_ventes_caisse_physique
    FOREIGN KEY (caisse_id) REFERENCES caisses(id);

ALTER TABLE historiques
  ADD COLUMN caisse_id int(11) NULL AFTER magasin_id,
  ADD KEY idx_historiques_caisse (caisse_id),
  ADD CONSTRAINT fk_historiques_caisse_physique
    FOREIGN KEY (caisse_id) REFERENCES caisses(id);

INSERT INTO historiques
    (utilisateur_id, magasin_id, caisse_id, action, details, ip, niveau, created_at)
SELECT
    v.utilisateur_id,
    v.magasin_id,
    v.caisse_id,
    'VENTE',
    CONCAT('Vente #', v.id, ' | Total : ', v.total),
    'SYSTEM',
    'SUCCESS',
    v.date_vente
FROM ventes v
WHERE NOT EXISTS (
    SELECT 1
    FROM historiques h
    WHERE h.action='VENTE'
      AND h.details LIKE CONCAT('Vente #', v.id, ' |%')
);
