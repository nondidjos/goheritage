<?php
/**
 * Compare slider block — before/after image reveal.
 * Drag to compare two images side by side.
 * Works with the compare-slider JS in assets/js/index.js.
 */

$beforeImage = $block->before_image()->toFile();
$afterImage = $block->after_image()->toFile();
?>

<?php if ($beforeImage && $afterImage): ?>
    <figure class="compare-block">
        <div class="compare-slider">
            <div class="compare-slider__before">
                <img src="<?= $beforeImage->url() ?>" alt="<?= $beforeImage->alt()->or('Avant')->esc() ?>" loading="lazy">
            </div>
            <div class="compare-slider__after">
                <img src="<?= $afterImage->url() ?>" alt="<?= $afterImage->alt()->or('Après')->esc() ?>" loading="lazy">
            </div>
            <div class="compare-slider__handle">
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

        <?php if ($block->caption()->isNotEmpty()): ?>
            <figcaption class="img-caption">
                <?= $block->caption()->html() ?>
            </figcaption>
        <?php endif ?>
    </figure>
<?php endif ?>