/**
 * GoHéritage — Map Page
 * MapLibre GL with MapTiler Backdrop style.
 * Site data read from data-sites attribute on the map element.
 */

(function () {
    'use strict';

    // ── Mobile detection ─────────────────────────────────────────────────────
    var isMobile = window.matchMedia('(max-width: 60rem)').matches;

    // ── Fit layout to remaining viewport height ──────────────────────────────
    var layout = document.getElementById('map-layout');

    function fitMapToScreen() {
        if (!layout) return;
        if (isMobile) {
            // On mobile the panel is a fixed overlay; map fills remaining space
            var top = layout.getBoundingClientRect().top;
            layout.style.height = (window.innerHeight - top) + 'px';
        } else {
            var top = layout.getBoundingClientRect().top;
            layout.style.height = (window.innerHeight - top) + 'px';
        }
    }

    fitMapToScreen();
    window.addEventListener('resize', function () {
        isMobile = window.matchMedia('(max-width: 60rem)').matches;
        fitMapToScreen();
    });

    // ── Init MapLibre ────────────────────────────────────────────────────────
    var mapEl = document.getElementById('heritage-map');
    var MAPTILER_KEY = atob(mapEl.dataset.key || '');
    var STYLE_URL = 'https://api.maptiler.com/maps/streets-v2/style.json?key=' + MAPTILER_KEY;
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

    // Embed mode: dismiss the spinner as soon as the basemap is painted, not
    // when every tile has finished. Feels instant in an iframe.
    map.on('load', function () {
        if (typeof window.dismissEmbedLoader === 'function') {
            window.dismissEmbedLoader();
        }
    });

    // ── State ────────────────────────────────────────────────────────────────
    var markers = {};
    var popups = {};
    var activeId = null;
    var selectedTags = new Set();

    // ── Pre-select tag from URL if present ──
    var urlParams = new URLSearchParams(window.location.search);
    var initialTag = urlParams.get('tag');
    if (initialTag) {
        selectedTags.add(initialTag);
    }

    // ── Mobile bottom drawer (declared early so activateSite can call them) ──
    var panel       = document.getElementById('map-panel');
    var panelHandle = document.getElementById('map-panel-handle');
    var panelSearch = document.getElementById('map-panel-search');

    function expandDrawer() {
        if (panel) panel.classList.add('is-expanded');
    }

    function collapseDrawer() {
        if (panel) panel.classList.remove('is-expanded');
    }

    function toggleDrawer() {
        if (panel) panel.classList.toggle('is-expanded');
    }

    // ── Handle: drag up/down (or tap to toggle) ──
    if (panelHandle && panel) {
        var MAP_PEEK = 88;            // matches --drawer-peek in map.css
        var mDragging = false, mMoved = false;
        var mStartY = 0, mStartT = 0;

        function mCollapsedT() {
            return Math.max(0, panel.getBoundingClientRect().height - MAP_PEEK);
        }
        function mCurrentT() {
            return panel.classList.contains('is-expanded') ? 0 : mCollapsedT();
        }
        panelHandle.addEventListener('pointerdown', function (e) {
            mDragging = true; mMoved = false;
            mStartY = e.clientY; mStartT = mCurrentT();
            panel.classList.add('is-dragging');
            panelHandle.classList.add('is-dragging');
            try { panelHandle.setPointerCapture(e.pointerId); } catch (_) {}
        });
        document.addEventListener('pointermove', function (e) {
            if (!mDragging) return;
            var dy = e.clientY - mStartY;
            if (Math.abs(dy) > 4) mMoved = true;
            var t = Math.max(0, Math.min(mCollapsedT(), mStartT + dy));
            panel.style.transform = 'translateY(' + t + 'px)';
        }, { passive: true });
        function mEnd(e) {
            if (!mDragging) return;
            mDragging = false;
            panelHandle.classList.remove('is-dragging');
            var dy = (e.clientY || mStartY) - mStartY;
            var willExpand;
            if (!mMoved) {
                willExpand = !panel.classList.contains('is-expanded');
            } else {
                var thr = mCollapsedT() * 0.3;
                willExpand = panel.classList.contains('is-expanded') ? !(dy > thr) : (-dy > thr);
            }
            panel.classList.remove('is-dragging');
            panel.style.transform = '';
            panel.classList.toggle('is-expanded', willExpand);
        }
        document.addEventListener('pointerup', mEnd);
        document.addEventListener('pointercancel', mEnd);
    }

    // Search row taps toggle the drawer (but tapping the input always expands)
    if (panelSearch) panelSearch.addEventListener('click', function (e) {
        if (e.target.tagName === 'INPUT') { expandDrawer(); return; }
        toggleDrawer();
    });

    // Tapping the map collapses the drawer on mobile
    var mapContainer = document.getElementById('map-container');
    if (mapContainer) {
        mapContainer.addEventListener('click', function () {
            if (isMobile) collapseDrawer();
        });
    }

    // Close button (desktop hidden, fallback)
    var closeBtn = document.getElementById('map-panel-close');
    if (closeBtn && panel) {
        closeBtn.addEventListener('click', collapseDrawer);
    }

    // ── Desktop fold toggle: collapse the side panel completely ──
    var mapFoldToggle = document.getElementById('map-fold-toggle');
    if (mapFoldToggle) {
        mapFoldToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            document.body.classList.toggle('is-panel-collapsed');
            // Container size changed — MapLibre needs to recompute
            setTimeout(function () { map.resize(); }, 50);
            setTimeout(function () { map.resize(); }, 350);
        });
    }

    // Forward wheel events from popups so zooming still works without scrolling the page
    mapEl.addEventListener('wheel', function (e) {
        if (e.target.closest('.maplibregl-popup')) {
            e.preventDefault();
            var clone = new WheelEvent('wheel', {
                clientX: e.clientX,
                clientY: e.clientY,
                deltaX: e.deltaX,
                deltaY: e.deltaY,
                deltaZ: e.deltaZ,
                deltaMode: e.deltaMode,
                bubbles: true,
                cancelable: true
            });
            var canvas = map.getCanvasContainer();
            if (canvas) canvas.dispatchEvent(clone);
        }
    }, { passive: false });

    // ── Build markers + popups ───────────────────────────────────────────────
    SITES.forEach(function (site) {
        if (!site.lat || !site.lng) return;

        // marker element — teardrop pin shape
        var el = document.createElement('div');
        el.className = 'map-marker';
        el.title = site.title;
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', site.title);
        el.innerHTML =
            '<svg viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
              '<circle cx="14" cy="14" r="13" fill="currentColor" fill-opacity="0.18"/>' +
              '<circle cx="14" cy="14" r="7" fill="currentColor" stroke="white" stroke-width="2.5"/>' +
            '</svg>';

        var isEmbed = mapEl.dataset.embed === '1';
        var popupUrl = site.url + (isEmbed ? '?embed=1' : '');
        
        // popup — anchored above the marker, styled as a mini card
        var popup = new maplibregl.Popup({
            closeButton: true,
            closeOnClick: false,
            focusAfterOpen: false,
            offset: 28,
            maxWidth: '260px',
            anchor: 'bottom',
        }).setHTML(
            '<div class="popup-inner">' +
                '<p class="location-tag popup-location">' +
                    '<svg class="location-pin" width="10" height="12" viewBox="0 0 10 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 0C2.24 0 0 2.24 0 5c0 3.75 5 7 5 7s5-3.25 5-7c0-2.76-2.24-5-5-5zm0 6.5C4.17 6.5 3.5 5.83 3.5 5S4.17 3.5 5 3.5 6.5 4.17 6.5 5 5.83 6.5 5 6.5z" fill="currentColor" /></svg>' +
                    escHtml(site.location) +
                '</p>' +
                '<p class="popup-title">' + escHtml(site.title) + '</p>' +
            '</div>' +
            '<a class="btn popup-link" href="' + escHtml(popupUrl) + '">Voir le modèle →</a>'
        );

        // close popup when its own close button is clicked — also deactivate
        popup.on('close', function () {
            if (activeId === site.id) {
                activeId = null;
            }
        });

        new maplibregl.Marker({ element: el, anchor: 'center' })
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

        // "Centrer sur la carte" button → fly to + activate (desktop only)
        var centerBtn = item.querySelector('.map-card__btn-center');
        if (centerBtn) {
            centerBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                activateSite(id, true);
            });
        }

        // "Voir le projet" link navigates normally — just stop propagation
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
        // On mobile, focusing the search field expands the drawer
        searchInput.addEventListener('focus', function () {
            if (isMobile) expandDrawer();
        });
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
                if (selectedTags.has(tag)) {
                    btn.classList.add('tag--active');
                }
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

        // Apply filters initially in case a tag was selected via URL
        updateFilterBtnState();
        applyFilters();
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

        // elevate the selected pin
        if (markers[id]) markers[id].el.classList.add('is-active');

        // highlight list card (desktop only — on mobile drawer collapses)
        var listItem = document.querySelector('.map-card[data-id="' + id + '"]');
        if (listItem && !isMobile) {
            listItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

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

        // On mobile: collapse the drawer so the map + popup are visible
        if (isMobile) {
            collapseDrawer();
        }
    }

    function deactivate(id) {
        if (markers[id]) markers[id].el.classList.remove('is-active');
        if (popups[id]) popups[id].remove();
    }

    map.on('click', function () {
        if (activeId) deactivate(activeId);
        activeId = null;
    });

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
