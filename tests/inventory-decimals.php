<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/stock-quantities.php';
function expectQuantity($actual, float $expected, string $label): void {
    if (abs((float)$actual - $expected) > 0.000001) throw new RuntimeException($label . ': ' . var_export($actual, true));
    echo "PASS $label: " . number_format((float)$actual, 3, '.', '') . "\n";
}
$pdo = null;
try {
    foreach (['1.125' => 1.125, '0.001' => 0.001, '10,250' => 10.250] as $input => $expected) expectQuantity(stockQuantity($input), $expected, 'parse ' . $input);
    foreach (['-1', 'abc', '1.0001', '', null, [], INF] as $input) {
        try { stockQuantity($input); throw new RuntimeException('Invalid input accepted'); }
        catch (InvalidArgumentException $e) { echo "PASS invalid input rejected\n"; }
    }
    expectQuantity(inventoryAdjustedStock(10, 10.5, 10.25), 9.75, 'sale after count');
    $pdo = new PDO(getenv('TEST_DB_DSN') ?: 'mysql:host=localhost;dbname=e_servicesburundi;charset=utf8mb4', getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    // Explicit session-local tables: no LIKE self-alias and no writes to permanent tables.
    $pdo->exec("CREATE TEMPORARY TABLE produits (
        id INT NOT NULL PRIMARY KEY, magasin_id INT NOT NULL,
        nom VARCHAR(150) NOT NULL, codebarre VARCHAR(100) NOT NULL,
        quantite DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        prix_achat DECIMAL(10,2) NOT NULL DEFAULT 0.00
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE inventaire_lignes (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        inventaire_id INT NOT NULL, produit_id INT NOT NULL,
        stock_theorique DECIMAL(12,3) NOT NULL,
        quantite_comptee DECIMAL(12,3) NOT NULL,
        ecart DECIMAL(12,3) NOT NULL, utilisateur_id INT NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE lots_produits (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        produit_id INT NOT NULL, magasin_id INT NOT NULL,
        numero_lot VARCHAR(100) DEFAULT NULL,
        quantite_initiale DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        quantite_restante DECIMAL(12,3) NOT NULL DEFAULT 0.000,
        prix_achat DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        date_reception DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        date_expiration DATE DEFAULT NULL,
        statut ENUM('actif','epuise','bloque','expire') NOT NULL DEFAULT 'actif'
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE stock_mouvements (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        produit_id INT NOT NULL, magasin_id INT NOT NULL,
        type ENUM('entree','sortie','entree_commande','sortie_vente','transfert_entree','transfert_sortie','retour_client','perte','inventaire_correctif','reservation','liberation') NOT NULL,
        quantite DECIMAL(12,3) NOT NULL,
        ancien_stock DECIMAL(12,3) NOT NULL,
        nouveau_stock DECIMAL(12,3) NOT NULL,
        motif TEXT DEFAULT NULL, utilisateur_id INT DEFAULT NULL,
        date_mouvement TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO produits (id, magasin_id, nom, codebarre, quantite) VALUES (1, 1, 'CLI test', 'CLI-TEST', 10.500)");
    $pdo->exec("INSERT INTO lots_produits (id, produit_id, magasin_id, quantite_initiale, quantite_restante, statut) VALUES (1, 1, 1, 10.500, 10.500, 'actif')");
    $pdo->exec("INSERT INTO inventaire_lignes (inventaire_id, produit_id, stock_theorique, quantite_comptee, ecart, utilisateur_id) VALUES (1, 1, 10.500, 10.250, -0.250, 1)");
    $line = $pdo->query('SELECT * FROM inventaire_lignes')->fetch();
    expectQuantity($line['quantite_comptee'], 10.25, 'count persisted');
    $pdo->exec('UPDATE produits SET quantite=quantite-0.500 WHERE id=1');
    $pdo->exec('UPDATE lots_produits SET quantite_restante=quantite_restante-0.500 WHERE id=1');
    expectQuantity(applyInventoryCorrection($pdo, 1, 1, (float)$line['stock_theorique'], (float)$line['quantite_comptee'], 'CLI', 1), 9.75, 'correction result');
    expectQuantity($pdo->query('SELECT quantite FROM produits WHERE id=1')->fetchColumn(), 9.75, 'persisted product');
    expectQuantity($pdo->query('SELECT SUM(quantite_restante) FROM lots_produits')->fetchColumn(), 9.75, 'persisted lots');
    $moves = $pdo->query('SELECT * FROM stock_mouvements')->fetchAll();
    expectQuantity(count($moves), 1, 'movement count');
    expectQuantity($moves[0]['ancien_stock'], 10, 'old stock');
    expectQuantity($moves[0]['nouveau_stock'], 9.75, 'new stock');
    expectQuantity($moves[0]['quantite'], 0.25, 'adjustment quantity');
    try { applyInventoryCorrection($pdo, 1, 2, 10.5, 10.25, 'CLI', 1); throw new LogicException('Wrong store accepted'); }
    catch (RuntimeException $e) { echo "PASS wrong store rejected\n"; }
    try { inventoryAdjustedStock(0, 10, 0); throw new LogicException('Negative accepted'); }
    catch (RuntimeException $e) { echo "PASS negative stock rejected\n"; }
    $pdo->rollBack();
    expectQuantity($pdo->query('SELECT COUNT(*) FROM produits')->fetchColumn(), 0, 'rollback');
    echo "RESULT=PASS\n";
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    echo 'RESULT=FAIL: ' . $e->getMessage() . "\n";
    exit(1);
}
