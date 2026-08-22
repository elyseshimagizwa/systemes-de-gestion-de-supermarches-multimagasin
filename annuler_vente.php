<?php

require_once 'config.php';
require_once 'config-settings.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    exit("Méthode non autorisée");

}

verify_csrf();

$user = currentUser();

/*==================================================
=            ROLES
==================================================*/

$isSuperAdmin =
    $user['role'] === 'super_admin';

$isGlobalAdmin =
    $user['role'] === 'global_admin';

$isAdmin =
    in_array(
        $user['role'],
        [
            'super_admin',
            'global_admin',
            'admin'
        ]
    );

if (!$isAdmin) {

    $_SESSION['error'] =
        "Vous n'avez pas l'autorisation d'annuler une vente.";

    header("Location: ventes.php");

    exit;
}

/*==================================================
=            INFOS UTILISATEUR
==================================================*/

$entreprise_id =
    (int)($user['entreprise_id'] ?? 0);

$currentMagasinId =
    (int)($user['magasin_id'] ?? 0);

$utilisateur_id =
    (int)$user['id'];

$utilisateur_nom =
    $user['nom'] ?? '';

/*==================================================
=            PARAMETRES
==================================================*/

$settings = getSettings();

$devise =
    $settings['devise'] ?? 'FCFA';

/*==================================================
=            ID VENTE
==================================================*/

$vente_id =
    (int)($_GET['id'] ?? 0);

if ($vente_id <= 0) {

    $_SESSION['error'] =
        "Vente introuvable.";

    header("Location: ventes.php");

    exit;
}

/*==================================================
=            CHARGEMENT VENTE
==================================================*/

$sql = "

SELECT

v.*,

u.nom AS caissier,

m.nom AS magasin

FROM ventes v

LEFT JOIN utilisateurs u
ON u.id=v.utilisateur_id

LEFT JOIN magasins m
ON m.id=v.magasin_id

WHERE v.id=?

";

$params = [

    $vente_id

];

if (!$isSuperAdmin && !$isGlobalAdmin) {

    $sql .= "

    AND v.magasin_id=?

    ";

    $params[] =
        $currentMagasinId;
}

$sql .= "

LIMIT 1

";

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$vente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vente) {

    throw new Exception("Vente introuvable.");

}

if ($vente['statut'] === 'annulee') {

    throw new Exception(
        "Cette vente est déjà annulée."
    );

}

if (!$vente) {

    $_SESSION['error'] =
        "Cette vente n'existe pas ou ne vous appartient pas.";

    header("Location: ventes.php");

    exit;
}

/*==================================================
=            VERIFICATION ENTREPRISE
==================================================*/

if (
    !$isSuperAdmin
    &&
    $vente['entreprise_id'] != $entreprise_id
) {

    $_SESSION['error'] =
        "Accès refusé.";

    header("Location: ventes.php");

    exit;
}

/*==================================================
=            DEJA ANNULEE ?
==================================================*/

if (
    isset($vente['statut'])
    &&
    $vente['statut'] === 'annulee'
) {

    $_SESSION['warning'] =
        "Cette vente est déjà annulée.";

    header("Location: ventes.php");

    exit;
}

/*==================================================
=            CHARGEMENT DES LIGNES
==================================================*/

$stmt = $pdo->prepare("

SELECT

lv.*,

p.nom,

p.magasin_id

FROM ligne_ventes lv

INNER JOIN produits p

ON p.id=lv.produit_id

WHERE lv.vente_id=?

ORDER BY lv.id ASC

");

$stmt->execute([

    $vente_id

]);

$lignes =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($lignes)) {

    $_SESSION['error'] =
        "Impossible d'annuler une vente sans lignes.";

    header("Location: ventes.php");

    exit;
}

/*==================================================
=            DEBUT TRANSACTION
==================================================*/

try {

    $pdo->beginTransaction();


    /*==================================================
=            REMISE EN STOCK
==================================================*/

foreach ($lignes as $ligne) {

    $stmtProduit = $pdo->prepare("
        SELECT
            id,
            quantite,
            magasin_id
        FROM produits
        WHERE id=?
        FOR UPDATE
    ");

    $stmtProduit->execute([
        $ligne['produit_id']
    ]);

    $produit = $stmtProduit->fetch(PDO::FETCH_ASSOC);

    if (!$produit) {
        throw new Exception(
            "Produit introuvable : ".$ligne['nom']
        );
    }

    /* Vérification du magasin */

    if (
        (int)$produit['magasin_id']
        !=
        (int)$vente['magasin_id']
    ) {

        throw new Exception(
            "Le produit ".$ligne['nom']." n'appartient pas au magasin de la vente."
        );
    }

    /* Remise en stock */

    $updateStock = $pdo->prepare("
        UPDATE produits

        SET quantite = quantite + ?

        WHERE id=?
    ");

    $updateStock->execute([

        $ligne['quantite'],

        $ligne['produit_id']

    ]);

}

/*==================================================
=            TRANSACTION FINANCIERE INVERSE
==================================================*/

$stmtFinance = $pdo->prepare("

INSERT INTO transactions_financieres
(

entreprise_id,

magasin_id,

utilisateur,

type,

categorie,

montant,

description,

date_transaction

)

VALUES
(

?,

?,

?,

'SORTIE',

'ANNULATION VENTE',

?,

?,

NOW()

)

");

$stmtFinance->execute([

    $vente['entreprise_id'],

    $vente['magasin_id'],

    $utilisateur_nom,

    $vente['total'],

    "Annulation de la vente N° ".$vente['numero_ticket']

]);

/*==================================================
=            MISE A JOUR DE LA VENTE
==================================================*/

$stmtUpdate = $pdo->prepare("

UPDATE ventes

SET

statut='annulee',

annule_par=?,

date_annulation=NOW()

WHERE id=?

");

$stmtUpdate->execute([

    $utilisateur_id,

    $vente_id

]);

/*==================================================
=            HISTORIQUE ANNULATION
==================================================*/

$stmtHistorique = $pdo->prepare("

INSERT INTO annulations_ventes
(

vente_id,

numero_ticket,

entreprise_id,

magasin_id,

utilisateur_id,

montant,

date_annulation

)

VALUES
(

?,

?,

?,

?,

?,

?,

NOW()

)

");

$stmtHistorique->execute([

    $vente_id,

    $vente['numero_ticket'],

    $vente['entreprise_id'],

    $vente['magasin_id'],

    $utilisateur_id,

    $vente['total']

]);

/*==================================================
=            JOURNAL DES ACTIONS
==================================================*/

$stmtJournal = $pdo->prepare("

INSERT INTO journal_actions
(

entreprise_id,

magasin_id,

utilisateur_id,

action,

description,

date_action

)

VALUES
(

?,

?,

?,

?,

?,

NOW()

)

");

$stmtJournal->execute([

    $vente['entreprise_id'],

    $vente['magasin_id'],

    $utilisateur_id,

    'ANNULATION_VENTE',

    'Annulation de la vente '.$vente['numero_ticket']

]);

/*==================================================
=            MISE A JOUR DES STATISTIQUES
==================================================*/

if (tableExists($pdo, 'statistiques_journalieres')) {

    $stmtStats = $pdo->prepare("

        UPDATE statistiques_journalieres

        SET

            chiffre_affaires = chiffre_affaires - ?,

            nombre_ventes = GREATEST(nombre_ventes - 1,0)

        WHERE

            entreprise_id=?

        AND magasin_id=?

        AND DATE(date_statistique)=CURDATE()

    ");

    $stmtStats->execute([

        $vente['total'],

        $vente['entreprise_id'],

        $vente['magasin_id']

    ]);

}
/*==================================================
=            VALIDATION FINALE
==================================================*/

$pdo->commit();

$_SESSION['success'] =
"✅ La vente N° ".$vente['numero_ticket']." a été annulée avec succès.";

header("Location: ventes.php");

exit;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {

        $pdo->rollBack();

    }

    $_SESSION['error'] =
    "Erreur lors de l'annulation : ".$e->getMessage();

    header("Location: ventes.php");

    exit;

}