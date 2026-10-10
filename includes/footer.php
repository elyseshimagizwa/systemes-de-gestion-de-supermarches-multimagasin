
<?php
require_once __DIR__ . '/../config.php';

$user     = currentUser();
$settings = getSettings();

/* =========================================================
   MULTI-MAGASIN
   ========================================================= */

$magasinNom       = "Magasin Principal";
$magasinAdresse   = $settings['adresse'] ?? '';
$magasinTelephone = $settings['telephone'] ?? '';

$magasinId = function_exists('currentMagasinId')
    ? (int) currentMagasinId()
    : (int) ($user['magasin_id'] ?? 0);

if ($magasinId > 0) {

    global $pdo;

    $stmt = $pdo->prepare("
        SELECT nom, adresse, telephone
        FROM magasins
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$magasinId]);

    $magasin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($magasin) {

        $magasinNom = !empty($magasin['nom'])
            ? $magasin['nom']
            : $magasinNom;

        $magasinAdresse = !empty($magasin['adresse'])
            ? $magasin['adresse']
            : $magasinAdresse;

        $magasinTelephone = !empty($magasin['telephone'])
            ? $magasin['telephone']
            : $magasinTelephone;
    }
}

/* =========================================================
   INFORMATIONS
   ========================================================= */

$nomBoutique = $settings['nom_boutique']
    ?? 'BURUNDI MARKET HUB';

$email = $settings['email_admin']
    ?? '';

$telephone = $magasinTelephone
    ?: ($settings['telephone'] ?? '');

$adresse = $magasinAdresse
    ?: ($settings['adresse'] ?? '');

$logo = $settings['logo'] ?? '';
?>

<footer class="mt-10">

    <!-- =====================================================
         FOOTER — MÊME STYLE QUE LE HEADER
         ===================================================== -->

    <div
        class="
            text-white
            overflow-hidden
            relative
        "
        style="
            background:
                linear-gradient(
                    120deg,
                    #123b2a,
                    #27704d 58%,
                    #d4e65c
                );
        "
    >

        <!-- =================================================
             CONTENU
             ================================================= -->

        <div class="
            relative
            z-10
            max-w-7xl
            mx-auto
            px-5
            sm:px-6
            lg:px-8
            py-10
        ">

            <!-- =================================================
                 PARTIE PRINCIPALE
                 ================================================= -->

            <div class="
                grid
                grid-cols-1
                md:grid-cols-2
                lg:grid-cols-3
                gap-8
            ">

                <!-- =============================================
                     MARQUE
                     ============================================= -->

                <div>

                    <div class="
                        flex
                        items-center
                        gap-3
                        mb-4
                    ">

                        <?php if (!empty($logo)): ?>

                            <img
                                src="<?= e($logo) ?>"
                                alt="<?= e($nomBoutique) ?>"
                                class="
                                    w-12
                                    h-12
                                    rounded-xl
                                    object-cover
                                    bg-white
                                    p-1
                                    shadow-lg
                                "
                            >

                        <?php else: ?>

                            <div class="
                                w-12
                                h-12
                                rounded-xl
                                bg-green-950/40
                                border
                                border-white/20
                                flex
                                items-center
                                justify-center
                                font-black
                                shadow-lg
                            ">
                                BM
                            </div>

                        <?php endif; ?>

                        <div>

                            <h2 class="
                                text-xl
                                font-black
                                tracking-tight
                            ">
                                <?= e($nomBoutique) ?>
                            </h2>

                            <p class="
                                text-xs
                                text-lime-200
                                mt-0.5
                                font-semibold
                            ">
                                Gestion commerciale & POS
                            </p>

                        </div>

                    </div>

                    <p class="
                        text-sm
                        leading-6
                        text-green-50/90
                        max-w-md
                    ">
                        Solution moderne de gestion des ventes,
                        des stocks et des magasins, conçue pour
                        simplifier votre activité commerciale.
                    </p>

                </div>


                <!-- =============================================
                     MAGASIN ACTIF
                     ============================================= -->

                <div>

                    <h3 class="
                        text-sm
                        font-black
                        uppercase
                        tracking-widest
                        text-lime-200
                        mb-4
                    ">
                        Magasin actif
                    </h3>

                    <div class="
                        flex
                        items-start
                        gap-3
                    ">

                        <div class="
                            w-10
                            h-10
                            rounded-xl
                            bg-green-950/40
                            border
                            border-white/20
                            flex
                            items-center
                            justify-center
                            shrink-0
                        ">
                            🏬
                        </div>

                        <div class="min-w-0">

                            <div class="
                                font-black
                                text-white
                                truncate
                            ">
                                <?= e($magasinNom) ?>
                            </div>

                            <?php if (!empty($adresse)): ?>

                                <div class="
                                    text-sm
                                    text-green-50/80
                                    mt-1
                                ">
                                    📍 <?= e($adresse) ?>
                                </div>

                            <?php endif; ?>

                            <?php if (!empty($telephone)): ?>

                                <div class="
                                    text-sm
                                    text-green-50/80
                                    mt-1
                                ">
                                    📞 <?= e($telephone) ?>
                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>


                <!-- =============================================
                     CONTACT
                     ============================================= -->

                <div>

                    <h3 class="
                        text-sm
                        font-black
                        uppercase
                        tracking-widest
                        text-lime-200
                        mb-4
                    ">
                        Contact
                    </h3>

                    <div class="space-y-3">

                        <?php if (!empty($telephone)): ?>

                            <a
                                href="tel:<?= e($telephone) ?>"
                                class="
                                    flex
                                    items-center
                                    gap-3
                                    text-sm
                                    text-green-50/90
                                    hover:text-white
                                    transition
                                "
                            >

                                <span class="
                                    w-9
                                    h-9
                                    rounded-xl
                                    bg-green-950/40
                                    border
                                    border-white/20
                                    flex
                                    items-center
                                    justify-center
                                ">
                                    ☎
                                </span>

                                <span>
                                    <?= e($telephone) ?>
                                </span>

                            </a>

                        <?php endif; ?>


                        <?php if (!empty($email)): ?>

                            <a
                                href="mailto:<?= e($email) ?>"
                                class="
                                    flex
                                    items-center
                                    gap-3
                                    text-sm
                                    text-green-50/90
                                    hover:text-white
                                    transition
                                "
                            >

                                <span class="
                                    w-9
                                    h-9
                                    rounded-xl
                                    bg-green-950/40
                                    border
                                    border-white/20
                                    flex
                                    items-center
                                    justify-center
                                ">
                                    ✉
                                </span>

                                <span class="break-all">
                                    <?= e($email) ?>
                                </span>

                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 SEPARATION
                 ================================================= -->

            <div class="
                mt-8
                pt-6
                border-t
                border-white/20
                flex
                flex-col
                md:flex-row
                items-center
                justify-between
                gap-4
            ">

                <!-- =============================================
                     COPYRIGHT
                     ============================================= -->

                <div class="
                    text-xs
                    text-green-50/75
                    text-center
                    md:text-left
                ">

                    © <?= date('Y') ?>

                    <span class="
                        text-white
                        font-bold
                    ">
                        <?= e($nomBoutique) ?>
                    </span>

                    · Tous droits réservés.

                </div>


                <!-- =============================================
                     MAGASIN
                     ============================================= -->

                <div class="
                    inline-flex
                    items-center
                    gap-2
                    px-4
                    py-2
                    rounded-full
                    bg-green-950/40
                    border
                    border-white/20
                    text-xs
                    text-white
                    backdrop-blur-sm
                ">

                    <span class="
                        w-2
                        h-2
                        rounded-full
                        bg-lime-300
                    "></span>

                    <?= e($magasinNom) ?>

                </div>


                <!-- =============================================
                     DEVELOPPEUR
                     ============================================= -->

                <a
                    href="https://elyseshimagizwa.infinityfreeapp.com/?i=1"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="
                        text-xs
                        text-green-50/70
                        hover:text-white
                        transition
                    "
                >

                    Développé par

                    <span class="
                        text-white
                        font-bold
                    ">
                     SHIMEL DEV TECH
                    </span>

                </a>

            </div>

        </div>

    </div>

</footer>
