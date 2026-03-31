<?php
// home page — hero, compare slider, procédé, nos projets
snippet('header');
?>

<section class="items-end min-h-[85vh] pt-4 pb-16">

  <div class="col-2 flex flex-col justify-end pb-2">
    <h1 class="font-sans text-[clamp(1.25rem,2vw,1.75rem)] leading-tight text-ink mb-6">
      <?= $page->heroHeading()->or('Notre patrimoine, modélisé et accessible en 3D') ?>
    </h1>
    <div class="flex flex-col sm:flex-row gap-3 w-full">
      <!-- "Carte" uses an explicit lighter orange colour -->
      <a href="<?= url('map') ?>"
        class="btn flex-1 border-[4px] bg-[#f47a21] border-[#f47a21] text-ink hover:bg-[#d86616] hover:border-[#d86616] justify-center py-3 px-6 text-[1.1rem]">Voir
        la carte
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="7" y1="7" x2="17" y2="17"></line>
          <polyline points="17 7 17 17 7 17"></polyline>
        </svg></a>
      <a href="<?= url('contact') ?>"
        class="btn flex-1 justify-center border-[4px] hover:bg-surface hover:border-surface transition-colors duration-150">Nous contacter</a>
    </div>
  </div>

  <div class="col-5">
    <!-- removing aspect ratio constraints and just letting the image fill its natural column height natively -->
    <div class="w-full h-full bg-surface rounded-[4px] overflow-hidden min-h-[50vh]">
      <img src="<?= url('assets/hero-images/Wien-Museum-Online-Sammlung-311154-1-4.jpeg') ?>" alt="Patrimoine numérisé"
        class="w-full h-full object-cover" loading="eager">
    </div>
  </div>

</section>

<?php if ($featured_project = $site->featured_project()->toPage()): ?>
<section class="py-16 bg-surface col-7 -mx-4 px-4 md:px-12 mb-20 rounded-[4px] mt-10">
  <div class="col-7 flex flex-col md:flex-row gap-10 lg:gap-16 items-center w-full">
    <!-- Featured Project Image -->
    <div class="w-full md:w-3/5">
      <a href="<?= $featured_project->url() ?>" class="block rounded-[4px] overflow-hidden aspect-[16/9] group">
        <?php if ($cover = $featured_project->cover()->toFile()): ?>
          <img src="<?= $cover->crop(1200, 675)->url() ?>" alt="<?= $cover->alt()->esc() ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
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
      <a href="<?= $featured_project->url() ?>" class="btn self-start">Découvrir le projet</a>
    </div>
  </div>
</section>
<?php endif ?>

<section class="py-20">

  <div class="col-4">
    <div class="compare-slider" id="compare-slider">
      <div class="compare-slider__before">
        <?php if ($beforeImg = $page->compareImageBefore()->toFile()): ?>
          <img src="<?= $beforeImg->url() ?>" alt="<?= $beforeImg->alt()->esc() ?>" loading="lazy">
        <?php else: ?>
          <img src="<?= url('assets/hero-images/Seattle-Art-Museum-good-scan-60070.jpg') ?>" alt="Avant — photo"
            loading="lazy">
        <?php endif ?>
      </div>
      <div class="compare-slider__after">
        <?php if ($afterImg = $page->compareImageAfter()->toFile()): ?>
          <img src="<?= $afterImg->url() ?>" alt="<?= $afterImg->alt()->esc() ?>" loading="lazy">
        <?php else: ?>
          <img src="<?= url('assets/hero-images/threewomensquatting.jpg') ?>" alt="Après — modèle 3D" loading="lazy">
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

  <div class="col-1"></div>

  <div class="col-2 flex flex-col justify-end">
    <h2 class="font-sans text-[clamp(1.25rem,2vw,1.75rem)] text-ink leading-snug mb-4">
      <?= $page->compareHeading()->or('Un jumeau numérique') ?>
    </h2>
    <p class="font-sans text-base text-ink leading-normal">
      <?php if ($page->compareText()->isNotEmpty()): ?>
        <?= $page->compareText()->nl2br() ?>
      <?php else: ?>
        Grâce à la photogrammétrie et au scan 3D, nous créons des répliques numériques fidèles du patrimoine en danger.
        Ces modèles permettent une documentation précise, une analyse détaillée et une diffusion accessible à tous,
        chercheurs comme grand public.
      <?php endif ?>
    </p>
  </div>

</section>

<section class="pt-12 pb-20">
  <h2 class="col-7 font-sans text-[clamp(1.25rem,2vw,1.75rem)] text-ink leading-tight mb-6">
    <?= $page->procedeHeading()->or('Le procédé GOVR') ?>
  </h2>

  <div class="col-7 overflow-hidden rounded-[4px] aspect-[3/1] relative" id="procede-images">
    <?php if ($step1Img = $page->step1Image()->toFile()): ?>
      <img src="<?= $step1Img->url() ?>" alt="Acquisition" class="procede-image" data-step="0">
    <?php else: ?>
      <img src="<?= url('assets/hero-images/Илья_Репин_-_Какой_простор.jpeg') ?>" alt="Acquisition" class="procede-image"
        data-step="0">
    <?php endif ?>

    <?php if ($step2Img = $page->step2Image()->toFile()): ?>
      <img src="<?= $step2Img->url() ?>" alt="Traitement" class="procede-image is-hidden" data-step="1">
    <?php else: ?>
      <img src="<?= url('assets/hero-images/Wien-Museum-Online-Sammlung-311154-1-4.jpeg') ?>" alt="Traitement"
        class="procede-image is-hidden" data-step="1">
    <?php endif ?>

    <?php if ($step3Img = $page->step3Image()->toFile()): ?>
      <img src="<?= $step3Img->url() ?>" alt="Production" class="procede-image is-hidden" data-step="2">
    <?php else: ?>
      <img src="<?= url('assets/hero-images/Seattle-Art-Museum-good-scan-60070.jpg') ?>" alt="Production"
        class="procede-image is-hidden" data-step="2">
    <?php endif ?>
  </div>

  <div class="col-7 bg-surface rounded-[4px] relative overflow-hidden p-4 pt-4">

    <div class="absolute top-0 left-0 w-full h-1 bg-border/50">
      <div id="procede-progress" class="h-full bg-mid w-0 transition-none"></div>
    </div>

    <div class="relative flex flex-col md:block md:h-52 gap-4" id="procede-steps">

      <div
        class="bg-white rounded-[4px] p-6 cursor-pointer transition-all duration-200 ease-in-out hover:bg-[#f8f7f3] h-full flex flex-col justify-start md:absolute md:top-0 w-full md:w-[27%] md:left-0"
        data-step="0">
        <span class="font-sans text-5xl leading-none text-ink mb-3 block">1</span>
        <h3 class="font-sans text-lg text-ink mb-2"><?= $page->step1Title()->or('Acquisition') ?></h3>
        <p class="font-sans text-sm text-ink leading-normal">
          <?= $page->step1Text()->isNotEmpty() ? $page->step1Text()->nl2br() : 'Capture photographique haute résolution du patrimoine. Des centaines de clichés couvrent chaque angle et chaque détail.' ?>
        </p>
      </div>

      <div
        class="bg-white rounded-[4px] p-6 cursor-pointer transition-all duration-200 ease-in-out hover:bg-[#f8f7f3] h-full flex flex-col justify-start md:absolute md:top-0 w-full md:w-[27%] md:left-[42%]"
        data-step="1">
        <span class="font-sans text-5xl leading-none text-faint mb-3 block">2</span>
        <h3 class="font-sans text-lg text-ink mb-2"><?= $page->step2Title()->or('Traitement') ?></h3>
        <p class="font-sans text-sm text-ink leading-normal">
          <?= $page->step2Text()->isNotEmpty() ? $page->step2Text()->nl2br() : 'Reconstruction photogrammétrique et nettoyage des nuages de points pour former un modèle 3D fidèle.' ?>
        </p>
      </div>

      <div
        class="bg-white rounded-[4px] p-6 cursor-pointer transition-all duration-200 ease-in-out hover:bg-[#f8f7f3] h-full flex flex-col justify-start md:absolute md:top-0 w-full md:w-[27%] md:left-[72%]"
        data-step="2">
        <span class="font-sans text-5xl leading-none text-faint mb-3 block">3</span>
        <h3 class="font-sans text-lg text-ink mb-2"><?= $page->step3Title()->or('Production') ?></h3>
        <p class="font-sans text-sm text-ink leading-normal">
          <?= $page->step3Text()->isNotEmpty() ? $page->step3Text()->nl2br() : 'Optimisation pour le web, textures réalistes et mise en ligne sur la plateforme, accessible à tous.' ?>
        </p>
      </div>

    </div>
  </div>
</section>

<section class="py-20">

  <div class="col-7 bg-ink rounded-[4px] overflow-hidden">

    <!-- Manifesto header -->
    <div class="px-10 md:px-16 pt-14 pb-12 border-b border-white/10">
      <p class="font-mono text-xs uppercase tracking-wider text-white/40 mb-6">Conservation par le numérique</p>
      <h2 class="font-thyssen text-[clamp(2.75rem,7vw,5.5rem)] text-white leading-[0.95]">
        Le temps efface.<br>Nous préservons l'irremplaçable.
      </h2>
    </div>

    <!-- Stats row -->
    <div class="grid grid-cols-1 md:grid-cols-3 px-10 md:px-16 py-12 gap-10 md:gap-0 border-b border-white/10">
      <div class="md:border-r md:border-white/10 md:pr-12">
        <p class="font-thyssen text-[4.5rem] text-white leading-none mb-3">10×</p>
        <p class="font-sans text-sm text-white/50 leading-relaxed">plus rapide qu'un relevé terrain traditionnel pour une couverture complète d'un site</p>
      </div>
      <div class="md:border-r md:border-white/10 md:px-12">
        <p class="font-thyssen text-[4.5rem] text-white leading-none mb-3">0.1mm</p>
        <p class="font-sans text-sm text-white/50 leading-relaxed">de précision sur les mesures et relevés extraits du modèle photogrammétrique</p>
      </div>
      <div class="md:pl-12">
        <p class="font-thyssen text-[4.5rem] text-white leading-none mb-3">∞</p>
        <p class="font-sans text-sm text-white/50 leading-relaxed">accès au modèle, sans déplacement, sans risque pour le site original</p>
      </div>
    </div>

    <!-- Footer: description + CTA -->
    <div class="px-10 md:px-16 py-10 flex flex-col md:flex-row md:items-center justify-between gap-8">
      <p class="font-sans text-base text-white/60 leading-relaxed max-w-lg">
        Un jumeau numérique détecte les dégradations invisibles, prépare les interventions de restauration et ouvre votre patrimoine au monde entier.
      </p>
      <a href="<?= url('contact') ?>" class="btn border-white/40 text-white hover:bg-white hover:text-ink hover:border-white flex-shrink-0 transition-colors duration-150">
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

<section class="py-20">
  <h2 class="col-7 font-sans text-[clamp(1.25rem,2vw,1.75rem)] text-ink leading-tight mb-6">
    <?= $page->projectsHeading()->or('Nos derniers projets') ?>
  </h2>

  <div class="col-7 grid grid-cols-1 md:grid-cols-7 gap-6 md:gap-3">
    <?php
    $projects = page('map') ? page('map')->children()->listed()->sortBy('date', 'desc')->limit(3) : pages();
    if ($projects->count()):
      foreach ($projects as $project):
        ?>
        <a href="<?= $project->url() ?>"
          class="col-span-1 md:col-span-2 block no-underline hover:no-underline group transition-transform duration-200">
          <div class="overflow-hidden rounded-[4px] aspect-[16/7] mb-3 bg-surface">
            <?php if ($cover = $project->cover()->toFile()): ?>
              <img src="<?= $cover->crop(800, 350)->url() ?>" alt="<?= $cover->alt()->esc() ?>" loading="lazy"
                class="w-full h-full object-cover">
            <?php else: ?>
              <img src="<?= url('assets/hero-images/Seattle-Art-Museum-good-scan-60070.jpg') ?>" alt="Project preview"
                loading="lazy" class="w-full h-full object-cover">
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
    else:
      ?>
      <!-- fallback cards when no projects exist -->
      <a href="<?= url('map') ?>"
        class="col-span-1 md:col-span-2 block no-underline hover:no-underline group transition-transform duration-200">
        <div class="overflow-hidden rounded-[4px] aspect-[16/7] mb-3 bg-surface">
          <img src="<?= url('assets/hero-images/Wien-Museum-Online-Sammlung-311154-1-4.jpeg') ?>" alt="Projet"
            loading="lazy" class="w-full h-full object-cover">
        </div>
        <p class="font-mono text-xs uppercase text-faint mb-2 flex items-center gap-1">
          <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path>
            <circle cx="12" cy="10" r="3"></circle>
          </svg>
          Vienne, Autriche
        </p>
        <h3 class="font-thyssen text-4xl text-ink leading-snug mb-3 group-hover:underline">
          Musée de Vienne</h3>
        <div class="flex flex-wrap gap-2">
          <span class="tag">Architecture</span>
        </div>
      </a>
      <a href="<?= url('map') ?>"
        class="col-span-1 md:col-span-2 block no-underline hover:no-underline group transition-transform duration-200">
        <div class="overflow-hidden rounded-[4px] aspect-[16/7] mb-3 bg-surface">
          <img src="<?= url('assets/hero-images/Seattle-Art-Museum-good-scan-60070.jpg') ?>" alt="Projet" loading="lazy"
            class="w-full h-full object-cover">
        </div>
        <p class="font-mono text-xs uppercase text-faint mb-2 flex items-center gap-1">
          <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path>
            <circle cx="12" cy="10" r="3"></circle>
          </svg>
          Seattle, USA
        </p>
        <h3 class="font-thyssen text-4xl text-ink leading-snug mb-3 group-hover:underline">
          Seattle Art Museum</h3>
        <div class="flex flex-wrap gap-2">
          <span class="tag">Sculpture</span>
        </div>
      </a>
      <a href="<?= url('map') ?>"
        class="col-span-1 md:col-span-2 block no-underline hover:no-underline group transition-transform duration-200">
        <div class="overflow-hidden rounded-[4px] aspect-[16/7] mb-3 bg-surface">
          <img src="<?= url('assets/hero-images/s-l1600-2.jpg') ?>" alt="Projet" loading="lazy"
            class="w-full h-full object-cover">
        </div>
        <p class="font-mono text-xs uppercase text-faint mb-2 flex items-center gap-1">
          <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path>
            <circle cx="12" cy="10" r="3"></circle>
          </svg>
          Paris, France
        </p>
        <h3 class="font-thyssen text-4xl text-ink leading-snug mb-3 group-hover:underline">
          Cathédrale Saint-Denis</h3>
        <div class="flex flex-wrap gap-2">
          <span class="tag">Patrimoine</span>
        </div>
      </a>
    <?php endif ?>

    <!-- "Tous nos projets" Button acting as the 4th card in col-7 -->
    <a href="<?= url('map') ?>"
      class="btn border-[4px] border-border hover:bg-surface hover:border-surface col-span-1 md:col-span-1 flex flex-col items-center justify-center p-6 bg-transparent transition-colors duration-150 rounded-[4px] no-underline group h-full min-h-[150px]">
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

<section class="py-20">

  <!-- Left: intro + CTA (3 cols) -->
  <div class="col-3 flex flex-col justify-between">
    <div>
      <p class="font-mono text-xs uppercase tracking-wider text-faint mb-3">Métrologie & livrables</p>
      <h2 class="font-thyssen text-[clamp(2rem,4vw,3.5rem)] text-ink leading-tight mt-1">
        Du terrain<br>au plan côté.
      </h2>
    </div>
    <div>
      <p class="font-sans text-base text-mid leading-relaxed mb-6">
        Chaque numérisation produit des livrables exploitables immédiatement — plans, mesures précises, données brutes — compatibles avec les outils de votre équipe.
      </p>
      <a href="<?= url('contact') ?>" class="btn self-start">
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
  <div class="col-1"></div>

  <!-- Right: image (3 cols, portrait) -->
  <div class="col-3 overflow-hidden rounded-[4px] bg-surface" style="min-height: 320px;">
    <img src="<?= url('assets/hero-images/Wien-Museum-Online-Sammlung-311154-1-4.jpeg') ?>"
      alt="Relevé architectural" class="w-full h-full object-cover">
  </div>

  <!-- Full-width rule -->
  <div class="col-7 border-t border-border mt-12"></div>

  <!-- Three deliverables: col-2 | col-2 | col-3 -->
  <div class="col-2 pt-8 pr-8">
    <p class="font-mono text-xs text-faint mb-3">01</p>
    <h3 class="font-sans font-semibold text-base text-ink mb-2">Plans & élévations</h3>
    <p class="font-sans text-sm text-mid leading-relaxed">Coupes, façades, vues en élévation. Export DXF/DWG, précision millimétrique.</p>
  </div>

  <div class="col-2 pt-8 px-8 border-l border-border">
    <p class="font-mono text-xs text-faint mb-3">02</p>
    <h3 class="font-sans font-semibold text-base text-ink mb-2">Analyse dimensionnelle</h3>
    <p class="font-sans text-sm text-mid leading-relaxed">Distances, angles et superficies mesurés directement dans le modèle interactif.</p>
  </div>

  <div class="col-3 pt-8 pl-8 border-l border-border">
    <p class="font-mono text-xs text-faint mb-3">03</p>
    <h3 class="font-sans font-semibold text-base text-ink mb-2">Intégration BIM & SIG</h3>
    <p class="font-sans text-sm text-mid leading-relaxed">Formats IFC, Revit, QGIS. Données brutes E57/LAS disponibles. Le jumeau s'intègre directement dans vos workflows existants.</p>
  </div>

</section>

<section class="py-12 mb-16">
  <div class="col-7 relative rounded-[4px] overflow-hidden min-h-[50vh] flex items-center justify-center p-12 bg-ink">
    <div class="absolute inset-0 opacity-20 mix-blend-luminosity pointer-events-none">
      <?php if ($impactImg = $page->impactImage()->toFile()): ?>
        <img src="<?= $impactImg->url() ?>" alt="Statistiques" class="w-full h-full object-cover">
      <?php else: ?>
        <img src="<?= url('assets/hero-images/s-l1600-2.jpg') ?>" alt="Statistiques" class="w-full h-full object-cover">
      <?php endif ?>
    </div>

    <div class="relative z-10 flex flex-col items-center text-center max-w-3xl col-start-2 col-end-7 mx-auto">
      <h2 class="font-thyssen text-4xl sm:text-5xl md:text-7xl text-white leading-tight mb-6 mt-8">
        <?= $page->impactHeading()->or('Préserver pour l\'éternité.') ?>
      </h2>
      <p class="font-sans text-lg text-white/80 mb-10 leading-relaxed">
        <?php if ($page->impactText()->isNotEmpty()): ?>
          <?= $page->impactText()->nl2br() ?>
        <?php else: ?>
          Chaque modèle numérisé est une victoire contre le temps et l'oubli. Rejoignez notre base de données mondiale ou
          signalez-nous des éléments architecturaux en péril immédiat.
        <?php endif ?>
      </p>
      <div class="flex flex-col sm:flex-row flex-wrap gap-4 justify-center">
        <a href="<?= url('contact') ?>"
          class="btn border-[4px] !border-surface !text-surface hover:!bg-surface hover:!text-ink transition-colors duration-150">
          Contactez nous
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </a>
        <a href="<?= url('map') ?>"
          class="btn !bg-surface border-[4px] !border-surface !text-ink hover:!bg-white hover:!border-white transition-colors duration-150">
          Explorer la carte
        </a>
      </div>
    </div>
  </div>
</section>

<?php snippet('footer') ?>