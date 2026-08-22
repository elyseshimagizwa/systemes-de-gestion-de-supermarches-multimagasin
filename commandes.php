
<?php

require_once 'config.php';

requireLogin();

/* =========================
   ACCES ADMIN + CAISSIER
========================= */
if (!in_array(currentUser()['role'], ['admin', 'caissier'])) {

    flash('error', 'Accès refusé');

    header('Location: dashboard.php');

    exit;
}

/* =========================
   USER
========================= */
$user = currentUser();

$isAdmin = ($user['role'] === 'admin');

/* =========================
   CREER COMMANDE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['creer'])) {

    verify_csrf();

    $pdo->beginTransaction();

    try {

        /* =========================
           INSERT COMMANDE
        ========================== */
        $stmt = $pdo->prepare("
            INSERT INTO commandes
            (
                fournisseur_id,
                statut
            )
            VALUES
            (
                ?,
                'En attente'
            )
        ");

        $stmt->execute([
            $_POST['fournisseur_id']
        ]);

        $commandeId = $pdo->lastInsertId();

        /* =========================
           LIGNES COMMANDE
        ========================== */
        foreach ($_POST['produit_id'] as $i => $pid) {

            $qte = (int)$_POST['quantite'][$i];

            if ($pid && $qte > 0) {

                $pa =
                    (float)$_POST['prix_achat'][$i];

                $l = $pdo->prepare("
                    INSERT INTO ligne_commandes
                    (
                        commande_id,
                        produit_id,
                        quantite,
                        prix_achat
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?
                    )
                ");

                $l->execute([

                    $commandeId,

                    $pid,

                    $qte,

                    $pa
                ]);
            }
        }

        /* =========================
           HISTORIQUE
        ========================== */
        $h = $pdo->prepare("
            INSERT INTO historiques
            (
                utilisateur_id,
                action,
                details,
                ip,
                niveau,
                created_at
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

        $h->execute([

            $user['id'],

            'CREATION COMMANDE',

            'Commande fournisseur ID : '.$commandeId,

            $_SERVER['REMOTE_ADDR'],

            'success'
        ]);

        $pdo->commit();

        flash('success','Commande créée');

    } catch(Exception $e) {

        $pdo->rollBack();

        flash('error','Erreur création commande');
    }

    header('Location: commandes.php');

    exit;
}

/* =========================
   RECEPTIONNER COMMANDE
========================= */
if (isset($_GET['recevoir'])) {

    $id = (int)$_GET['recevoir'];

    $pdo->beginTransaction();

    try {

        /* =========================
           LIGNES COMMANDE
        ========================== */
        $rows = $pdo->prepare("
            SELECT *
            FROM ligne_commandes
            WHERE commande_id=?
        ");

        $rows->execute([$id]);

        $items = $rows->fetchAll();

        foreach($items as $it){

            /* STOCK ACTUEL */
            $old = $pdo->prepare("
                SELECT quantite
                FROM produits
                WHERE id=?
            ");

            $old->execute([
                $it['produit_id']
            ]);

            $ancien =
                (int)$old->fetchColumn();

            $nouveau =
                $ancien + $it['quantite'];

            /* UPDATE STOCK */
            $u = $pdo->prepare("
                UPDATE produits
                SET quantite=?
                WHERE id=?
            ");

            $u->execute([

                $nouveau,

                $it['produit_id']
            ]);

            /* =========================
               MOUVEMENT STOCK
            ========================== */
            $m = $pdo->prepare("
                INSERT INTO stock_mouvements
                (
                    produit_id,
                    type,
                    quantite,
                    ancien_stock,
                    nouveau_stock,
                    motif,
                    utilisateur_id
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            $m->execute([

                $it['produit_id'],

                'entree_commande',

                $it['quantite'],

                $ancien,

                $nouveau,

                'Réception commande',

                $user['id']
            ]);
        }

        /* =========================
           UPDATE COMMANDE
        ========================== */
        $pdo->prepare("
            UPDATE commandes
            SET statut='Reçue totalement'
            WHERE id=?
        ")->execute([$id]);

        /* =========================
           HISTORIQUE
        ========================== */
        $h = $pdo->prepare("
            INSERT INTO historiques
            (
                utilisateur_id,
                action,
                details,
                ip,
                niveau,
                created_at
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

        $h->execute([

            $user['id'],

            'RECEPTION COMMANDE',

            'Commande réceptionnée ID : '.$id,

            $_SERVER['REMOTE_ADDR'],

            'info'
        ]);

        $pdo->commit();

        flash('success','Commande réceptionnée');

    } catch(Exception $e){

        $pdo->rollBack();

        flash('error','Erreur réception');
    }

    header('Location: commandes.php');

    exit;
}

/* =========================
   DATA
========================= */
$fournisseurs = $pdo->query("
    SELECT *
    FROM fournisseurs
    ORDER BY nom
")->fetchAll();

$produits = $pdo->query("
    SELECT *
    FROM produits
    ORDER BY nom
")->fetchAll();

$list = $pdo->query("
    SELECT
        c.*,
        f.nom fournisseur

    FROM commandes c

    JOIN fournisseurs f
    ON f.id=c.fournisseur_id

    ORDER BY c.id DESC
")->fetchAll();

/* =========================
   INCLUDES
========================= */
include 'includes/header.php';

include 'includes/sidebar.php';
?>

<div class="p-4 md:p-6">

<!-- HEADER -->
<div class="flex justify-between items-center mb-6">

    <div>

        <h1 class="text-3xl font-bold">
            📦 Commandes Fournisseurs
        </h1>

        <p class="text-gray-500">
            Gestion des commandes fournisseurs
        </p>

    </div>

</div>

<!-- ALERT -->
<?php if($m=flash('success')): ?>

<div class="bg-green-100 text-green-700 p-3 rounded-xl mb-4">

    <?= e($m) ?>

</div>

<?php endif; ?>

<?php if($m=flash('error')): ?>

<div class="bg-red-100 text-red-700 p-3 rounded-xl mb-4">

    <?= e($m) ?>

</div>

<?php endif; ?>

<!-- FORM -->
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow p-5 mb-6">

<form method="POST" class="space-y-5">

<input
    type="hidden"
    name="csrf_token"
    value="<?= csrf_token() ?>"
>

<input
    type="hidden"
    name="creer"
    value="1"
>

<!-- FOURNISSEUR -->
<div>

<label class="font-semibold block mb-2">
    Fournisseur
</label>

<select
    name="fournisseur_id"
    required
    class="border p-3 rounded-xl w-full"
>

<option value="">
    Choisir fournisseur
</option>

<?php foreach($fournisseurs as $f): ?>

<option value="<?= $f['id'] ?>">

    <?= e($f['nom']) ?>

</option>

<?php endforeach; ?>

</select>

</div>

<!-- PRODUITS -->
<div class="grid md:grid-cols-3 gap-4">

<?php for($i=0;$i<3;$i++): ?>

<div class="border rounded-2xl p-4 space-y-3">

    <h3 class="font-bold text-sm">
        Produit <?= $i+1 ?>
    </h3>

    <!-- PRODUIT -->
    <select
        name="produit_id[]"
        class="border p-3 rounded-xl w-full"
    >

    <option value="">
        Produit
    </option>

    <?php foreach($produits as $p): ?>

    <option value="<?= $p['id'] ?>">

        <?= e($p['nom']) ?>

    </option>

    <?php endforeach; ?>

    </select>

    <!-- QUANTITE -->
    <input
        type="number"
        name="quantite[]"
        placeholder="Quantité"
        class="border p-3 rounded-xl w-full"
    >

    <!-- PRIX -->
    <input
        type="number"
        step="0.01"
        name="prix_achat[]"
        placeholder="Prix achat"
        class="border p-3 rounded-xl w-full"
    >

</div>

<?php endfor; ?>

</div>

<!-- BTN -->
<button
    class="bg-blue-600 hover:bg-blue-700
           text-white rounded-xl px-4 py-3 w-full"
>

    ➕ Créer commande

</button>

</form>

</div>

<!-- TABLE -->
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow overflow-x-auto">

<table class="min-w-full text-sm">

<thead class="bg-gray-100 dark:bg-slate-700">

<tr>

<th class="p-3 text-left">#</th>

<th class="p-3 text-left">Fournisseur</th>

<th class="p-3 text-left">Statut</th>

<th class="p-3 text-left">Date</th>

<th class="p-3 text-left">Actions</th>

</tr>

</thead>

<tbody>

<?php foreach($list as $c): ?>

<tr class="border-t hover:bg-gray-50 dark:hover:bg-slate-700">

    <td class="p-3">

        <?= $c['id'] ?>

    </td>

    <td class="p-3">

        <?= e($c['fournisseur']) ?>

    </td>

    <td class="p-3">

        <?php if($c['statut']=='Reçue totalement'): ?>

            <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs">

                ✅ Reçue

            </span>

        <?php else: ?>

            <span class="bg-yellow-100 text-yellow-700 px-3 py-1 rounded-full text-xs">

                ⏳ En attente

            </span>

        <?php endif; ?>

    </td>

    <td class="p-3">

        <?= e($c['date_commande']) ?>

    </td>

    <td class="p-3">

        <?php if($c['statut'] !== 'Reçue totalement'): ?>

        <a
            href="?recevoir=<?= $c['id'] ?>"
            onclick="return confirm('Réceptionner cette commande ?')"
            class="bg-green-600 hover:bg-green-700
                   text-white px-4 py-2 rounded-xl text-sm"
        >

            ✅ Réceptionner

        </a>

        <?php else: ?>

        <span class="text-green-600 font-semibold">

            Terminée

        </span>

        <?php endif; ?>

    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>

<?php include 'includes/footer.php'; ?>
```
