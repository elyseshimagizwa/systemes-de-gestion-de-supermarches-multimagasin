<?php
require_once 'config.php';
require_once 'config-settings.php';

requireLogin();

$user = currentUser();

$isGlobalAdmin =
    in_array(
        $user['role'],
        ['super_admin','global_admin']
    );

$magasin_id =
    (int)($user['magasin_id'] ?? 0);

$entreprise_id =
    (int)($user['entreprise_id'] ?? 0);

$settings = getSettings();
$devise = $settings['devise'] ?? 'FCFA';

$id = $_GET['id'] ?? 0;

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

$params = [$id];

if(!$isGlobalAdmin){

    $sql .= "
    AND v.magasin_id=?
    ";

    $params[] =
        $magasin_id;
}

$sql .= "
LIMIT 1
";

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$vente = $stmt->fetch();

if(!$vente){

    exit("
    <div class='text-red-500 p-4'>
        Vente introuvable
    </div>
    ");
}

/* =========================
   TVA SETTINGS
========================= */

$tva = $vente['tva'] ?? 0;

/* =========================
   LIGNES
========================= */

$lignes = $pdo->prepare("
    SELECT 
        lv.*,
        p.nom
    FROM ligne_ventes lv

    LEFT JOIN produits p
    ON p.id = lv.produit_id

    WHERE lv.vente_id=?
");

$lignes->execute([$id]);

$items = $lignes->fetchAll();

?>

<div class="space-y-4">

    <!-- INFOS -->
    <div class="grid md:grid-cols-2 gap-4">

        <div class="bg-slate-100 dark:bg-slate-900 p-4 rounded-xl">

            <div class="text-sm text-gray-500">
                Ticket
            </div>

            <div class="font-bold text-xl">
                #<?= $vente['id'] ?>
            </div>

        </div>

        <div class="bg-slate-100 dark:bg-slate-900 p-4 rounded-xl">

            <div class="text-sm text-gray-500">
                Caissier
            </div>

            <div class="font-bold text-xl">
                <?= e($vente['caissier']) ?>
            </div>

        </div>

    </div>

    <!-- TABLE -->
    <div class="overflow-auto rounded-xl border dark:border-slate-700">

        <table class="w-full">

            <thead class="bg-slate-200 dark:bg-slate-900">

                <tr>

                    <th class="p-3 text-left">
                        Produit
                    </th>

                    <th class="p-3 text-left">
                        Qté
                    </th>

                    <th class="p-3 text-left">
                        PU
                    </th>

                    <th class="p-3 text-left">
                        Total
                    </th>

                </tr>

            </thead>

            <tbody>

            <?php foreach($items as $i): ?>

                <tr class="border-t dark:border-slate-700">

                    <td class="p-3">
                        <?= e($i['nom']) ?>
                    </td>

                    <td class="p-3">
                        <?= $i['quantite'] ?>
                    </td>

                    <td class="p-3">
                        <?= number_format($i['prix_unitaire'],2) ?>
                        <?= $devise ?>
                    </td>

                    <td class="p-3 font-bold text-green-600">

                        <?= number_format($i['sous_total'],2) ?>
                        <?= $devise ?>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <!-- TOTAL -->
    <div class="grid md:grid-cols-2 gap-4">

        <div class="bg-slate-100 dark:bg-slate-900 p-4 rounded-xl">

            <div class="text-gray-500 text-sm">
                Mode paiement
            </div>

            <div class="font-bold">
                <?= e($vente['mode_paiement']) ?>
            </div>

        </div>

        <div class="bg-slate-100 dark:bg-slate-900 p-4 rounded-xl">

            <div class="text-gray-500 text-sm">
                Date vente
            </div>

            <div class="font-bold">
                <?= e($vente['date_vente']) ?>
            </div>

        </div>

    </div>

    <!-- FINANCE -->
    <div class="bg-slate-100 dark:bg-slate-900 p-5 rounded-xl space-y-2">

        <div class="flex justify-between">
            <span>Total TTC</span>

            <b>
                <?= number_format($vente['total'],2) ?>
                <?= $devise ?>
            </b>
        </div>

        <div class="flex justify-between">
            <span>TVA</span>

            <b>
                <?= number_format($tva,2) ?>
                <?= $devise ?>
            </b>
        </div>

        <div class="flex justify-between">
            <span>Montant reçu</span>

            <b>
                <?= number_format($vente['montant_recu'],2) ?>
                <?= $devise ?>
            </b>
        </div>

        <div class="flex justify-between text-green-600">
            <span>Monnaie rendue</span>

            <b>
                <?= number_format($vente['monnaie'],2) ?>
                <?= $devise ?>
            </b>
        </div>

    </div>

</div>