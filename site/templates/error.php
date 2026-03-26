<?php
// error/404 template
snippet('header') ?>

<main class="min-h-[80vh] flex items-center justify-center py-20">
    <div class="col-7 text-center">
        <!-- Big Thyssen number for premium feel -->
        <h1 class="font-thyssen text-[120px] md:text-[200px] text-border leading-none mb-4 select-none">404</h1>

        <div class="max-w-md mx-auto">
            <h2 class="font-sans text-2xl md:text-3xl text-ink mb-6">Page introuvable</h2>
            <p class="font-sans text-base text-ink/60 mb-10 leading-relaxed">
                Désolé, la page que vous recherchez n’existe pas ou a été déplacée. Nous vous invitons à retourner sur
                la page d'accueil ou à explorer notre carte.
            </p>

            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="<?= $site->url() ?>"
                    class="btn border-[4px] border-ink text-ink hover:bg-ink hover:text-white transition-all duration-150">
                    Retour à l'accueil
                </a>
                <a href="<?= url('map') ?>"
                    class="btn border-[4px] border-border text-ink hover:bg-surface transition-all duration-150">
                    Voir la carte
                </a>
            </div>
        </div>
    </div>
</main>

<?php snippet('footer') ?>