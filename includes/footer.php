<?php
require_once __DIR__ . '/../config.php';
requireLogin();

$user = currentUser();
$settings = getSettings();

/* ================= MULTI MAGASIN ================= */

$magasinNom = "Magasin Principal";
$magasinAdresse = $settings['adresse'] ?? '';
$magasinTelephone = $settings['telephone'] ?? '';

if(currentMagasinId() > 0){

    global $pdo;

    $stmt = $pdo->prepare("
        SELECT *
        FROM magasins
        WHERE id=?
        LIMIT 1
    ");

    $stmt->execute([
        currentMagasinId()
    ]);

    $magasin = $stmt->fetch();

    if($magasin){

        $magasinNom =
            $magasin['nom'];

        $magasinAdresse =
            $magasin['adresse']
            ?? $magasinAdresse;

        $magasinTelephone =
            $magasin['telephone']
            ?? $magasinTelephone;
    }
}
?>

<footer class="mt-10 px-4 pb-6">

    <!-- SHOPIFY FOOTER CARD -->

    <div class="
        bg-gradient-to-r
        from-indigo-600
        via-violet-600
        to-purple-600
        rounded-[35px]
        shadow-2xl
        overflow-hidden
        relative
    ">

        <!-- BACKGROUND DECOR -->

        <div class="
            absolute
            top-0
            right-0
            w-72
            h-72
            bg-white/10
            rounded-full
            blur-3xl
            -mr-24
            -mt-24
        "></div>

        <div class="
            absolute
            bottom-0
            left-0
            w-60
            h-60
            bg-white/10
            rounded-full
            blur-3xl
            -ml-20
            -mb-20
        "></div>

        <!-- CONTENT -->

        <div class="
            relative
            z-10
            max-w-7xl
            mx-auto
            px-6
            py-10
        ">

            <div class="
                grid
                grid-cols-1
                md:grid-cols-2
                xl:grid-cols-4
                gap-8
            ">

                <!-- BOUTIQUE -->

                <div>

                    <div class="flex items-center gap-3 mb-4">

                        <?php if(!empty($settings['logo'])): ?>

                            <img
                                src="<?= $settings['logo'] ?>"
                                class="
                                    w-14
                                    h-14
                                    rounded-2xl
                                    object-cover
                                    border-2
                                    border-white/30
                                    shadow-lg
                                "
                            >

                        <?php endif; ?>

                        <div>

                            <h2 class="
                                text-2xl
                                font-black
                                text-white
                            ">
                                <?= e($settings['nom_boutique']) ?>
                            </h2>

                            <div class="
                                text-white/80
                                text-sm
                            ">
                                Shopify POS Premium
                            </div>

                        </div>

                    </div>

                    <p class="
                        text-white/80
                        leading-relaxed
                        text-sm
                    ">

                        Solution de caisse moderne,
                        rapide et professionnelle
                        avec gestion multi-magasin,
                        ventes, stock et statistiques.

                    </p>

                </div>

                <!-- MAGASIN -->

                <div>

                    <h3 class="
                        text-white
                        font-bold
                        text-lg
                        mb-4
                    ">
                        🏬 Magasin actif
                    </h3>

                    <div class="
                        bg-white/10
                        backdrop-blur-md
                        rounded-2xl
                        p-4
                        border
                        border-white/10
                    ">

                        <div class="
                            text-white
                            font-bold
                            text-lg
                        ">
                            <?= e($magasinNom) ?>
                        </div>

                        <div class="
                            text-white/80
                            text-sm
                            mt-2
                        ">
                            📍
                            <?= e($magasinAdresse) ?>
                        </div>

                        <div class="
                            text-white/80
                            text-sm
                            mt-2
                        ">
                            📞
                            <?= e($magasinTelephone) ?>
                        </div>

                    </div>

                </div>

                <!-- CONTACT -->

                <div>

                    <h3 class="
                        text-white
                        font-bold
                        text-lg
                        mb-4
                    ">
                        📞 Contact
                    </h3>

                    <div class="space-y-3">

                        <div class="
                            bg-white/10
                            rounded-2xl
                            p-4
                            backdrop-blur-md
                        ">

                            <div class="
                                text-white/70
                                text-sm
                            ">
                                Téléphone
                            </div>

                            <div class="
                                text-white
                                font-bold
                            ">
                                <?= e($settings['telephone']) ?>
                            </div>

                        </div>

                        <div class="
                            bg-white/10
                            rounded-2xl
                            p-4
                            backdrop-blur-md
                        ">

                            <div class="
                                text-white/70
                                text-sm
                            ">
                                Email
                            </div>

                            <div class="
                                text-white
                                break-all
                                font-bold
                            ">
                                <?= e($settings['email_admin']) ?>
                            </div>

                        </div>

                    </div>

                </div>

                <!-- SYSTEM -->

                <div>

                    <h3 class="
                        text-white
                        font-bold
                        text-lg
                        mb-4
                    ">
                        ⚡ Fonctionnalités
                    </h3>

                    <div class="space-y-2">

                        <div class="
                            bg-white/10
                            rounded-xl
                            px-4
                            py-3
                            text-white
                            text-sm
                        ">
                            -Systemes de gestion multi-magasin et gestion du stock
                        </div>

                        <div class="
                            bg-white/10
                            rounded-xl
                            px-4
                            py-3
                            text-white
                            text-sm
                        ">
                            - impression des facture thermiques
                        </div>

                        <div class="
                            bg-white/10
                            rounded-xl
                            px-4
                            py-3
                            text-white
                            text-sm
                        ">
                            - Travailles en Mode hors ligne
                        </div>

                        <div class="
                            bg-white/10
                            rounded-xl
                            px-4
                            py-3
                            text-white
                            text-sm
                        ">
                            - Scanner code barre lors de la vente via les camera externes brncher sur l'ordinateur
                        </div>

                    </div>

                </div>

            </div>

            <!-- BOTTOM -->

            <div class="
                border-t
                border-white/20
                mt-10
                pt-6
                flex
                flex-col
                md:flex-row
                justify-between
                items-center
                gap-4
            ">

                <div class="
                    text-white/80
                    text-sm
                ">

                    © <?= date('Y') ?>

                    <?= e($settings['nom_boutique']) ?>

                    • Tous droits réservés

                </div>

                <!-- USER / MAGASIN -->

                <div class="
                    flex
                    flex-wrap
                    items-center
                    gap-3
                ">


                    <div class="
                        bg-emerald-500/20
                        border
                        border-emerald-300/20
                        px-4
                        py-2
                        rounded-xl
                        text-emerald-100
                        text-sm
                    ">

                        🏬
                        <?= e($magasinNom) ?>

                    </div>

                </div>

            </div>

            <!-- DESIGN -->

            <div class="
                text-center
                mt-8
            ">

                <a
                    href="https://elyseshimagizwa.infinityfreeapp.com/?i=1"
                    target="_blank"
                    class="
                        inline-flex
                        items-center
                        gap-2
                        bg-black/20
                        hover:bg-black/30
                        transition
                        px-5
                        py-3
                        rounded-2xl
                        text-yellow-300
                        font-bold
                        shadow-lg
                    "
                >

                    💻 DESIGN BY CABINET INFORMATIQUE SHIMEL

                </a>

            </div>

        </div>

    </div>

</footer>