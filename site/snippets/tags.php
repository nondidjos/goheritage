<?php
// renders comma separated tags as rounded pills
$tags = $tags ?? [];
if (empty($tags))
    return;
?>
<ul class="tag-list">
    <?php foreach ($tags as $tag): ?>
        <li class="tag-pill">
            <?= Str::esc(trim($tag)) ?>
        </li>
    <?php endforeach ?>
</ul>