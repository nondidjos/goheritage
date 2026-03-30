/**
 * GoHéritage — Map Page
 * MapLibre GL with MapTiler Backdrop style.
 * Site data read from data-sites attribute on the map element.
 */

(function () {
    'use strict';

    // ── Fit layout to remaining viewport height ──────────────────────────────
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

    var map = new maplibregl.Map({
        container: 'heritage-map',
        style: STYLE_URL,
        center: [2.3, 46.5],
        zoom: 5,
        attributionControl: true,
    });

    map.addControl(new maplibregl.NavigationControl(), 'top-right');
    map.addControl(new maplibregl.ScaleControl({ maxWidth: 120, unit: 'metric' }), 'bottom-right');

    // ── State ────────────────────────────────────────────────────────────────
    var markers = {};
    var popups = {};
    var activeId = null;
    var selectedTags = new Set();

    // ── Build markers + popups ───────────────────────────────────────────────
    SITES.forEach(function (site) {
        if (!site.lat || !site.lng) return;

        // marker element
        var el = document.createElement('div');
        el.className = 'map-marker';
        el.title = site.title;
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', site.title);

        // popup — anchored above the marker, styled as a mini card
        var popup = new maplibregl.Popup({
            closeButton: true,
            closeOnClick: false,
            offset: 28,
            maxWidth: '260px',
            anchor: 'bottom',
        }).setHTML(
            '<div class="popup-inner">' +
                '<p class="popup-location">' + escHtml(site.location) + '</p>' +
                '<p class="popup-title">' + escHtml(site.title) + '</p>' +
            '</div>' +
            '<a class="btn popup-link" href="' + escHtml(site.url) + '">Voir le modèle →</a>'
        );

        // close popup when its own close button is clicked — also deactivate
        popup.on('close', function () {
            if (activeId === site.id) {
                if (markers[site.id]) markers[site.id].getElement().classList.remove('is-active');
                activeId = null;
            }
        });

        new maplibregl.Marker({ element: el })
            .setLngLat([site.lng, site.lat])
            .addTo(map);

        // clicking the marker activates the site (no fly — marker is already visible)
        el.addEventListener('click', function (e) {
            e.stopPropagation();
            activateSite(site.id, false);
        });

        markers[site.id] = { el: el, lngLat: [site.lng, site.lat] };
        popups[site.id] = popup;
    });

    // ── List cards ───────────────────────────────────────────────────────────
    var listItems = document.querySelectorAll('.map-card');

    listItems.forEach(function (item) {
        var id = item.dataset.id;

        // clicking anywhere on the card (except the action buttons) → activate + fly
        item.addEventListener('click', function (e) {
            if (e.target.closest('.map-card__actions')) return;
            activateSite(id, true);
        });

        // "Centrer sur la carte" button → fly to + activate
        var centerBtn = item.querySelector('.map-card__btn--center');
        if (centerBtn) {
            centerBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                activateSite(id, true);
            });
        }

        // "Voir le modèle" link navigates normally — just stop propagation
        var visitLink = item.querySelector('.map-card__btn--visit');
        if (visitLink) {
            visitLink.addEventListener('click', function (e) {
                e.stopPropagation();
                // default navigation allowed
            });
        }
    });

    // ── Search + tag filter ──────────────────────────────────────────────────
    var searchInput = document.getElementById('map-search');

    function applyFilters() {
        var query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        var hasTags = selectedTags.size > 0;

        listItems.forEach(function (item) {
            var title = ((item.querySelector('.map-card__title') || {}).textContent || '').toLowerCase();
            var loc   = ((item.querySelector('.map-card__location') || {}).textContent || '').toLowerCase();
            var desc  = ((item.querySelector('.map-card__desc') || {}).textContent || '').toLowerCase();
            var tags  = JSON.parse(item.dataset.tags || '[]');

            var matchesQuery = !query || title.includes(query) || loc.includes(query) || desc.includes(query);
            var matchesTags  = !hasTags || tags.some(function (t) { return selectedTags.has(t); });

            item.style.display = (matchesQuery && matchesTags) ? '' : 'none';
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    // ── Tag filter panel ──────────────────────────────────────────────────────
    var filterBtn   = document.getElementById('map-filter-btn');
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
        var markerData = markers[id];
        if (markerData) markerData.el.classList.add('is-active');

        // show popup
        if (popups[id]) {
            popups[id].setLngLat([site.lng, site.lat]).addTo(map);
        }

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
        if (markers[id]) markers[id].el.classList.remove('is-active');
        if (popups[id]) popups[id].remove();
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

    // ── Utility ───────────────────────────────────────────────────────────────
    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

})();
