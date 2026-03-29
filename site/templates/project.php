<?php
snippet('header');

// auto-detect 3d model files from this page's directory
// glb (draco-compressed) is preferred, obj is the fallback
$glbFile  = $page->files()->filterBy('extension', 'glb')->first();
$objFile  = $page->model_obj()->toFile()
    ?? $page->files()->filterBy('extension', 'obj')->first();
$texFile  = $page->model_texture()->toFile()
    ?? $page->files()->filterBy('extension', 'jpg')->filter(fn($f) => str_ends_with($f->name(), '-compressed'))->first()
    ?? $page->files()->filterBy('extension', 'in', ['png', 'jpg', 'jpeg'])
               ->filter(function($f) {
                   $name = strtolower($f->filename());
                   return strpos($name, 'diffuse') !== false
                       || strpos($name, 'texture') !== false
                       || strpos($name, 'color') !== false;
               })
               ->first()
    ?? $page->files()->filterBy('extension', 'in', ['png', 'jpg', 'jpeg'])
               ->not($page->cover())->first();

$viewerUrl = $page->viewer_url()->isNotEmpty() ? $page->viewer_url()->esc() : null;
$viewerLabel = $page->viewer_label()->isNotEmpty() ? $page->viewer_label()->esc() : 'Explorer le Modèle 3D';

// build annotation data from CMS structure field
$annotationsData = [];
if ($page->annotations()->isNotEmpty()) {
    foreach ($page->annotations()->toStructure() as $i => $ann) {
        $annotationsData[] = [
            'id'          => $ann->hotspot_id()->value(),
            'title'       => $ann->title()->value(),
            'description' => $ann->description()->value(),
        ];
    }
}
$annotationsJson = json_encode($annotationsData, JSON_UNESCAPED_UNICODE);

$glbUrl = $glbFile ? $glbFile->url() : null;
$objUrl = $objFile ? $objFile->url() : null;
$texUrl = $texFile ? $texFile->url() : null;

$hasIframe  = ($viewerUrl !== null);
$hasModel   = ($glbUrl !== null || $objUrl !== null);

$posterUrl = ($cover = $page->cover())
    ? $cover->crop(1600, 700)->url()
    : url('assets/hero-images/Seattle-Art-Museum-good-scan-60070.jpg');

$gallery = $page->images()
    ->filterBy('extension', 'in', ['jpg', 'jpeg', 'png', 'webp'])
    ->filter(fn($f) => !str_contains(strtolower($f->filename()), 'diffuse')
                    && !str_contains(strtolower($f->filename()), 'texture'))
    ->sortBy('sort');
?>

<div class="items-start pt-4 pb-20">

    <!-- ── Left: Content & Specs (2 cols) ── -->
    <div class="col-2 flex flex-col gap-8 pb-10">

        <!-- Location + Tags -->
        <div class="flex flex-wrap gap-2 items-center">
            <?php snippet('location-tag', ['location' => $page->location()->value()]) ?>
            <?php foreach ($page->tags()->split(',') as $tag): ?>
                <span class="tag"><?= trim($tag) ?></span>
            <?php endforeach ?>
        </div>

        <!-- Title -->
        <h1 class="font-thyssen text-5xl leading-tight"><?= $page->title()->esc() ?></h1>

        <!-- Description -->
        <div class="font-serif text-base text-ink/80 leading-relaxed">
            <?php if ($page->description()->isNotEmpty()): ?>
                <p><?= $page->description()->esc() ?></p>
            <?php else: ?>
                <p>Ce site témoigne d'une architecture exceptionnelle et d'une histoire qui s'étend sur plusieurs siècles. Sa préservation numérique par GoHéritage permet d'en explorer chaque recoin et d'assurer la transmission de ce patrimoine aux générations futures.</p>
            <?php endif ?>
        </div>

        <!-- Annotation panel (points of interest) -->
        <?php if (count($annotationsData) > 0): ?>
            <div class="annotation-panel">
                <h3 class="annotation-panel__heading">Points d'intérêt</h3>
                <div class="annotation-list">
                    <?php foreach ($annotationsData as $i => $ann): ?>
                        <button class="annotation-entry" data-hotspot="<?= htmlspecialchars($ann['id']) ?>">
                            <span class="annotation-entry__number"><?= str_pad($i + 1, 2, '0', STR_PAD_LEFT) ?></span>
                            <div class="annotation-entry__body">
                                <p class="annotation-entry__title"><?= htmlspecialchars($ann['title']) ?></p>
                                <?php if (!empty($ann['description'])): ?>
                                    <div class="annotation-entry__desc"><?= $ann['description'] ?></div>
                                <?php endif ?>
                            </div>
                        </button>
                    <?php endforeach ?>
                </div>
            </div>
        <?php endif ?>

        <!-- Rich text blocks -->
        <?php if ($page->text()->isNotEmpty()): ?>
            <div class="font-serif text-base text-ink leading-relaxed [&_h2]:font-sans [&_h2]:text-xl [&_h2]:mb-3 [&_h2]:mt-8 [&_p]:mb-4">
                <?= $page->text()->toBlocks() ?>
            </div>
        <?php else: ?>
            <div class="font-serif text-base text-ink/70 leading-relaxed flex flex-col gap-4">
                <p>Ce patrimoine architectural, fruit d'un savoir-faire ancestral, abrite une richesse ornementale et structurelle rarement égalée dans la région. La documentation photogrammétrique réalisée par nos équipes a permis de capturer près de trois mille clichés haute résolution, dont la restitution numérique restitue avec une précision millimétrique chaque moulure, chaque joint de pierre et chaque degré d'inclinaison des toitures.</p>
                <p>L'accès au modèle 3D interactif offre aux chercheurs, aux étudiants en architecture et au grand public une fenêtre inédite sur l'édifice, indépendamment de toute contrainte géographique ou horaire. Chaque visite virtuelle peut se prolonger par l'exploration de la galerie photographique et de la fiche technique disponibles dans ce panneau.</p>
            </div>
        <?php endif ?>

        <!-- Spec Sheet -->
        <div class="border-t border-border pt-6">
            <h3 class="font-mono text-xs uppercase tracking-widest text-faint mb-5">Fiche technique</h3>
            <dl class="flex flex-col gap-4">
                <?php if ($page->construction_date()->isNotEmpty()): ?>
                    <div>
                        <dt class="font-mono text-xs uppercase tracking-widest text-faint mb-1">Date de construction</dt>
                        <dd class="font-serif text-sm text-ink"><?= $page->construction_date()->esc() ?></dd>
                    </div>
                <?php endif ?>
                <?php if ($page->architect()->isNotEmpty()): ?>
                    <div>
                        <dt class="font-mono text-xs uppercase tracking-widest text-faint mb-1">Architecte</dt>
                        <dd class="font-serif text-sm text-ink"><?= $page->architect()->esc() ?></dd>
                    </div>
                <?php endif ?>
                <?php if ($page->style()->isNotEmpty()): ?>
                    <div>
                        <dt class="font-mono text-xs uppercase tracking-widest text-faint mb-1">Style</dt>
                        <dd class="font-serif text-sm text-ink"><?= $page->style()->esc() ?></dd>
                    </div>
                <?php endif ?>
                <?php if ($page->dimensions()->isNotEmpty()): ?>
                    <div>
                        <dt class="font-mono text-xs uppercase tracking-widest text-faint mb-1">Dimensions</dt>
                        <dd class="font-mono text-xs text-ink"><?= $page->dimensions()->esc() ?></dd>
                    </div>
                <?php endif ?>
                <?php if ($page->protection_status()->isNotEmpty()): ?>
                    <div class="pt-3 border-t border-border">
                        <dt class="font-mono text-xs uppercase tracking-widest text-faint mb-1.5">Protection</dt>
                        <dd>
                            <span class="inline-flex items-center gap-1.5 font-serif text-xs bg-mid/10 text-ink px-2 py-1 rounded-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                <?= $page->protection_status()->esc() ?>
                            </span>
                        </dd>
                    </div>
                <?php endif ?>
                <?php if ($page->condition()->isNotEmpty()): ?>
                    <div>
                        <dt class="font-mono text-xs uppercase tracking-widest text-faint mb-1">État</dt>
                        <dd class="font-serif text-xs text-accent flex items-center gap-1.5">
                            <div class="w-2 h-2 rounded-full bg-accent flex-shrink-0"></div>
                            <?= $page->condition()->esc() ?>
                        </dd>
                    </div>
                <?php endif ?>
            </dl>
        </div>

        <!-- Gallery -->
        <?php if ($gallery->count()): ?>
            <div>
                <h3 class="font-mono text-xs uppercase tracking-widest text-faint mb-3">Galerie</h3>
                <div class="grid grid-cols-2 gap-2">
                    <?php foreach ($gallery as $image): ?>
                        <a href="<?= $image->url() ?>" target="_blank"
                           class="block aspect-square overflow-hidden rounded-[4px] bg-border transition-transform hover:-translate-y-0.5">
                            <img src="<?= $image->crop(400, 400)->url() ?>"
                                 alt="<?= $image->alt()->or($page->title())->esc() ?>"
                                 loading="lazy" class="w-full h-full object-cover">
                        </a>
                    <?php endforeach ?>
                </div>
            </div>
        <?php endif ?>

        <!-- Additional images -->
        <?php $additionalImages = $page->additional_images()->toFiles(); ?>
        <?php if ($additionalImages->count()): ?>
            <div class="flex flex-col gap-3">
                <?php foreach ($additionalImages as $img): ?>
                    <a href="<?= $img->url() ?>" target="_blank"
                       class="block overflow-hidden rounded-[4px] bg-border hover:-translate-y-0.5 transition-transform">
                        <img src="<?= $img->resize(1200)->url() ?>"
                             alt="<?= $img->alt()->or($page->title())->esc() ?>"
                             loading="lazy" class="w-full h-auto object-cover">
                    </a>
                <?php endforeach ?>
            </div>
        <?php endif ?>

    </div>

    <!-- ── Right: 3D Viewer (5 cols, sticky) ── -->
    <div class="col-5 sticky top-6 h-[calc(100vh-5rem)] overflow-hidden rounded-[4px] relative bg-ink" id="viewer-container">

        <?php if ($hasIframe): ?>
            <div id="model-poster" class="absolute inset-0 cursor-pointer z-10 transition-opacity duration-500">
                <img src="<?= $posterUrl ?>" alt="<?= $page->title()->esc() ?>" class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-ink/30 flex items-center justify-center transition-colors group-hover:bg-ink/40">
                    <div class="bg-white/10 backdrop-blur-md border border-white/20 rounded-full w-20 h-20 flex items-center justify-center transform transition-transform duration-300 group-hover:scale-110">
                        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="white" class="ml-2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                    </div>
                </div>
                <div class="absolute bottom-6 left-1/2 -translate-x-1/2 bg-ink/80 backdrop-blur-md px-4 py-2 rounded-full">
                    <span class="font-sans text-xs text-white"><?= $viewerLabel ?></span>
                </div>
            </div>
            <div id="iframe-container" class="absolute inset-0 z-0"></div>
            <script>
            document.getElementById('model-poster').addEventListener('click', function() {
                this.style.opacity = '0';
                this.style.pointerEvents = 'none';
                const iframe = document.createElement('iframe');
                iframe.src = "<?= $viewerUrl ?>";
                iframe.className = "w-full h-full border-none";
                iframe.allowFullscreen = true;
                iframe.allow = "xr-spatial-tracking; fullscreen";
                document.getElementById('iframe-container').appendChild(iframe);
            });
            </script>

        <?php elseif ($hasModel): ?>
            <div id="viewer-3d"
                 class="w-full h-full bg-ink"
                 data-glb="<?= $glbUrl ?>"
                 data-obj="<?= $objUrl ?>"
                 data-texture="<?= $texUrl ?>"
                 data-draco-path="<?= url('node_modules/three/examples/jsm/libs/draco/') ?>"
                 data-annotations="<?= htmlspecialchars($annotationsJson) ?>">
            </div>
            <style>
              .viewer-progress {
                position: absolute; bottom: 30px; left: 50%; transform: translateX(-50%);
                width: 250px; text-align: center; pointer-events: none; z-index: 100;
                transition: opacity 0.4s;
              }
              .viewer-progress-bar {
                width: 100%; height: 2px;
                background: rgba(255,255,255,0.2);
                border-radius: 2px; margin-bottom: 8px; overflow: hidden;
              }
              .viewer-progress-fill {
                width: 0%; height: 100%;
                background: #fff;
                transition: width 0.2s;
              }
              .viewer-progress-text {
                font-family: var(--font-mono, monospace);
                font-size: 11px; text-transform: uppercase;
                letter-spacing: 0.08em; color: rgba(255,255,255,0.6);
              }
            </style>

        <?php else: ?>
            <img src="<?= $posterUrl ?>" alt="<?= $page->title()->esc() ?>" class="w-full h-full object-cover">
            <div class="absolute inset-0 bg-ink/30 flex items-center justify-center pointer-events-none">
                <span class="font-mono text-sm uppercase tracking-widest text-white px-6 py-2 border border-white/30 backdrop-blur-md rounded-sm">Vue 3D prochainement</span>
            </div>
        <?php endif ?>

    </div>

</div>

<?php snippet('footer') ?>
