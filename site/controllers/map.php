<?php

return function ($page) {

    $projects = $page->children()->listed()->sortBy('title', 'asc');
    $sitesData = [];

    foreach ($projects as $p) {
        $cover = $p->cover();
        $sitesData[] = [
            'id' => $p->slug(),
            'title' => $p->title()->value(),
            'location' => $p->location()->value(),
            'desc' => $p->description()->value(),
            'lat' => (float) $p->lat()->value(),
            'lng' => (float) $p->lng()->value(),
            'url' => $p->url(),
            'thumb' => $cover ? $cover->resize(160, 120)->url() : null,
            'tags' => array_values(array_filter(array_map('trim', $p->tags()->split(',')))),
        ];
    }

    return [
        'projects' => $projects,
        'sitesJson' => json_encode($sitesData, JSON_PRETTY_PRINT),
    ];

};
