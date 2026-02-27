<?php snippet('header') ?>

<div class="container">
    <div class="article-header">
        <div class="grid-7">
            <div class="col-7">
                <p class="article-date font-mono">
                    <?php if ($page->date()->isNotEmpty()): ?>
                        <?= $page->date()->toDate('d F Y') ?>
                    <?php endif ?>
                </p>
                <h1 class="article-title font-gloucester" style="font-family: var(--font-gloucester);">
                    <?= $page->title()->esc() ?>
                </h1>
                <?php if ($page->location()->isNotEmpty()): ?>
                    <p class="article-sub font-mono"
                        style="font-size:var(--text-sm); color:var(--color-accent); margin-top:var(--baseline-half);">
                        <?= $page->location()->esc() ?>
                    </p>
                <?php endif ?>
            </div>
        </div>
    </div>

    <?php if ($cover = $page->cover()): ?>
        <div class="article-cover">
            <img src="<?= $cover->crop(1600, 800)->url() ?>" alt="<?= $cover->alt()->esc() ?>">
        </div>
    <?php endif ?>

    <div class="article-body">
        <div class="grid-7">
            <div class="col-5">
                <?php if ($page->description()->isNotEmpty()): ?>
                    <p class="font-serif"
                        style="font-size:var(--text-lg); color:var(--color-ink-mid); line-height:var(--baseline-2x); margin-bottom:var(--baseline-2x);">
                        <?= $page->description()->esc() ?>
                    </p>
                    <hr class="divider">
                <?php endif ?>

                <div class="text font-serif">
                    <?= $page->text()->toBlocks() ?>
                </div>

                <?php if ($page->tags()->isNotEmpty()): ?>
                    <ul class="article-tags" style="margin-top: var(--baseline-2x);">
                        <?php foreach ($page->tags()->split(',') as $tag): ?>
                            <li><span class="article-tag font-mono">
                                    <?= esc(trim($tag)) ?>
                                </span></li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </div>

            <div class="col-1"></div>

            <div class="col-1">
                <?php if ($page->location()->isNotEmpty()): ?>
                    <div style="margin-bottom:var(--baseline-2x);">
                        <p class="contact-info__label font-mono">Location</p>
                        <p class="contact-info__value font-serif">
                            <?= $page->location()->esc() ?>
                        </p>
                    </div>
                <?php endif ?>
                <?php if ($page->date()->isNotEmpty()): ?>
                    <div style="margin-bottom:var(--baseline-2x);">
                        <p class="contact-info__label font-mono">Scan Date</p>
                        <p class="contact-info__value font-serif">
                            <?= $page->date()->toDate('F Y') ?>
                        </p>
                    </div>
                <?php endif ?>
                <?php if ($page->lat()->isNotEmpty() && $page->lng()->isNotEmpty()): ?>
                    <div>
                        <p class="contact-info__label font-mono">Coordinates</p>
                        <p class="contact-info__value font-mono" style="font-size:var(--text-xs);">
                            <?= $page->lat()->value() ?>,
                            <?= $page->lng()->value() ?>
                        </p>
                    </div>
                <?php endif ?>

                <div style="margin-top:var(--baseline-2x);">
                    <a href="<?= $page->parent()->url() ?>" class="btn">← Back to Map</a>
                </div>
            </div>

        </div>
    </div>
</div>

<?php snippet('footer') ?>