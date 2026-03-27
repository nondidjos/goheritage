/**
 * GoHéritage — Map Page
 * MapLibre GL with MapTiler Backdrop style.
 */

(function () {
    'use strict';

    // ── Fit map layout to remaining viewport height ──────────────────────────
    // Gets the top position of the layout element and fills from there to the
    // bottom of the viewport. Runs on load and on resize. Reliable regardless
    // of header/breadcrumb height.
    var layout = document.getElementById('map-layout');

    function fitMapToScreen() {
        if (!layout) return;
        var top = layout.getBoundingClientRect().top;
        layout.style.height = (window.innerHeight - top) + 'px';
    }

    fitMapToScreen();
    window.addEventListener('resize', fitMapToScreen);

    // ── Init MapLibre ────────────────────────────────────────────────────────
    var mapEl = document.getElementById('heritage-map');
    var MAPTILER_KEY = atob(mapEl.dataset.key || '');
    var STYLE_URL = 'https://api.maptiler.com/maps/backdrop/style.json?key=' + MAPTILER_KEY;
    var SITES = JSON.parse(mapEl.dataset.sites || '[]');

    var DEFAULT_CENTER = [2.3, 46.5];
    var DEFAULT_ZOOM = 5;

    var map = new maplibregl.Map({
        container: 'heritage-map',
        style: STYLE_URL,
        center: DEFAULT_CENTER,
        zoom: DEFAULT_ZOOM,
        attributionControl: true,
    });

    map.addControl(new maplibregl.NavigationControl(), 'top-right');
    map.addControl(new maplibregl.ScaleControl({ maxWidth: 120, unit: 'metric' }), 'bottom-right');

    // ── State ────────────────────────────────────────────────────────────────
    var markers = {};
    var activeId = null;
    var selectedTags = new Set();

    // ── Build markers ────────────────────────────────────────────────────────
    SITES.forEach(function (site) {
        if (!site.lat || !site.lng) return;

        var el = document.createElement('div');
        el.className = 'map-marker';
        el.title = site.title;
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', site.title);

        var marker = new maplibregl.Marker({ element: el })
            .setLngLat([site.lng, site.lat])
            .addTo(map);

        el.addEventListener('click', function (e) {
            e.stopPropagation();
            activateSite(site.id, false);
        });

        markers[site.id] = marker;
    });

    // ── List card interaction ─────────────────────────────────────────────────
    var listItems = document.querySelectorAll('.map-card');

    listItems.forEach(function (item) {
        item.addEventListener('click', function (e) {
            // let the "Voir le projet" link navigate normally
            if (e.target.closest('.map-card__visit')) return;
            var id = item.dataset.id;
            activateSite(id, true);
        });
    });

    // ── Search + tag filter ──────────────────────────────────────────────────
    var searchInput = document.getElementById('map-search');

    function applyFilters() {
        var query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        var hasTags = selectedTags.size > 0;

        listItems.forEach(function (item) {
            var title = (item.querySelector('.map-card__title') || {}).textContent || '';
            var location = (item.querySelector('.map-card__location') || {}).textContent || '';
            var desc = (item.querySelector('.map-card__desc') || {}).textContent || '';
            var itemTags = JSON.parse(item.dataset.tags || '[]');

            var matchesQuery = !query ||
                title.toLowerCase().includes(query) ||
                location.toLowerCase().includes(query) ||
                desc.toLowerCase().includes(query);

            var matchesTags = !hasTags || itemTags.some(function (t) { return selectedTags.has(t); });

            item.style.display = (matchesQuery && matchesTags) ? '' : 'none';
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    // ── Tag filter panel ──────────────────────────────────────────────────────
    var filterBtn = document.getElementById('map-filter-btn');
    var filterPanel = document.getElementById('map-filter-panel');

    if (filterBtn && filterPanel) {
        var allTags = [];
        var seen = {};
        SITES.forEach(function (s) {
            (s.tags || []).forEach(function (t) {
                if (t && !seen[t]) { seen[t] = true; allTags.push(t); }
            });
        });
        allTags.sort();

        if (allTags.length > 0) {
            allTags.forEach(function (tag) {
                var btn = document.createElement('button');
                btn.className = 'tag';
                btn.textContent = tag;
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (selectedTags.has(tag)) {
                        selectedTags.delete(tag);
                        btn.classList.remove('tag--active');
                    } else {
                        selectedTags.add(tag);
                        btn.classList.add('tag--active');
                    }
                    updateFilterBtnState();
                    applyFilters();
                });
                filterPanel.appendChild(btn);
            });

            // clear button — same tag style, white bg + gray outline
            var clearBtn = document.createElement('button');
            clearBtn.className = 'tag tag--clear';
            clearBtn.textContent = '× Effacer tout';
            clearBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                selectedTags.clear();
                filterPanel.querySelectorAll('.tag:not(.tag--clear)').forEach(function (b) {
                    b.classList.remove('tag--active');
                });
                updateFilterBtnState();
                applyFilters();
                // deliberately do NOT close the panel
            });
            filterPanel.appendChild(clearBtn);

            filterBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                filterPanel.classList.toggle('is-open');
                filterBtn.classList.toggle('is-open');
            });

            document.addEventListener('click', function () {
                filterPanel.classList.remove('is-open');
                filterBtn.classList.remove('is-open');
            });

            filterPanel.addEventListener('click', function (e) { e.stopPropagation(); });
        } else {
            filterBtn.style.display = 'none';
        }
    }

    function updateFilterBtnState() {
        if (filterBtn) filterBtn.classList.toggle('has-active', selectedTags.size > 0);
    }

    // ── Activate site ─────────────────────────────────────────────────────────
    function activateSite(id, fly) {
        if (activeId && activeId !== id) deactivate(activeId);
        activeId = id;

        var site = SITES.find(function (s) { return s.id === id; });
        if (!site) return;

        // highlight list card
        listItems.forEach(function (el) { el.classList.remove('is-active'); });
        var listItem = document.querySelector('.map-card[data-id="' + id + '"]');
        if (listItem) {
            listItem.classList.add('is-active');
            listItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // activate marker
        if (markers[id]) markers[id].getElement().classList.add('is-active');

        // fly to location
        if (fly && site.lat && site.lng) {
            map.flyTo({
                center: [site.lng, site.lat],
                zoom: Math.max(map.getZoom(), 12),
                speed: 1.4,
                curve: 1.4,
            });
        }
    }

    function deactivate(id) {
        var listItem = document.querySelector('.map-card[data-id="' + id + '"]');
        if (listItem) listItem.classList.remove('is-active');
        if (markers[id]) markers[id].getElement().classList.remove('is-active');
    }

    map.on('click', function () {
        if (activeId) deactivate(activeId);
        activeId = null;
    });

    // ── Mobile panel toggle ───────────────────────────────────────────────────
    var panel = document.getElementById('map-panel');
    var closeBtn = document.getElementById('map-panel-close');
    if (closeBtn && panel) {
        closeBtn.addEventListener('click', function () {
            panel.classList.toggle('is-collapsed');
        });
    }

})();
