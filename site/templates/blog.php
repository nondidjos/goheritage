<?php
$allArticles = $page->children()->listed()->sortBy('date', 'desc');

$featured = $site->featured_article()->toPage();
if (!$featured || !$featured->isListed()) {
    $featured = $allArticles->filterBy('featured', 'true')->first();
}
$flagship = $featured ?? $allArticles->first();
$remaining = $allArticles->not($flagship);
$recent   = $remaining->limit(4);

// Always render all non-flagship articles; tags filtered client-side, search server-side
$mainList = $remaining;
if ($q = get('q')) {
  $mainList = $remaining->search($q, 'title|text');
}
$allTags = $allArticles->pluck('tags', ',', true);
?>
<?php snippet('header') ?>

  <?php if ($flagship): ?>
    <div class="mb-20 lg:mb-32">

      <article class="col-4 group mb-16 lg:mb-0">
        <a href="<?= $flagship->url() ?>" class="block w-full overflow-hidden bg-surface relative aspect-[16/9] transition-transform duration-300 ease-out">
          <?php if ($cover = $flagship->cover()->toFile()): ?>
            <img src="<?= $cover->resize(1200, 675)->url() ?>" alt="<?= $cover->alt()->esc() ?>"
              class="w-full h-full object-cover">
          <?php else: ?>
            <img src="<?= url('assets/hero-images/Wien-Museum-Online-Sammlung-311154-1-4.jpeg') ?>" alt="<?= $flagship->title()->esc() ?>"
              class="w-full h-full object-cover">
          <?php endif ?>
        </a>

        <div class="flex justify-between items-start mt-3 mb-2">
          <div class="flex flex-wrap gap-2">
            <?php
              $tags = $flagship->tags()->split(',');
              $displayTags = array_slice($tags, 0, 2);
              $extra = count($tags) - 2;
            ?>
            <?php foreach ($displayTags as $tag): ?>
              <span class="tag"><?= trim($tag) ?></span>
            <?php endforeach ?>
            <?php if ($extra > 0): ?>
              <span class="tag" style="background-color:transparent;border:1px solid var(--color-border);">+<?= $extra ?></span>
            <?php endif ?>
          </div>
          <span class="byline"><?= $flagship->author()->isNotEmpty() ? $flagship->author()->esc() : 'GoHeritage' ?></span>
        </div>

        <div class="text-center w-full px-4">
          <h2 class="font-sans font-medium text-3xl md:text-4xl text-ink leading-tight mb-4 transition-colors tracking-tight">
            <a href="<?= $flagship->url() ?>"><?= $flagship->title()->esc() ?></a>
          </h2>
          <p class="article-excerpt max-w-3xl mx-auto">
            <?php
              $excerpt = $flagship->subheading()->isNotEmpty()
                ? $flagship->subheading()->value()
                : $flagship->text()->toBlocks()->excerpt(300);
              echo esc($excerpt ?: 'Découvrez notre dernier article sur le patrimoine numérisé, la photogrammétrie et la modélisation 3D au service de la préservation culturelle. Nous explorons les nouvelles méthodes de pointe employées pour archiver nos sites historiques majeurs.');
            ?>
          </p>
        </div>
      </article>

      <div class="col-1 hidden lg:block"></div>

      <aside class="col-2 flex flex-col justify-start">
        <div class="flex flex-col divide-y divide-border">
          <?php foreach ($recent as $article): ?>
            <article class="pt-5 pb-7 first:pt-0 group">
              <div class="flex justify-between items-center mb-3">
                <div class="flex flex-wrap gap-1.5">
                  <?php foreach (array_slice($article->tags()->split(','), 0, 2) as $tag): ?>
                    <span class="tag"><?= trim($tag) ?></span>
                  <?php endforeach ?>
                </div>
                <span class="byline"><?= $article->author()->isNotEmpty() ? $article->author()->esc() : 'GoHeritage' ?></span>
              </div>

              <h3 class="font-sans font-semibold text-2xl text-ink leading-snug mb-3 transition-colors tracking-tight">
                <a href="<?= $article->url() ?>" class="block"><?= $article->title()->esc() ?></a>
              </h3>

              <p class="article-excerpt">
                <?php
                  $excerpt = $article->subheading()->isNotEmpty()
                    ? $article->subheading()->value()
                    : $article->text()->toBlocks()->excerpt(300);
                  echo esc($excerpt ?: 'Un article sur la numérisation du patrimoine culturel, la préservation par la modélisation 3D et l\'accessibilité des collections pour le grand public. Ces avancées ouvrent des opportunités inédites pour les chercheurs et le grand public à travers le monde.');
                ?>
              </p>
            </article>
          <?php endforeach ?>
        </div>

        <a href="<?= $page->url() ?>"
          class="btn border-[4px] border-border hover:bg-surface hover:border-surface col-span-1 flex flex-col items-center justify-center p-6 bg-transparent transition-colors duration-150 no-underline group mt-6 min-h-[100px]">
          <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
            class="text-ink mb-3">
            <polyline points="9 10 4 15 9 20"></polyline>
            <path d="M20 4v7a4 4 0 0 1-4 4H4"></path>
          </svg>
          <span class="font-mono text-xs uppercase tracking-wider text-ink text-center">Tous les<br>articles</span>
        </a>
      </aside>

    </div>

    <!-- Main Article List -->
    <div class="border-t border-border pt-20 mb-20">
      
      <!-- Left sidebar: 2 columns (Search, Tags) -->
      <aside class="col-2 flex flex-col gap-10 pr-4 md:pr-8 mb-12 md:mb-0">

        <form action="<?= $page->url() ?>" method="GET">
          <div class="blog-search-bar">
            <svg class="blog-search-bar__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="search" name="q" value="<?= esc(get('q', '') ?? '') ?>" placeholder="Rechercher…"
                   class="blog-search-bar__input">
            <button type="submit" class="blog-search-bar__submit" aria-label="Rechercher">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
              </svg>
            </button>
          </div>
        </form>

        <div>
          <h3 class="font-sans font-semibold text-base text-ink mb-4">Thèmes</h3>
          <div class="flex flex-wrap gap-2" id="blog-tag-filters">
            <?php foreach ($allTags as $tag): ?>
              <button class="tag" data-filter-tag="<?= esc($tag) ?>">
                <?= esc($tag) ?>
              </button>
            <?php endforeach ?>
            <button id="blog-clear-tags" class="tag tag--clear hidden!">× Effacer tout</button>
          </div>
        </div>

      </aside>

      <!-- Right content: 5 columns (Articles) -->
      <div class="col-5 flex flex-col">

        <?php foreach ($mainList as $item): ?>
          <?php $itemTagsJson = htmlspecialchars(json_encode($item->tags()->split(',')), ENT_QUOTES, 'UTF-8') ?>
          <article class="grid grid-cols-1 md:grid-cols-5 gap-6 md:gap-8 border-b border-border pb-12 mb-12 last:border-b-0 last:mb-0 last:pb-0 group"
                   data-article-tags="<?= $itemTagsJson ?>">
            
            <!-- 2 columns of 5 for image -->
            <div class="md:col-span-2">
               <a href="<?= $item->url() ?>" class="block overflow-hidden relative rounded-[4px] h-48 bg-surface">
                 <?php if ($cover = $item->cover()->toFile()): ?>
                   <img src="<?= $cover->crop(600, 450)->url() ?>" alt="<?= $cover->alt()->esc() ?>" class="w-full h-full object-cover">
                 <?php else: ?>
                   <img src="<?= url('assets/hero-images/Wien-Museum-Online-Sammlung-311154-1-4.jpeg') ?>" alt="<?= $item->title()->esc() ?>" class="w-full h-full object-cover">
                 <?php endif ?>
               </a>
            </div>

            <!-- 3 columns of 5 for text -->
            <div class="md:col-span-3 flex flex-col justify-center">
              <div class="flex flex-wrap gap-2 mb-3">
                <?php foreach (array_slice($item->tags()->split(','), 0, 3) as $tag): ?>
                  <span class="tag"><?= trim($tag) ?></span>
                <?php endforeach ?>
              </div>
              <h3 class="font-sans font-semibold text-2xl text-ink leading-tight mb-3 transition-colors tracking-tight">
                <a href="<?= $item->url() ?>" class="hover:underline"><?= $item->title()->esc() ?></a>
              </h3>
              <p class="font-serif text-mid text-base leading-relaxed line-clamp-5">
                <?php
                  $excerpt = $item->subheading()->isNotEmpty()
                    ? $item->subheading()->value()
                    : $item->text()->toBlocks()->excerpt(450);
                  echo esc($excerpt ?: 'Un article sur la numérisation du patrimoine culturel, la préservation par la modélisation 3D et l\'accessibilité des collections pour le grand public. Ces avancées ouvrent des opportunités inédites pour les chercheurs et le grand public à travers le monde.');
                ?>
              </p>
              <div class="mt-5 flex items-center justify-between">
                <time class="font-mono text-xs text-faint uppercase tracking-wider"><?= $item->date()->toDate('d F Y') ?></time>
                <span class="byline"><?= $item->author()->isNotEmpty() ? $item->author()->esc() : 'GoHeritage' ?></span>
              </div>
            </div>
          </article>
        <?php endforeach ?>

        <?php if ($mainList->count() === 0): ?>
          <p class="font-sans text-faint text-lg text-center py-10 bg-surface rounded-[4px]">Aucun article ne correspond à votre recherche.</p>
        <?php endif ?>

        <!-- no-results message for JS tag filtering -->
        <p id="blog-no-results" class="hidden font-sans text-faint text-lg text-center py-10 bg-surface rounded-[4px]">
          Aucun article ne correspond aux thèmes sélectionnés.
        </p>
      </div>
    </div>
  <?php else: ?>
    <div class="col-7 py-20 text-center">
      <p class="font-sans text-faint text-lg">Aucun article publié pour le moment.</p>
    </div>
  <?php endif ?>

<?php snippet('footer') ?>