<?php
// home page — hero, compare slider, procédé, nos projets
snippet('header');
?>

<?php if ($page->showSection('hero')): ?>
<section class="items-end min-h-[85vh] pt-4 pb-16" id="hero-section">

  <div class="col-2 order-2 lg:order-1 flex flex-col justify-end pb-2">
    <h1 class="font-sans text-[clamp(1.25rem,2vw,1.75rem)] leading-tight text-ink mb-6">
      <?= $page->heroHeading()->or('Notre patrimoine, modélisé et accessible en 3D') ?>
    </h1>
    <div class="flex flex-col sm:flex-row gap-3 w-full">
      <!-- "Carte" uses an explicit lighter orange colour -->
      <a href="<?= url('map') ?>"
        class="btn btn--orange flex-1 justify-center py-3 px-6 text-[1.1rem] transition-colors duration-150">Voir
        la carte
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
          stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="7" y1="7" x2="17" y2="17"></line>
          <polyline points="17 7 17 17 7 17"></polyline>
        </svg></a>
      <a href="<?= url('contact') ?>"
        class="btn flex-1 justify-center transition-colors duration-150">Nous contacter</a>
    </div>
  </div>

  <div class="col-5 order-1 lg:order-2">
    <!-- removing aspect ratio constraints and just letting the image fill its natural column height natively -->
    <div class="w-full h-full bg-surface rounded-md overflow-hidden min-h-[50vh]">
      <?php
      // Desktop renders the landscape "hero" field. Fall back to the portrait
      // field if only that one was filled, so an uploaded image always shows
      // instead of a blank surface box.
      $heroMobile = $page->heroImageMobile()->toFile();
      $heroMedia  = $page->heroImage()->toFile() ?: $heroMobile;
      ?>
      <?php if ($heroMedia): ?>
        <?php if ($heroMedia->type() === 'video'): ?>
          <video src="<?= $heroMedia->url() ?>" class="w-full h-full object-cover" autoplay muted loop playsinline></video>
        <?php else: ?>
          <picture>
            <?php if ($heroMobile && $heroMobile->id() !== $heroMedia->id()): ?>
              <source media="(max-width: 640px)" srcset="<?= $heroMobile->resize(900)->url() ?>">
            <?php endif ?>
            <img src="<?= $heroMedia->resize(1800)->url() ?>" alt="<?= $heroMedia->alt()->esc() ?>" class="w-full h-full object-cover" loading="eager" decoding="async" fetchpriority="high">
          </picture>
        <?php endif ?>
      <?php endif ?>
    </div>
  </div>

</section>
<?php endif ?>



<?php if ($page->showSection('compare')): ?>
<section class="py-12 md:py-20">

  <div class="col-4">
    <div class="compare-slider" id="compare-slider">
      <div class="compare-slider__before">
        <?php if ($beforeImg = $page->compareImageBefore()->toFile()): ?>
          <picture>
            <?php if ($beforeMobile = $page->compareImageBeforeMobile()->toFile()): ?>
              <source media="(max-width: 640px)" srcset="<?= $beforeMobile->url() ?>">
            <?php endif ?>
            <img src="<?= $beforeImg->url() ?>" alt="<?= $beforeImg->alt()->esc() ?>" loading="lazy" decoding="async">
          </picture>
        <?php endif ?>
      </div>
      <div class="compare-slider__after">
        <?php if ($afterImg = $page->compareImageAfter()->toFile()): ?>
          <picture>
            <?php if ($afterMobile = $page->compareImageAfterMobile()->toFile()): ?>
              <source media="(max-width: 640px)" srcset="<?= $afterMobile->url() ?>">
            <?php endif ?>
            <img src="<?= $afterImg->url() ?>" alt="<?= $afterImg->alt()->esc() ?>" loading="lazy" decoding="async">
          </picture>
        <?php endif ?>
      </div>
      <div class="compare-slider__handle" id="compare-handle">
        <span class="compare-slider__line"></span>
        <span class="compare-slider__knob"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
            stroke-linejoin="round">
            <polyline points="18 8 22 12 18 16"></polyline>
            <polyline points="6 8 2 12 6 16"></polyline>
            <line x1="2" y1="12" x2="22" y2="12"></line>
          </svg></span>
        <span class="compare-slider__line"></span>
      </div>
    </div>
  </div>

  <div class="col-1 hidden md:block"></div>

  <div class="col-2 flex flex-col justify-end mt-6 md:mt-0">
    <h2 class="font-sans text-[clamp(1.25rem,2vw,1.75rem)] text-ink leading-snug mb-4">
      <?= $page->compareHeading()->or('Un jumeau numérique') ?>
    </h2>
    <p class="font-sans text-base text-ink leading-normal">
      <?= $page->compareText()->nl2br() ?>
    </p>
  </div>

</section>
<?php endif ?>

<?php if ($page->showSection('procede')): ?>
<section class="pt-12 pb-12 md:pb-20">
  <h2 class="col-7 font-sans text-[clamp(1.25rem,2vw,1.75rem)] text-ink leading-tight mb-6">
    <?= $page->procedeHeading()->or('Le procédé GOVR') ?>
  </h2>

  <div class="col-7 overflow-hidden rounded-md relative h-[300px] md:h-[500px] bg-surface" id="procede-images">
    <?php if ($f1 = $page->step1Image()->toFiles()->first()): ?>
      <div class="procede-image" data-step="0">
        <?php if ($f1->type() === 'video'): ?>
          <video src="<?= $f1->url() ?>" autoplay loop muted playsinline class="w-full h-full object-cover"></video>
        <?php else: ?>
          <img src="<?= $f1->url() ?>" alt="Acquisition" class="w-full h-full object-cover" loading="lazy" decoding="async">
        <?php endif ?>
      </div>
    <?php endif ?>

    <?php if ($f2 = $page->step2Image()->toFiles()->first()): ?>
      <div class="procede-image is-hidden" data-step="1">
        <?php if ($f2->type() === 'video'): ?>
          <video src="<?= $f2->url() ?>" autoplay loop muted playsinline class="w-full h-full object-cover"></video>
        <?php else: ?>
          <img src="<?= $f2->url() ?>" alt="Traitement" class="w-full h-full object-cover" loading="lazy" decoding="async">
        <?php endif ?>
      </div>
    <?php endif ?>

    <?php if ($f3 = $page->step3Image()->toFiles()->first()): ?>
      <div class="procede-image is-hidden" data-step="2">
        <?php if ($f3->type() === 'video'): ?>
          <video src="<?= $f3->url() ?>" autoplay loop muted playsinline class="w-full h-full object-cover"></video>
        <?php else: ?>
          <img src="<?= $f3->url() ?>" alt="Production" class="w-full h-full object-cover" loading="lazy" decoding="async">
        <?php endif ?>
      </div>
    <?php endif ?>
  </div>

  <div id="procede-steps-container" class="col-7 bg-surface rounded-md relative z-10 overflow-hidden p-4 pt-4">

    <div class="absolute top-0 left-0 w-full h-1 bg-border/50">
      <div id="procede-progress" class="h-full bg-mid w-0 transition-none"></div>
    </div>

    <div class="relative flex flex-col md:block md:h-52 gap-4" id="procede-steps">

      <div
        class="bg-white rounded-md p-6 cursor-pointer transition-all duration-200 ease-in-out hover:bg-[#f8f7f3] h-full flex flex-col justify-start w-full md:absolute md:top-0 md:w-[27%] md:left-0"
        data-step="0">
        <span class="font-sans text-5xl leading-none text-ink mb-3 block">01</span>
        <h3 class="font-sans text-lg text-ink mb-2"><?= $page->step1Title()->or('Acquisition') ?></h3>
        <p class="font-sans text-sm text-ink leading-normal line-clamp-3">
          <?= $page->step1Text()->nl2br() ?>
        </p>
      </div>

      <div
        class="bg-white rounded-md p-6 cursor-pointer transition-all duration-200 ease-in-out hover:bg-[#f8f7f3] h-full flex flex-col justify-start w-full md:absolute md:top-0 md:w-[27%] md:left-[42%]"
        data-step="1">
        <span class="font-sans text-5xl leading-none text-faint mb-3 block">02</span>
        <h3 class="font-sans text-lg text-ink mb-2"><?= $page->step2Title()->or('Traitement') ?></h3>
        <p class="font-sans text-sm text-ink leading-normal line-clamp-3">
          <?= $page->step2Text()->nl2br() ?>
        </p>
      </div>

      <div
        class="bg-white rounded-md p-6 cursor-pointer transition-all duration-200 ease-in-out hover:bg-[#f8f7f3] h-full flex flex-col justify-start w-full md:absolute md:top-0 md:w-[27%] md:left-[72%]"
        data-step="2">
        <span class="font-sans text-5xl leading-none text-faint mb-3 block">03</span>
        <h3 class="font-sans text-lg text-ink mb-2"><?= $page->step3Title()->or('Production') ?></h3>
        <p class="font-sans text-sm text-ink leading-normal line-clamp-3">
          <?= $page->step3Text()->nl2br() ?>
        </p>
      </div>

    </div>
  </div>
</section>
<?php endif ?>

<?php if ($page->showSection('manifesto')): ?>
<section class="py-12 md:py-20">

  <div class="col-7 bg-ink rounded-md overflow-hidden">

    <!-- Manifesto header -->
    <div class="px-6 md:px-16 pt-10 md:pt-14 pb-10 md:pb-12 border-b border-white/10">
      <p class="font-mono text-xs uppercase tracking-wider text-white/40 mb-6"><?= $page->manifestoTag()->esc() ?></p>
      <h2 class="font-thyssen text-[clamp(2.75rem,7vw,5.5rem)] text-white leading-[0.95]">
        <?= $page->manifestoHeading()->nl2br() ?>
      </h2>
    </div>

    <!-- Stats row -->
    <div class="grid grid-cols-1 md:grid-cols-3 px-6 md:px-16 py-10 md:py-12 gap-8 md:gap-0 border-b border-white/10">
      <div class="md:border-r md:border-white/10 md:pr-12">
        <p class="font-thyssen text-[4.5rem] text-white leading-none mb-3"><?= $page->stat1Value()->esc() ?></p>
        <p class="font-sans text-sm text-white/50 leading-relaxed"><?= $page->stat1Desc()->nl2br() ?></p>
      </div>
      <div class="md:border-r md:border-white/10 md:px-12">
        <p class="font-thyssen text-[4.5rem] text-white leading-none mb-3"><?= $page->stat2Value()->esc() ?></p>
        <p class="font-sans text-sm text-white/50 leading-relaxed"><?= $page->stat2Desc()->nl2br() ?></p>
      </div>
      <div class="md:pl-12">
        <p class="font-thyssen text-[4.5rem] text-white leading-none mb-3"><?= $page->stat3Value()->esc() ?></p>
        <p class="font-sans text-sm text-white/50 leading-relaxed"><?= $page->stat3Desc()->nl2br() ?></p>
      </div>
    </div>

    <!-- Footer: description + CTA -->
    <div class="px-6 md:px-16 py-8 md:py-10 flex flex-col md:flex-row md:items-center justify-between gap-6 md:gap-8">
      <p class="font-sans text-base text-white/60 leading-relaxed max-w-lg">
        <?= $page->manifestoFooterText()->nl2br() ?>
      </p>
      <a href="<?= url('contact') ?>" class="btn btn--secondary shrink-0 transition-colors duration-150">
        Protéger votre site
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="5" y1="12" x2="19" y2="12"></line>
          <polyline points="12 5 19 12 12 19"></polyline>
        </svg>
      </a>
    </div>

  </div>

</section>
<?php endif ?>

<?php if ($featured_project = $site->featured_project()->toPage()): ?>
<section class="py-12 md:py-20">
  <div class="col-7 flex flex-col md:flex-row gap-10 lg:gap-16 items-center w-full">
    <!-- Featured Project Image -->
    <div class="w-full md:w-3/5">
      <a href="<?= $featured_project->url() ?>" class="block rounded-md overflow-hidden aspect-video group">
        <?php if ($cover = $featured_project->cover()->toFile()): ?>
          <img src="<?= $cover->crop(1200, 675)->url() ?>" alt="<?= $cover->alt()->esc() ?>" loading="lazy" decoding="async" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
        <?php else: ?>
          <div class="w-full h-full bg-ink/10 flex items-center justify-center">
             <span class="text-faint">Pas d'image</span>
          </div>
        <?php endif ?>
      </a>
    </div>
    <!-- Featured Project Text -->
    <div class="w-full md:w-2/5 flex flex-col justify-center">
      <p class="font-mono text-xs uppercase tracking-wider text-faint mb-4">Projet à la une</p>
      <h3 class="font-thyssen text-4xl lg:text-5xl text-ink leading-snug mb-4">
        <a href="<?= $featured_project->url() ?>" class="hover:underline"><?= $featured_project->title()->esc() ?></a>
      </h3>
      <?php if ($featured_project->location()->isNotEmpty()): ?>
        <p class="font-mono text-xs uppercase text-faint mb-5 flex items-center gap-1">
          <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path>
            <circle cx="12" cy="10" r="3"></circle>
          </svg>
          <?= $featured_project->location()->esc() ?>
        </p>
      <?php endif ?>
      <p class="font-sans text-base text-mid leading-relaxed mb-8">
        <?= $featured_project->description()->isNotEmpty() ? $featured_project->description()->clean() : $featured_project->text()->toBlocks()->excerpt(150) ?>
      </p>
      <a href="<?= $featured_project->url() ?>" class="btn w-full md:w-auto justify-center self-start">Découvrir le projet</a>
    </div>
  </div>
</section>
<?php endif ?>

<?php if ($page->showSection('projects')): ?>
<section class="py-12 md:py-20">
  <h2 class="col-7 font-sans text-[clamp(1.25rem,2vw,1.75rem)] text-ink leading-tight mb-6">
    <?= $page->projectsHeading()->or('Nos derniers projets') ?>
  </h2>

  <div class="col-7 grid grid-cols-1 md:grid-cols-7 gap-6 md:gap-3">
    <?php
    $projects = page('map') ? page('map')->children()->listed()->filterBy('isPubliclyVisible', true)->sortBy('date', 'desc')->limit(3) : pages();
    foreach ($projects as $project):
      ?>
      <a href="<?= $project->url() ?>"
        class="col-span-1 md:col-span-2 block no-underline hover:no-underline group transition-transform duration-200">
        <div class="overflow-hidden rounded-md aspect-16/7 mb-3 bg-surface">
          <?php if ($cover = $project->cover()->toFile()): ?>
            <img src="<?= $cover->crop(800, 350)->url() ?>" alt="<?= $cover->alt()->esc() ?>" loading="lazy" decoding="async"
              class="w-full h-full object-cover">
          <?php endif ?>
        </div>
        <?php if ($project->location()->isNotEmpty()): ?>
          <p class="font-mono text-xs uppercase text-faint mb-2 flex items-center gap-1">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path>
              <circle cx="12" cy="10" r="3"></circle>
            </svg>
            <?= $project->location()->esc() ?>
          </p>
        <?php endif ?>
        <h3 class="font-thyssen text-4xl text-ink leading-snug mb-3 group-hover:underline">
          <?= $project->title()->esc() ?>
        </h3>

        <div class="flex flex-wrap gap-2">
          <?php foreach ($project->tags()->split(',') as $tag): ?>
            <span class="tag"><?= trim($tag) ?></span>
          <?php endforeach ?>
        </div>
      </a>
      <?php
    endforeach;
    ?>

    <!-- "Tous nos projets" Button acting as the 4th card in col-7 -->
    <a href="<?= url('map') ?>"
      class="btn border-border hover:bg-surface hover:border-surface col-span-1 md:col-span-1 flex flex-col items-center justify-center p-6 bg-transparent transition-colors duration-150 rounded-md no-underline group h-full min-h-[150px]">
      <!-- Enter key arrow (corner-down-left) -->
      <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
        class="text-ink mb-4 transition-transform">
        <polyline points="9 10 4 15 9 20"></polyline>
        <path d="M20 4v7a4 4 0 0 1-4 4H4"></path>
      </svg>
      <span class="font-mono text-xs uppercase tracking-wider text-ink text-center">Tous nos<br>projets</span>
    </a>
  </div>

</section>
<?php endif ?>

<?php if ($page->showSection('deliverables')): ?>
<section class="py-12 md:py-20">

  <!-- Left: intro + CTA (3 cols) -->
  <div class="col-3 flex flex-col justify-between">
    <div>
      <p class="font-mono text-xs uppercase tracking-wider text-faint mb-3"><?= $page->deliverablesTag()->esc() ?></p>
      <h2 class="font-thyssen text-[clamp(2rem,4vw,3.5rem)] text-ink leading-tight mt-1">
        <?= $page->deliverablesHeading()->nl2br() ?>
      </h2>
    </div>
    <div>
      <p class="font-sans text-base text-mid leading-relaxed mb-6">
        <?= $page->deliverablesText()->nl2br() ?>
      </p>
      <a href="<?= url('contact') ?>" class="btn w-full md:w-auto justify-center self-start mb-6 md:mb-0">
        Demander un devis
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="5" y1="12" x2="19" y2="12"></line>
          <polyline points="12 5 19 12 12 19"></polyline>
        </svg>
      </a>
    </div>
  </div>

  <!-- Spacer (1 col) -->
  <div class="col-1 hidden md:block"></div>

  <!-- Right: image (3 cols, portrait) -->
  <div class="col-3 overflow-hidden rounded-md bg-surface" style="min-height: 320px;">
    <?php if ($delivImg = $page->deliverablesImage()->toFile()): ?>
      <img src="<?= $delivImg->url() ?>" alt="<?= $delivImg->alt()->esc() ?>" loading="lazy" decoding="async" class="w-full h-full object-cover">
    <?php endif ?>
  </div>

  <!-- Full-width rule -->
  <div class="col-7 border-t border-border mt-12"></div>

  <!-- Three deliverables: col-2 | col-2 | col-3 -->
  <div class="col-2 pt-8 md:pr-8">
    <p class="font-mono text-xs text-faint mb-3">01</p>
    <h3 class="font-sans font-semibold text-base text-ink mb-2"><?= $page->deliv1Title()->esc() ?></h3>
    <p class="font-sans text-sm text-mid leading-relaxed"><?= $page->deliv1Text()->nl2br() ?></p>
  </div>

  <div class="col-2 pt-8 md:px-8 md:border-l md:border-border">
    <p class="font-mono text-xs text-faint mb-3">02</p>
    <h3 class="font-sans font-semibold text-base text-ink mb-2"><?= $page->deliv2Title()->esc() ?></h3>
    <p class="font-sans text-sm text-mid leading-relaxed"><?= $page->deliv2Text()->nl2br() ?></p>
  </div>

  <div class="col-3 pt-8 md:pl-8 md:border-l md:border-border">
    <p class="font-mono text-xs text-faint mb-3">03</p>
    <h3 class="font-sans font-semibold text-base text-ink mb-2"><?= $page->deliv3Title()->esc() ?></h3>
    <p class="font-sans text-sm text-mid leading-relaxed"><?= $page->deliv3Text()->nl2br() ?></p>
  </div>

</section>
<?php endif ?>

<?php if ($page->showSection('impact')): ?>
<section class="py-12 mb-8 md:mb-16">
  <div class="col-7 relative rounded-md overflow-hidden min-h-[40vh] md:min-h-[50vh] flex items-center justify-center p-6 md:p-12 bg-ink">
    <?php if ($impactImg = $page->impactImage()->toFile()): ?>
      <div class="absolute inset-0 opacity-20 mix-blend-luminosity pointer-events-none">
        <img src="<?= $impactImg->resize(1600)->url() ?>" alt="Statistiques" loading="lazy" decoding="async" class="w-full h-full object-cover">
      </div>
    <?php endif ?>

    <div class="relative z-10 flex flex-col items-start text-left max-w-3xl col-start-2 col-end-7">
      <?php if ($page->impactTag()->isNotEmpty()): ?>
        <p class="font-mono text-xs uppercase tracking-wider text-white/40 mb-6"><?= $page->impactTag()->esc() ?></p>
      <?php endif ?>
      <h2 class="font-thyssen text-3xl sm:text-5xl md:text-7xl text-white leading-tight mb-4 mt-4">
        <?= $page->impactHeading()->or('Préserver pour l\'éternité.') ?>
      </h2>
      <p class="font-sans text-sm md:text-lg text-white/80 mb-8 leading-relaxed">
        <?= $page->impactText()->nl2br() ?>
      </p>
      <div class="flex flex-col sm:flex-row gap-3 w-full">
        <a href="<?= url('contact') ?>"
          class="btn btn--secondary w-full sm:w-auto justify-center transition-colors duration-150">
          Contactez nous
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </a>
        <a href="<?= url('map') ?>"
          class="btn btn--orange w-full sm:w-auto transition-colors duration-150 justify-center">
          Explorer la carte
        </a>
      </div>
    </div>
  </div>
</section>
<?php endif ?>

<?php snippet('footer') ?>
