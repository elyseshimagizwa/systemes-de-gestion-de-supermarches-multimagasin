<?php

/** Quantities use three decimal places, consistently with DECIMAL(12,3). */
function stockQuantity($value): float
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('Quantité invalide.');
    }
    $text = str_replace(',', '.', trim((string)$value));
    if (!preg_match('/^\d+(?:\.\d{1,3})?$/D', $text)) {
        throw new InvalidArgumentException('La quantité doit être positive ou nulle, avec trois décimales maximum.');
    }
    $quantity = (float)$text;
    if (!is_finite($quantity) || $quantity > 999999999.999) {
        throw new InvalidArgumentException('Quantité trop élevée.');
    }
    return round($quantity, 3);
}

function inventoryAdjustedStock(float $current, float $theoretical, float $counted): float
{
    $result = round($current + $counted - $theoretical, 3);
    if (!is_finite($result) || $result < 0 || $result > 999999999.999) {
        throw new RuntimeException('Correction impossible : recomptez le produit avant validation.');
    }
    return $result;
}

/** Caller must lock the product first and own the transaction. */
function adjustInventoryLots(PDO $pdo, int $productId, int $storeId, float $current, float $target, string $reference): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Une transaction est nécessaire pour corriger les lots.');
    }
    $stmt = $pdo->prepare('SELECT id, quantite_restante, statut FROM lots_produits WHERE produit_id=? AND magasin_id=? ORDER BY date_reception, id FOR UPDATE');
    $stmt->execute([$productId, $storeId]);
    $lots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = round(array_sum(array_column($lots, 'quantite_restante')), 3);
    if (abs($total - $current) > 0.000001) {
        throw new RuntimeException('Stock et lots incohérents : réconciliez les lots avant validation.');
    }
    $difference = round($target - $current, 3);
    if ($difference > 0) {
        // An unexplained surplus must not silently acquire an expiry date or become sellable.
        $stmt = $pdo->prepare("INSERT INTO lots_produits (produit_id, magasin_id, numero_lot, quantite_initiale, quantite_restante, prix_achat, statut) SELECT id, magasin_id, ?, ?, ?, prix_achat, 'bloque' FROM produits WHERE id=? AND magasin_id=?");
        $stmt->execute(['INV-' . $reference, $difference, $difference, $productId, $storeId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Produit introuvable pour la correction de lot.');
        }
    } elseif ($difference < 0) {
        $nonEmpty = array_values(array_filter($lots, static function (array $lot): bool {
            return (float)$lot['quantite_restante'] > 0;
        }));
        // A global count cannot determine which of several lots is missing.
        if (count($nonEmpty) !== 1) {
            throw new RuntimeException('Écart négatif sur plusieurs lots : un contrôle par lot est nécessaire.');
        }
        $lot = $nonEmpty[0];
        $remaining = round((float)$lot['quantite_restante'] + $difference, 3);
        $stmt = $pdo->prepare('UPDATE lots_produits SET quantite_restante=?, statut=? WHERE id=?');
        $stmt->execute([$remaining, $remaining == 0 ? 'epuise' : $lot['statut'], (int)$lot['id']]);
    }
}

/** The inventory session must already be locked by the caller. */
function applyInventoryCorrection(PDO $pdo, int $productId, int $storeId, float $theoretical, float $counted, string $reference, int $operatorId): float
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Transaction nécessaire.');
    }
    $stmt = $pdo->prepare('SELECT quantite FROM produits WHERE id=? AND magasin_id=? FOR UPDATE');
    $stmt->execute([$productId, $storeId]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        throw new RuntimeException('Produit introuvable dans ce magasin.');
    }
    $current = stockQuantity($value);
    $target = inventoryAdjustedStock($current, $theoretical, $counted);
    $difference = round($target - $current, 3);
    adjustInventoryLots($pdo, $productId, $storeId, $current, $target, $reference);
    if (abs($difference) > 0.000001) {
        $pdo->prepare('UPDATE produits SET quantite=? WHERE id=? AND magasin_id=?')->execute([$target, $productId, $storeId]);
        $pdo->prepare("INSERT INTO stock_mouvements (produit_id, magasin_id, type, quantite, ancien_stock, nouveau_stock, motif, utilisateur_id, date_mouvement) VALUES (?, ?, 'inventaire_correctif', ?, ?, ?, ?, ?, NOW())")
            ->execute([$productId, $storeId, abs($difference), $current, $target, 'Inventaire ' . $reference, $operatorId]);
    }
    return $target;
}

