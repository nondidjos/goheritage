<?php
// renders comma separated tags as rounded pills
$tags = $tags ?? [];
if (empty($tags))
    return;
?>
<ul class="flex flex-wrap gap-2">
    <?php foreach ($tags as $tag): ?>
        <li class="inline-flex items-center font-mono text-[11px] uppercase text-ink bg-surface px-4 py-1.5 rounded-sm">
            <?= Str::esc(trim($tag)) ?>
        </li>
    <?php endforeach ?>
</ul>