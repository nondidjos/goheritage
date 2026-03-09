<?php
// pin + city name tag
// usage: snippet('location-tag', ['location' => '...'])
$location = $location ?? '';
$class = $class ?? '';
if ($location === '')
    return;
?>
<p class="location-tag <?= $class ?>">
    <svg class="location-pin" width="10" height="12" viewBox="0 0 10 12" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path
            d="M5 0C2.24 0 0 2.24 0 5c0 3.75 5 7 5 7s5-3.25 5-7c0-2.76-2.24-5-5-5zm0 6.5C4.17 6.5 3.5 5.83 3.5 5S4.17 3.5 5 3.5 6.5 4.17 6.5 5 5.83 6.5 5 6.5z"
            fill="currentColor" />
    </svg>
    <?= Str::esc($location) ?>
</p>