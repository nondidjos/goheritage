/**
 * GoHéritage — Map Page
 * MapLibre GL with MapTiler Backdrop style.
 * Reads window.HERITAGE_SITES (injected by map.php).
 * Includes search filtering for the project list.
 */

(function () {
    'use strict';

    const MAPTILER_KEY = 'YOUR_MAPTILER_KEY';
    const STYLE_URL = `https://api.maptiler.com/maps/backdrop/style.json?key=${MAPTILER_KEY}`;
    const SITES = window.HERITAGE_SITES || [];

    // default centre: metropolitan france / belgium
    const DEFAULT_CENTER = [2.3, 46.5];
    const DEFAULT_ZOOM = 5;

    // ── init map ──
    const map = new maplibregl.Map({
        container: 'heritage-map',
        style: STYLE_URL,
        center: DEFAULT_CENTER,
        zoom: DEFAULT_ZOOM,
        attributionControl: true,
    });

    map.addControl(new maplibregl.NavigationControl(), 'top-right');
    map.addControl(new maplibregl.ScaleControl({ maxWidth: 120, unit: 'metric' }), 'bottom-right');

    // ── state ──
    const markers = {};
    const popups = {};
    let activeId = null;

    // ── build markers ──
    SITES.forEach(site => {
        if (!site.lat || !site.lng) return;

        const el = document.createElement('div');
        el.className = 'map-marker';
        el.title = site.title;
        el.setAttribute('role', 'button');
        el.setAttribute('aria-label', site.title);

        const popup = new maplibregl.Popup({
            closeButton: true,
            closeOnClick: false,
            offset: 30,
            maxWidth: '240px',
        }).setHTML(`
      <p class="popup-location">${escHtml(site.location)}</p>
      <p class="popup-title">${escHtml(site.title)}</p>
      <p class="popup-desc">${escHtml(site.desc)}</p>
      <a class="popup-link" href="${escHtml(site.url)}">Voir le projet →</a>
    `);

        const marker = new maplibregl.Marker({ element: el })
            .setLngLat([site.lng, site.lat])
            .setPopup(popup)
            .addTo(map);

        el.addEventListener('click', (e) => {
            e.stopPropagation();
            activateSite(site.id, false);
        });

        markers[site.id] = marker;
        popups[site.id] = popup;
    });

    // ── list item interaction ──
    const listItems = document.querySelectorAll('.map-card');

    listItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const id = item.dataset.id;
            activateSite(id, true);
        });
    });

    // ── search functionality ──
    const searchInput = document.getElementById('map-search');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.toLowerCase().trim();
            listItems.forEach(item => {
                const title = (item.querySelector('.map-card__title')?.textContent || '').toLowerCase();
                const location = (item.querySelector('.map-card__location')?.textContent || '').toLowerCase();
                const desc = (item.querySelector('.map-card__desc')?.textContent || '').toLowerCase();
                const matches = !query || title.includes(query) || location.includes(query) || desc.includes(query);
                item.style.display = matches ? '' : 'none';
            });
        });
    }

    // ── activate site ──
    function activateSite(id, fly) {
        if (activeId && activeId !== id) {
            deactivate(activeId);
        }

        activeId = id;

        const site = SITES.find(s => s.id === id);
        if (!site) return;

        // highlight list item
        const listItem = document.querySelector(`.map-card[data-id="${id}"]`);
        if (listItem) {
            document.querySelectorAll('.map-card').forEach(el => el.classList.remove('is-active'));
            listItem.classList.add('is-active');
            listItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // activate marker
        if (markers[id]) {
            markers[id].getElement().classList.add('is-active');
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

        // open popup after brief delay
        setTimeout(() => {
            if (popups[id]) {
                popups[id].addTo(map);
            }
        }, fly ? 300 : 0);
    }

    function deactivate(id) {
        const listItem = document.querySelector(`.map-card[data-id="${id}"]`);
        if (listItem) listItem.classList.remove('is-active');
        if (markers[id]) markers[id].getElement().classList.remove('is-active');
        if (popups[id]) popups[id].remove();
    }

    // close active on map click
    map.on('click', () => {
        if (activeId) deactivate(activeId);
        activeId = null;
    });

    // ── mobile: toggle panel ──
    const panel = document.getElementById('map-panel');
    const closeBtn = document.getElementById('map-panel-close');
    if (closeBtn && panel) {
        closeBtn.addEventListener('click', () => {
            panel.classList.toggle('is-collapsed');
        });
    }

    // ── utility ──
    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

})();