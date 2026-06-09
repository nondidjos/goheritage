<?php

return function ($page) {

    $projects = $page->children()->listed()->filterBy('isPubliclyVisible', true)->sortBy('title', 'asc');
    $sitesData = [];

    foreach ($projects as $p) {
        $cover  = $p->cover();
        $isMapOnly = $p->map_only()->isTrue();
        $type   = $isMapOnly ? 'fiche' : 'projet';
        $tags   = array_values(array_filter(array_map('trim', $p->tags()->split(','))));
        $tags[] = '__type:' . $type;           // synthetic tag drives the type filter

        $sitesData[] = [
            'id'       => $p->slug(),
            'title'    => $p->title()->value(),
            'location' => $p->location()->value(),
            'desc'     => $p->description()->value(),
            'lat'      => (float) $p->lat()->value(),
            'lng'      => (float) $p->lng()->value(),
            'url'      => $isMapOnly ? null : $p->url(),   // null → no popup link
            'thumb'    => $cover ? $cover->resize(160, 120)->url() : null,
            'tags'     => $tags,
        ];
    }

    return [
        'projects' => $projects,
        'sitesJson' => json_encode($sitesData, JSON_PRETTY_PRINT),
    ];

};
