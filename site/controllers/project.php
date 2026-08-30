<?php
/**
 * Controller for site/templates/project.php.
 *
 * All the pure data work lives here: section visibility, asset URL
 * resolution, and the mode-switcher state machine. The template stays
 * render-only.
 *
 * The page-level access gate (token check + 404) is deliberately NOT
 * here — it's the single most security-sensitive check in this file, so
 * it stays at the very top of the template itself, somewhere a reviewer
 * trips over it immediately rather than inside a controller they might
 * not think to open. The gate does use $panelUser from this controller,
 * though, so it still only gets computed once.
 */
return function ($page) {

    $panelUser = kirby()->user();

    // Section visibility helper — panel users see everything; everyone else
    // respects the per-section toggles set on the page.
    $canSee = function (string $section) use ($page, $panelUser) {
        return $panelUser !== null || $page->sectionVisible($section);
    };

    $isEmbedded   = !empty(get('embed'));
    // viewer=only is an extra mode used by the CMS panel preview iframe —
    // hides the sidebar entirely so the viewer fills the iframe edge to edge.
    $isViewerOnly = $isEmbedded && get('viewer') === 'only';

    // Project pages use the same site header as the rest of the site (the
    // standalone back-to-map button lives outside the header). This flag
    // only exists to satisfy the header snippet's signature.
    $isVisitor = false;

    // ── Canonical point-cloud glyph ───────────────────────────────────────
    // One shape, used everywhere a point cloud is represented on the visitor
    // side (the mode dropdown + the "no cloud"/"unsupported" screens), so it
    // always matches the panel's `gh-pointcloud` tab icon. Geometry is
    // identical to that icon (project-ux/index.js): a big centre dot ringed
    // by 8 satellites on a circle of radius 7 around (12,12), at 45° steps.
    // viewBox is 24×24 and shapes fill with currentColor, so callers just
    // wrap this in their own <svg>.
    $pcDots =
          '<circle cx="12" cy="12" r="2.6"/>'        // centre (biggest)
        . '<circle cx="12" cy="5" r="1.3"/>'         // ring of 8, clockwise from top
        . '<circle cx="16.95" cy="7.05" r="1.3"/>'
        . '<circle cx="19" cy="12" r="1.3"/>'
        . '<circle cx="16.95" cy="16.95" r="1.3"/>'
        . '<circle cx="12" cy="19" r="1.3"/>'
        . '<circle cx="7.05" cy="16.95" r="1.3"/>'
        . '<circle cx="5" cy="12" r="1.3"/>'
        . '<circle cx="7.05" cy="7.05" r="1.3"/>';

    // ── Point-cloud sources ────────────────────────────────────────────────
    // Shared by the ?pointcloud=1 embed pane (its own snippet, see the
    // template) and the main page's point-cloud switcher pane — computed
    // once here so the two can't drift out of sync with each other.
    $pcExternal = $page->pointcloud_url()->isNotEmpty() ? $page->pointcloud_url()->value() : null;
    $pcCopc     = $page->copcFile();
    $pcInline   = $page->files()->filterBy('extension', 'in', ['ply', 'pcd'])->sortBy('modified', 'desc')->first();
    $pcOther    = $page->files()->filterBy('extension', 'in', ['las', 'laz', 'e57', 'xyz', 'pts'])->sortBy('modified', 'desc')->first();

    // DRACO decoder ships with three.js. We use the local copy in
    // node_modules so we don't rely on external CDNs which can fail
    // on the first load due to network/DNS timeouts.
    $dracoPath = url('node_modules/three/examples/jsm/libs/draco/');

    // Canonical filenames are set by the upload-overwrite plugin at upload time.
    // The modelFile() page method (model-converter) owns the canonical-name →
    // extension-variants → field-UUID fallback chain for each slot.
    $objFile          = $page->modelFile('obj');
    $interiorObjFile  = $page->modelFile('obj_interior');

    $texFile          = $page->modelFile('texture');
    $normFile         = $page->modelFile('normal');
    $interiorTexFile  = $page->modelFile('texture_interior');
    $interiorNormFile = $page->modelFile('normal_interior');

    // Progressive loading previews (auto-generated 1024 px JPEG companions)
    $texPreviewFile         = $texFile
        ? $page->file(pathinfo($texFile->filename(), PATHINFO_FILENAME) . '-preview.jpg') : null;
    $interiorTexPreviewFile = $interiorTexFile
        ? $page->file(pathinfo($interiorTexFile->filename(), PATHINFO_FILENAME) . '-preview.jpg') : null;

    $hotspotsExtFile = $page->modelFile('hotspots');
    $hotspotsIntFile = $page->modelFile('hotspots_interior');
    $hotspotsExtUrl  = $hotspotsExtFile ? $hotspotsExtFile->url() : null;
    $hotspotsIntUrl  = $hotspotsIntFile ? $hotspotsIntFile->url() : null;

    $viewerUrl   = $page->viewer_url()->isNotEmpty() ? $page->viewer_url()->esc() : null;
    $viewerLabel = $page->viewer_label()->isNotEmpty() ? $page->viewer_label()->esc() : 'Explorer le Modèle 3D';

    // Annotation data for the viewer, shaped by the model-converter plugin
    // (id/title/description/camera_mode/location) — see annotationsPayload().
    $annotationsJson = json_encode($page->annotationsPayload(), JSON_UNESCAPED_UNICODE);

    $objUrl                = $page->assetUrl($objFile);
    $interiorObjUrl        = $page->assetUrl($interiorObjFile);
    $interiorTexUrl        = $page->assetUrl($interiorTexFile);
    $interiorNormUrl       = $page->assetUrl($interiorNormFile);
    $texUrl                = $page->assetUrl($texFile);
    $normUrl               = $page->assetUrl($normFile);
    $texPreviewUrl         = $texPreviewFile         ? $texPreviewFile->url()         : null;
    $interiorTexPreviewUrl = $interiorTexPreviewFile ? $interiorTexPreviewFile->url() : null;

    // GLB: canonical filename, else field UUID, else best-guess fallback —
    // see exteriorGlbFile() (model-converter) for the full chain.
    $interiorGlbFile = $page->modelFile('glb_interior');
    $interiorGlbUrl  = $page->assetUrl($interiorGlbFile);
    $glbFile         = $page->exteriorGlbFile();
    $glbUrl          = $page->assetUrl($glbFile);

    $hasIframe = ($viewerUrl !== null);
    $hasModel  = ($objUrl !== null || $interiorObjUrl !== null || $glbUrl !== null || $interiorGlbUrl !== null);

    // Visibility: when the owner has not exposed the 3D model section,
    // suppress the viewer/iframe entirely and fall through to the poster
    // image. Admins keep full access (handled via $canSee).
    if (!$canSee('model')) {
        $hasIframe = false;
        $hasModel  = false;
    }

    // Annotations follow the same gating: if hidden, blank the JSON so the
    // viewer doesn't render hotspot markers at all.
    if (!$canSee('annotations')) {
        $annotationsJson = '[]';
    }

    $defaultSide = $page->model_toggle()->isTrue() ? 'interior' : 'exterior';

    $posterUrl = ($cover = $page->cover()->toFile())
        ? $cover->crop(1600, 700)->url()
        : null;

    $gallery = $page->galleryImages();

    // ── View-mode chips ─────────────────────────────────────────────────────
    // The right-hand viewer area can swap between 3D model, fullscreen image
    // gallery, and fullscreen plans. Each mode is its own pane inside
    // #viewer-container; floating chips on top let the visitor switch.
    //
    // A mode only contributes a chip + pane when it has content AND the
    // owner has opted to expose it via visibility ($canSee). When only one
    // mode is available we suppress the chip row entirely — a single chip
    // with no alternatives is just noise.
    $plansList         = $page->plans();
    $hasGalleryPane    = $canSee('gallery') && $gallery->count() > 0;
    $hasPlansPane      = $canSee('plans')   && $plansList && $plansList->count() > 0;
    // Point cloud — an external Potree/web viewer URL or an uploaded PLY/PCD.
    // Rendered as a 4th switcher pane (lazy iframe into ?pointcloud=1) so
    // visitors can reach it without leaving the page.
    $hasPointcloudPane = $canSee('pointcloud') && ($pcExternal !== null || $pcInline !== null || $pcCopc !== null);
    // The model pane is always present — even when there's nothing to show
    // it falls back to the cover image / "Vue 3D prochainement" placeholder,
    // which is the page's intended hero. So we don't gate it on $hasModel.
    $hasModelPane = true;

    // The model pane carries an ACTUAL interactive viewer only when there's
    // a 3D model or an external viewer URL. Otherwise it's just the
    // cover-image placeholder ("Vue 3D prochainement") — present as a
    // fallback, but it must NOT win the default when real content (a point
    // cloud, gallery…) exists.
    $hasRealModel = $hasModel || $hasIframe;

    // Switcher order is fixed (model · gallery · plans · point cloud) so the
    // chip row reads consistently across projects. The model button only
    // appears when there's an ACTUAL 3D model/viewer — otherwise the model
    // pane is just the "Vue 3D prochainement" placeholder, and showing a
    // "Modèle 3D" button on a point-cloud- or gallery-only project is
    // misleading. The pane still exists as the last-resort fallback (see
    // $defaultMode below); it just gets no chip.
    $availableModes = [];
    if ($hasRealModel)      $availableModes[] = 'model';
    if ($hasGalleryPane)    $availableModes[] = 'gallery';
    if ($hasPlansPane)      $availableModes[] = 'plans';
    if ($hasPointcloudPane) $availableModes[] = 'pointcloud';

    // The data-type switcher belongs to the VISITOR-facing site (and
    // external embeds) only — NOT the CMS panel preview (viewer=only),
    // which already has its own per-type editing tabs (Modèle 3D / Nuage de
    // points). Showing it there would be a redundant control inside the
    // panel's own viewer.
    $showModeChips = count($availableModes) > 1 && !$isViewerOnly;

    // Default mode = the first pane that actually has content, by priority:
    //   real 3D model / external viewer  →  point cloud  →  gallery  →  plans
    // The empty model placeholder is the last resort, so a point-cloud-only
    // project (e.g. Hôtel Tassel) opens on its cloud instead of "Aucun
    // modèle". An explicit ?mode= override always wins (used by deep links
    // / the panel).
    $modeParam = get('mode');
    if ($modeParam && in_array($modeParam, $availableModes, true)) {
        $defaultMode = $modeParam;
    } elseif ($hasRealModel) {
        $defaultMode = 'model';
    } elseif ($hasPointcloudPane) {
        $defaultMode = 'pointcloud';
    } elseif ($hasGalleryPane) {
        $defaultMode = 'gallery';
    } elseif ($hasPlansPane) {
        $defaultMode = 'plans';
    } else {
        $defaultMode = 'model'; // placeholder fallback
    }

    // ── Spec sheet ──────────────────────────────────────────────────────────
    // Map protection status code → human-readable label so the public fiche
    // technique reads cleanly ("Classé Monument Historique" instead of the
    // raw "classé").
    $protectionLabels = [
        'classé'   => 'Classé Monument Historique',
        'unesco'   => 'Patrimoine mondial UNESCO',
        'regional' => 'Inventaire Régional',
        'none'     => 'Non protégé',
    ];
    $protectionRaw   = $page->protection_status()->value();
    $protectionLabel = $protectionLabels[$protectionRaw] ?? $protectionRaw;

    $specFields = [
        ['label' => 'Construction', 'value' => $page->construction_date()->value()],
        ['label' => 'Architecte',   'value' => $page->architect()->value()],
        ['label' => 'Style',        'value' => $page->style()->value()],
        ['label' => 'Dimensions',   'value' => $page->dimensions()->value()],
        // Skip "Non protégé" — only show protection when it's meaningful.
        ['label' => 'Protection',   'value' => ($protectionRaw && $protectionRaw !== 'none') ? $protectionLabel : ''],
    ];
    $hasSpecs = false;
    foreach ($specFields as $sf) { if (!empty($sf['value'])) { $hasSpecs = true; break; } }

    // ── Tags ────────────────────────────────────────────────────────────────
    // A tag is only linkified if at least one LISTED project on the map
    // actually carries it — otherwise the link would land on the map
    // filtered for a tag that yields zero results. Orphan tags render as
    // plain labels so the info is still shown but isn't a dead-end.
    $mapPage  = page('map');
    $liveTags = $mapPage ? $mapPage->children()->livePublicTags() : [];

    // ── Mode-switcher icons + labels ───────────────────────────────────────
    $modeIcons = [
        'model'      => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'gallery'    => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'plans'      => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
        'pointcloud' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none">' . $pcDots . '</svg>',
    ];
    $modeLabels = [
        'model'      => 'Modèle 3D',
        'gallery'    => 'Galerie',
        'plans'      => 'Plans',
        'pointcloud' => 'Nuage de points',
    ];

    return compact(
        'panelUser', 'canSee', 'isEmbedded', 'isViewerOnly', 'isVisitor', 'pcDots',
        'pcExternal', 'pcCopc', 'pcInline', 'pcOther',
        'dracoPath', 'objFile', 'interiorObjFile', 'texFile', 'normFile',
        'interiorTexFile', 'interiorNormFile', 'texPreviewFile', 'interiorTexPreviewFile',
        'hotspotsExtUrl', 'hotspotsIntUrl', 'viewerUrl', 'viewerLabel', 'annotationsJson',
        'objUrl', 'interiorObjUrl', 'interiorTexUrl', 'interiorNormUrl', 'texUrl', 'normUrl',
        'texPreviewUrl', 'interiorTexPreviewUrl', 'interiorGlbFile', 'interiorGlbUrl',
        'glbFile', 'glbUrl', 'hasIframe', 'hasModel', 'defaultSide', 'posterUrl', 'gallery',
        'plansList', 'hasGalleryPane', 'hasPlansPane', 'hasPointcloudPane', 'hasModelPane',
        'hasRealModel', 'availableModes', 'showModeChips', 'defaultMode',
        'specFields', 'hasSpecs', 'liveTags', 'modeIcons', 'modeLabels'
    );

};
