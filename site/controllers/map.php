<?php

return function ($page) {

    // Filter params from query string. Home page + project pages link here
    // with these set; map.js reads the data-attrs and pre-checks chips.
    $regionFilter   = strtolower(trim((string) get('region')));
    $categoryFilter = strtolower(trim((string) get('category')));
    $tagFilter      = strtolower(trim((string) get('tag')));
    $q              = strtolower(trim((string) get('q')));

    // Category → naive substring matchers against location + title + tags
    // (no category field on the model yet — derived heuristically).
    $categoryNeedles = [
        'chateaux'  => ['chateau', 'château'],
        'abbayes'   => ['abbaye', 'église', 'eglise', 'church', 'chapelle'],
        'jardins'   => ['jardin', 'parc', 'garden'],
        'demeures'  => ['demeure', 'manoir', 'maison', 'mansion'],
    ];

    $all = $page->children()->listed()->sortBy('title', 'asc');

    $filtered = $all->filter(function ($p) use ($regionFilter, $categoryFilter, $tagFilter, $q, $categoryNeedles) {
        if ($regionFilter !== '' && method_exists($p, 'region')) {
            if ($p->region() !== $regionFilter) return false;
        }
        if ($tagFilter !== '') {
            $tags = array_map('trim', $p->tags()->split(','));
            $tagsLower = array_map('strtolower', $tags);
            if (!in_array($tagFilter, $tagsLower, true)) return false;
        }
        if ($categoryFilter !== '' && isset($categoryNeedles[$categoryFilter])) {
            $hay = strtolower(implode(' ', [
                $p->title()->value(),
                $p->location()->value(),
                implode(' ', array_map('trim', $p->tags()->split(','))),
            ]));
            $hit = false;
            foreach ($categoryNeedles[$categoryFilter] as $n) {
                if (str_contains($hay, $n)) { $hit = true; break; }
            }
            if (!$hit) return false;
        }
        if ($q !== '') {
            $hay = strtolower(implode(' ', [
                $p->title()->value(),
                $p->location()->value(),
                $p->description()->value(),
                implode(' ', array_map('trim', $p->tags()->split(','))),
            ]));
            if (!str_contains($hay, $q)) return false;
        }
        return true;
    });

    $sitesData = [];
    foreach ($filtered as $p) {
        $cover = $p->cover();
        $sitesData[] = [
            'id'       => $p->slug(),
            'title'    => $p->title()->value(),
            'location' => $p->location()->value(),
            'desc'     => $p->description()->value(),
            'lat'      => (float) $p->lat()->value(),
            'lng'      => (float) $p->lng()->value(),
            'url'      => $p->url(),
            'thumb'    => $cover ? $cover->resize(160, 120)->url() : null,
            'tags'     => array_values(array_filter(array_map('trim', $p->tags()->split(',')))),
            'region'   => method_exists($p, 'region') ? $p->region() : null,
        ];
    }

    return [
        'projects'  => $filtered,
        'allCount'  => $all->count(),
        'sitesJson' => json_encode($sitesData, JSON_PRETTY_PRINT),
        'filters'   => [
            'region'   => $regionFilter,
            'category' => $categoryFilter,
            'tag'      => $tagFilter,
            'q'        => $q,
        ],
    ];
};
