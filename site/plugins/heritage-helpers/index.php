<?php

/**
 * GoHéritage helpers — region inference, availability flags, owner contact
 * accessors. Lightweight, no models/blueprints — just functions + page methods
 * that derive marketplace metadata from existing project fields.
 */

Kirby::plugin('goheritage/helpers', [
    'pageMethods' => [

        /**
         * Region key inferred from the project's location string. Matches
         * Belgian and a few French regions; "autre" otherwise. Empty
         * location → null so callers can hide the badge entirely.
         */
        'region' => function () {
            $loc = strtolower((string) $this->location()->value());
            if ($loc === '') return null;
            $map = [
                'wallonie'  => ['wallonie', 'wallon', 'liège', 'liege', 'namur', 'hainaut', 'mons', 'charleroi', 'luxembourg', 'brabant wallon', 'tournai', 'verviers', 'spa', 'dinant'],
                'flandre'   => ['flandre', 'flamand', 'flanders', 'antwerpen', 'anvers', 'gand', 'gent', 'bruges', 'brugge', 'leuven', 'louvain', 'oost-vlaanderen', 'west-vlaanderen', 'limburg'],
                'bruxelles' => ['bruxelles', 'brussels', 'brussel'],
            ];
            foreach ($map as $key => $needles) {
                foreach ($needles as $n) {
                    if (str_contains($loc, $n)) return $key;
                }
            }
            return 'autre';
        },

        /** Human label for the region key. */
        'regionLabel' => function () {
            return match ($this->region()) {
                'wallonie'  => 'Wallonie',
                'flandre'   => 'Flandre',
                'bruxelles' => 'Bruxelles',
                'autre'     => 'Autre région',
                default     => null,
            };
        },

        /**
         * Returns the list of activity keys this project is available for.
         * Keys: visite, mariage, seminaire, tournage, hebergement, evenement.
         * Driven by checkboxes/toggles on the project page (defaults to []).
         */
        'availabilities' => function () {
            $raw = $this->avail_activities()->split(',');
            return array_values(array_filter(array_map('trim', $raw)));
        },

        /** True when the project lists at least one bookable activity. */
        'isBookable' => function () {
            return count($this->availabilities()) > 0;
        },

        /** Owner-side contact bundle. Returns an array; empty when unset. */
        'ownerContact' => function () {
            $bundle = [
                'name'    => (string) $this->owner_name(),
                'email'   => (string) $this->owner_email(),
                'phone'   => (string) $this->owner_phone(),
                'website' => (string) $this->owner_website(),
            ];
            return array_filter($bundle, fn ($v) => $v !== '');
        },
    ],

    'siteMethods' => [
        /**
         * Aggregate of (region key → count) across all listed projects.
         * Used by the home page region grid so counts come from real data,
         * not a hard-coded constant.
         */
        'regionCounts' => function () {
            $counts = ['wallonie' => 0, 'flandre' => 0, 'bruxelles' => 0, 'autre' => 0];
            $projects = page('map')?->children()->listed() ?? new \Kirby\Cms\Pages();
            foreach ($projects as $p) {
                $key = $p->region();
                if ($key && isset($counts[$key])) $counts[$key]++;
            }
            return $counts;
        },
    ],
]);

if (!function_exists('availabilityCatalog')) {
    /**
     * Canonical activity-key → metadata map. Single source of truth used by
     * the project blueprint, project template badges, contact form chips.
     */
    function availabilityCatalog(): array
    {
        return [
            'visite'      => ['label' => 'Visites guidées',         'icon' => 'walk'],
            'mariage'     => ['label' => 'Mariages',                'icon' => 'heart'],
            'seminaire'   => ['label' => 'Séminaires / réceptions', 'icon' => 'briefcase'],
            'tournage'    => ['label' => 'Tournages / shootings',   'icon' => 'camera'],
            'hebergement' => ['label' => 'Hébergement',             'icon' => 'bed'],
            'evenement'   => ['label' => 'Événements culturels',    'icon' => 'star'],
        ];
    }
}
