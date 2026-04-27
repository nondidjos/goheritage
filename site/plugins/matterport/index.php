<?php
/**
 * matterport plugin
 *
 * Extracts sweep (panorama capture point) positions from a public Matterport
 * Showcase page without using the paid Model API. How it works:
 *
 *   1. Fetches the public Showcase HTML at https://my.matterport.com/show/?m=ID
 *   2. Regexes the `window.MP_PREFETCHED_MODELDATA = {...};` JSON blob out
 *      of the initial HTML (same trick as rebane2001/matterport-dl).
 *   3. Walks the embedded JSON for sweeps/locations and extracts
 *      `{ uuid, position, rotation }` — the uuid matches the
 *      `{uuid}_skybox{0..5}.jpg` filenames in the bundle export.
 *   4. Writes the data to the page as:
 *        • `pano-hotspots.json`  — goheritage format; drives dollhouse markers
 *        • `matterport-sweeps.obj` — one vertex per sweep, for Blender alignment
 *
 * Zero credentials, zero paid add-ons. Works for any public Matterport URL
 * that a browser can load.
 *
 * If Matterport changes the embed format the regex may break — see
 * extractModelData() for the fallback chain.
 */

use Kirby\Cms\App as Kirby;
use Kirby\Http\Response;
use Kirby\Http\Remote;

Kirby::plugin('goheritage/matterport', [

    'pageMethods' => [
        // Extract `m=XXXXXXX` model ID from a matterport share URL.
        'matterportModelId' => function () {
            $raw = trim((string) $this->matterport_url());
            if ($raw === '') return null;
            // https://my.matterport.com/show/?m=XXXXX
            if (preg_match('/[?&]m=([A-Za-z0-9_-]+)/', $raw, $m)) return $m[1];
            // https://discover.matterport.com/space/XXXXX
            if (preg_match('~/space/([A-Za-z0-9_-]+)~', $raw, $m))  return $m[1];
            // https://my.matterport.com/show/XXXXX  (no query string)
            if (preg_match('~/show/([A-Za-z0-9_-]+)~', $raw, $m))   return $m[1];
            // Raw model ID
            if (preg_match('/^[A-Za-z0-9_-]{6,}$/', $raw))          return $raw;
            return null;
        },
    ],

    'fields' => [
        // Panel field: button that triggers the import API route and shows status.
        'matterport-import' => [
            'computed' => [
                'pageId'   => function () { return $this->model()->id(); },
                'modelId'  => function () { return $this->model()->matterportModelId(); },
                'skyboxCount' => function () {
                    $count = 0;
                    foreach ($this->model()->files() as $f) {
                        if (preg_match('/[-_]skybox[-_]?0\.(jpe?g|png|webp)$/i', $f->filename())) {
                            $count++;
                        }
                    }
                    return $count;
                },
            ],
        ],
    ],

    'api' => [
        'routes' => [
            [
                'pattern' => 'matterport/import',
                'method'  => 'POST',
                'auth'    => false,
                'action'  => function () {
                    try {
                        return matterportImportRoute(kirby());
                    } catch (\Throwable $e) {
                        return Response::json([
                            'error' => 'Erreur serveur: ' . $e->getMessage(),
                            'file'  => basename($e->getFile()),
                            'line'  => $e->getLine(),
                        ], 500);
                    }
                },
            ],
        ],
    ],
]);

function matterportImportRoute($kirby) {
                    $user  = $kirby->user();
                    if (!$user) {
                        return Response::json(['error' => 'Non authentifié'], 401);
                    }

                    $pageId = $kirby->request()->get('pageId');
                    $page   = $pageId ? $kirby->page($pageId) : null;
                    if (!$page) {
                        return Response::json(['error' => 'Page introuvable'], 404);
                    }

                    $modelId = $page->matterportModelId();
                    if (!$modelId) {
                        return Response::json([
                            'error' => 'URL Matterport invalide ou absente sur la page.',
                        ], 400);
                    }

                    // ── Fetch Showcase HTML — try multiple URLs ──────────────
                    // Prefer standard viewer; fall back to discover URL.
                    // Both pages embed MP_PREFETCHED_MODELDATA or similar blobs.
                    $rawUrl  = trim((string) $page->matterport_url());
                    $tryUrls = [
                        'https://my.matterport.com/show/?m=' . $modelId,
                    ];
                    // If the input was a discover URL, try it first — may carry
                    // different/richer embed data than the redirect target.
                    if (str_contains($rawUrl, 'discover.matterport.com')) {
                        array_unshift($tryUrls, $rawUrl);
                    } else {
                        $tryUrls[] = 'https://discover.matterport.com/space/' . $modelId;
                    }

                    $sweeps = null;
                    $lastErr = null;
                    foreach ($tryUrls as $url) {
                        try {
                            $resp = Remote::request($url, [
                                'method'  => 'GET',
                                'timeout' => 30,
                                'headers' => [
                                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                                    'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                                    'Accept-Language' => 'en-US,en;q=0.9',
                                    'Referer'         => 'https://www.google.com/',
                                    'Sec-Fetch-Mode'  => 'navigate',
                                    'Sec-Fetch-Site'  => 'none',
                                ],
                            ]);
                        } catch (\Throwable $e) {
                            $lastErr = $e->getMessage();
                            continue;
                        }
                        if ($resp->code() !== 200) {
                            $lastErr = 'HTTP ' . $resp->code() . ' on ' . $url;
                            continue;
                        }
                        $html = (string) $resp->content();
                        $sweeps = matterportExtractSweeps($html);
                        if ($sweeps !== null && count($sweeps) > 0) break;
                        // 200 but no sweeps — note which URL and keep trying
                        $lastErr = 'Données introuvables dans HTML de ' . parse_url($url, PHP_URL_HOST);
                    }

                    if ($sweeps === null || empty($sweeps)) {
                        return Response::json([
                            'error' => 'Impossible d\'extraire les données Matterport. '
                                     . 'Le modèle est peut-être privé, ou le format a changé. '
                                     . ($lastErr ? "Dernière erreur : $lastErr" : ''),
                        ], 502);
                    }

                    // ── Index existing skybox files on the page ─────────────
                    $skyboxByPrefix = []; // prefix (lowercased, no hyphens) => filename of face 0
                    foreach ($page->files() as $f) {
                        if (preg_match('/^(.+?)[-_]skybox[-_]?(\d)\.(jpe?g|png|webp)$/i', $f->filename(), $m)) {
                            $prefix = strtolower(str_replace('-', '', $m[1]));
                            $face   = (int) $m[2];
                            if ($face === 0 || !isset($skyboxByPrefix[$prefix])) {
                                $skyboxByPrefix[$prefix] = $f->filename();
                            }
                        }
                    }

                    // ── Assemble hotspots ───────────────────────────────────
                    $hotspots = [];
                    $matched  = 0;
                    $missing  = [];
                    foreach ($sweeps as $sw) {
                        $uuid = strtolower(str_replace('-', '', $sw['uuid']));
                        $panoFile = $skyboxByPrefix[$uuid] ?? ($uuid . '_skybox0.jpg');
                        if (isset($skyboxByPrefix[$uuid])) {
                            $matched++;
                        } else {
                            $missing[] = $uuid;
                        }
                        $hotspots[] = [
                            'id'         => $sw['uuid'],
                            'title'      => $sw['label'] ?: substr($uuid, 0, 8),
                            'position'   => [
                                'x' => (float) $sw['position']['x'],
                                'y' => (float) $sw['position']['y'],
                                'z' => (float) $sw['position']['z'],
                            ],
                            'panorama'   => $panoFile,
                            'pano_yaw'   => 0,
                            'pano_pitch' => 0,
                        ];
                    }

                    // ── Write pano-hotspots.json ────────────────────────────
                    $json = [
                        'version'   => '1.0',
                        'source'    => 'matterport-scrape:' . $modelId,
                        'generated' => gmdate('c'),
                        'exterior'  => ['hotspots' => $hotspots],
                        'interior'  => ['hotspots' => []],
                    ];
                    $jsonTmp = sys_get_temp_dir() . '/' . uniqid('mp_') . '_pano-hotspots.json';
                    file_put_contents(
                        $jsonTmp,
                        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );

                    // ── Write matterport-sweeps.py (Blender script) ─────────
                    // User opens Blender → Scripting workspace → Text → Open
                    // this file → Run Script (Alt+P). Creates named empties at
                    // each sweep (axis-corrected to Blender's Z-up), grouped
                    // under one parent empty. Alignment helper included.
                    $py = matterportBuildBlenderScript($modelId, $sweeps);
                    $pyTmp = sys_get_temp_dir() . '/' . uniqid('mp_') . '_matterport-sweeps.py';
                    file_put_contents($pyTmp, $py);

                    try {
                        $kirby->impersonate('kirby');
                        $page = $kirby->page($pageId);

                        // JSON
                        $jsonName = 'pano-hotspots.json';
                        $existing = $page->file($jsonName);
                        if ($existing) {
                            $newJsonFile = $existing->replace($jsonTmp);
                        } else {
                            $newJsonFile = $page->createFile([
                                'source'   => $jsonTmp,
                                'filename' => $jsonName,
                                'template' => 'default',
                            ]);
                        }

                        // Blender script
                        $pyName = 'matterport-sweeps.py';
                        $page   = $kirby->page($pageId);
                        $existingPy = $page->file($pyName);
                        if ($existingPy) {
                            $existingPy->replace($pyTmp);
                        } else {
                            $page->createFile([
                                'source'   => $pyTmp,
                                'filename' => $pyName,
                                'template' => 'default',
                            ]);
                        }

                        // Link JSON to the pano-hotspots field
                        $kirby->page($pageId)->update([
                            'pano_hotspots_json' => $newJsonFile->uuid(),
                        ]);
                    } catch (\Throwable $e) {
                        return Response::json(['error' => 'Échec de l\'écriture : ' . $e->getMessage()], 500);
                    } finally {
                        @unlink($jsonTmp);
                        @unlink($pyTmp);
                    }

                    $page = $kirby->page($pageId);
                    $pyFile = $page->file('matterport-sweeps.py');
                    return Response::json([
                        'status'   => 'ok',
                        'model_id' => $modelId,
                        'hotspots' => count($hotspots),
                        'matched'  => $matched,
                        'missing'  => $missing,
                        'filename' => 'pano-hotspots.json',
                        'blender'  => 'matterport-sweeps.py',
                        'blender_url' => $pyFile ? $pyFile->url() : null,
                    ]);
}


/**
 * Build a self-contained Blender Python script that places named empties at
 * every Matterport sweep position and provides a one-click 3-point alignment
 * operator for snapping the user's own model to the Matterport frame.
 *
 * Usage in Blender:
 *   1. Scripting workspace → Text → Open → select matterport-sweeps.py
 *   2. Alt+P (Run Script) — empties appear in a new "Matterport Sweeps"
 *      collection, origin parented.
 *   3. The script also registers a sidebar panel (N key in 3D view,
 *      "Matterport" tab) with buttons for:
 *        • Select All Sweeps
 *        • Frame Selected
 *        • 3-point Align Model (select 3 empties then Ctrl-click 3 mesh
 *          vertices in edit mode — runs Kabsch alignment)
 */
function matterportBuildBlenderScript(string $modelId, array $sweeps): string
{
    // Encode sweep list as a Python literal — keep both raw (Y-up) and
    // pre-swapped (Z-up) coords so the script can flip axes if needed.
    $pySweeps = "SWEEPS = [\n";
    foreach ($sweeps as $sw) {
        $uuid  = strtolower(str_replace('-', '', $sw['uuid']));
        $label = $sw['label'] ?: substr($uuid, 0, 8);
        // Escape for Python string literal
        $safeLabel = addslashes($label);
        $safeUuid  = addslashes($uuid);
        $pySweeps .= sprintf(
            "    {\"uuid\": \"%s\", \"label\": \"%s\", \"pos\": (%.6f, %.6f, %.6f)},\n",
            $safeUuid,
            $safeLabel,
            $sw['position']['x'],
            $sw['position']['y'],
            $sw['position']['z'],
        );
    }
    $pySweeps .= "]\n";

    $safeModelId = addslashes($modelId);

    $template = <<<'PY'
"""
Matterport sweeps → Blender empties
Model: {{MODEL_ID}}
Generated: {{GENERATED}}

Run in Blender (Scripting workspace, Alt+P). Creates one empty per sweep
at the Matterport capture position (axis-corrected: Matterport Y-up, meters
→ Blender Z-up, meters). Also registers a "Matterport" N-panel with:

  • Reload Sweeps       — re-create the collection
  • Select All Sweeps   — select every empty
  • Frame Selected      — zoom the viewport to selection
  • 3-Point Align       — select 3 sweep empties, then shift-select 3
                          matching vertices on your model in that order,
                          then click. Computes a rigid+uniform-scale
                          transform (Umeyama/Kabsch) and applies it to
                          the active object. Your model snaps to the
                          Matterport frame.

No external deps — pure bpy + mathutils.
"""
import bpy
from mathutils import Vector, Matrix
import numpy as np

COLLECTION_NAME = "Matterport Sweeps"
PARENT_NAME     = "MatterportRoot"
EMPTY_SIZE      = 0.25

{{SWEEPS}}


def mp_to_blender(pos):
    """Matterport Y-up, meters → Blender Z-up, meters.
    Matterport (x, y, z) = right, up, forward.
    Blender  (x, y, z) = right, forward, up.
    Mapping: (x_mp, y_mp, z_mp) → (x_mp, -z_mp, y_mp).
    """
    return Vector((pos[0], -pos[2], pos[1]))


def ensure_collection(name, parent=None):
    coll = bpy.data.collections.get(name)
    if coll is None:
        coll = bpy.data.collections.new(name)
        (parent or bpy.context.scene.collection).children.link(coll)
    return coll


def clear_collection(coll):
    for obj in list(coll.objects):
        bpy.data.objects.remove(obj, do_unlink=True)


def build_sweeps():
    coll = ensure_collection(COLLECTION_NAME)
    clear_collection(coll)

    root = bpy.data.objects.get(PARENT_NAME)
    if root is None:
        root = bpy.data.objects.new(PARENT_NAME, None)
        root.empty_display_type = 'ARROWS'
        root.empty_display_size = 0.5
        coll.objects.link(root)

    for sw in SWEEPS:
        pos = mp_to_blender(sw["pos"])
        label = sw["label"]
        # Prefix with uuid[:8] so duplicates don't collide
        name = f"mp_{sw['uuid'][:8]}_{label}" if label else f"mp_{sw['uuid'][:8]}"
        emp = bpy.data.objects.new(name, None)
        emp.empty_display_type = 'SPHERE'
        emp.empty_display_size = EMPTY_SIZE
        emp.location = pos
        emp.parent = root
        emp["mp_uuid"]  = sw["uuid"]
        emp["mp_label"] = label
        coll.objects.link(emp)

    print(f"[Matterport] Created {len(SWEEPS)} sweep empties under '{PARENT_NAME}'.")
    return coll


# ── Umeyama (Kabsch with scale) for 3-point alignment — numpy-backed ────────
def umeyama(src_pts, dst_pts):
    """Rigid + uniform scale transform mapping src → dst.
    Returns a 4x4 Matrix. Requires at least 3 point pairs.
    """
    src = np.array([[p.x, p.y, p.z] for p in src_pts], dtype=np.float64)
    dst = np.array([[p.x, p.y, p.z] for p in dst_pts], dtype=np.float64)
    n = src.shape[0]

    src_mu = src.mean(axis=0)
    dst_mu = dst.mean(axis=0)
    sc = src - src_mu
    dc = dst - dst_mu

    H = sc.T @ dc / n
    U, S, Vt = np.linalg.svd(H)
    d = np.sign(np.linalg.det(Vt.T @ U.T))
    D = np.diag([1.0, 1.0, d])
    R = Vt.T @ D @ U.T

    var_src = (sc ** 2).sum() / n
    scale = (S[0] + S[1] + S[2] * d) / var_src if var_src > 1e-12 else 1.0
    t = dst_mu - scale * (R @ src_mu)

    M = Matrix.Identity(4)
    for i in range(3):
        for j in range(3):
            M[i][j] = R[i, j] * scale
        M[i][3] = t[i]
    return M


# ── Operators ───────────────────────────────────────────────────────────────
class MP_OT_reload(bpy.types.Operator):
    bl_idname = "mp.reload_sweeps"
    bl_label  = "Reload Sweeps"
    def execute(self, context):
        build_sweeps()
        return {'FINISHED'}


class MP_OT_select_all(bpy.types.Operator):
    bl_idname = "mp.select_all_sweeps"
    bl_label  = "Select All Sweeps"
    def execute(self, context):
        coll = bpy.data.collections.get(COLLECTION_NAME)
        if not coll: return {'CANCELLED'}
        bpy.ops.object.select_all(action='DESELECT')
        for o in coll.objects:
            if o.name.startswith("mp_"):
                o.select_set(True)
        return {'FINISHED'}


class MP_OT_frame(bpy.types.Operator):
    bl_idname = "mp.frame_sweeps"
    bl_label  = "Frame Sweeps"
    def execute(self, context):
        bpy.ops.mp.select_all_sweeps()
        for a in context.screen.areas:
            if a.type == 'VIEW_3D':
                for r in a.regions:
                    if r.type == 'WINDOW':
                        with bpy.context.temp_override(area=a, region=r):
                            bpy.ops.view3d.view_selected()
                return {'FINISHED'}
        return {'CANCELLED'}


class MP_OT_align(bpy.types.Operator):
    """Align the active mesh to Matterport sweeps.

    How to use:
      1. Pick your model as the active object (Object mode).
      2. Note 3 vertex indices on your model that correspond to 3 sweeps.
      3. Fill in the 3 empty names + 3 vertex indices in the panel.
      4. Click Align. Applies scale + rotation + translation to the model.
    """
    bl_idname = "mp.align_model"
    bl_label  = "3-Point Align Model"

    def execute(self, context):
        props = context.scene.mp_align_props
        obj = context.view_layer.objects.active
        if not obj or obj.type != 'MESH':
            self.report({'ERROR'}, "Active object must be a mesh.")
            return {'CANCELLED'}

        try:
            sweep_names = [props.sweep_a, props.sweep_b, props.sweep_c]
            vi = [props.vert_a, props.vert_b, props.vert_c]
            src_pts = [obj.matrix_world @ obj.data.vertices[i].co for i in vi]
            dst_pts = [bpy.data.objects[n].matrix_world.translation.copy() for n in sweep_names]
        except (KeyError, IndexError) as e:
            self.report({'ERROR'}, f"Missing sweep or vertex: {e}")
            return {'CANCELLED'}

        M = umeyama(src_pts, dst_pts)
        obj.matrix_world = M @ obj.matrix_world
        self.report({'INFO'}, "Model aligned to Matterport frame.")
        return {'FINISHED'}


class MP_AlignProps(bpy.types.PropertyGroup):
    sweep_a: bpy.props.StringProperty(name="Sweep A")
    sweep_b: bpy.props.StringProperty(name="Sweep B")
    sweep_c: bpy.props.StringProperty(name="Sweep C")
    vert_a:  bpy.props.IntProperty(name="Vert A", default=0, min=0)
    vert_b:  bpy.props.IntProperty(name="Vert B", default=1, min=0)
    vert_c:  bpy.props.IntProperty(name="Vert C", default=2, min=0)


class MP_PT_panel(bpy.types.Panel):
    bl_label       = "Matterport"
    bl_idname      = "MP_PT_panel"
    bl_space_type  = 'VIEW_3D'
    bl_region_type = 'UI'
    bl_category    = "Matterport"

    def draw(self, context):
        lay = self.layout
        lay.operator("mp.reload_sweeps", icon='FILE_REFRESH')
        lay.operator("mp.select_all_sweeps", icon='RESTRICT_SELECT_OFF')
        lay.operator("mp.frame_sweeps", icon='VIEWZOOM')

        lay.separator()
        lay.label(text="3-Point Align:")
        p = context.scene.mp_align_props
        box = lay.box()
        box.prop_search(p, "sweep_a", bpy.data, "objects", text="A")
        box.prop(p, "vert_a")
        box.prop_search(p, "sweep_b", bpy.data, "objects", text="B")
        box.prop(p, "vert_b")
        box.prop_search(p, "sweep_c", bpy.data, "objects", text="C")
        box.prop(p, "vert_c")
        box.operator("mp.align_model", icon='SNAP_ON')


CLASSES = (MP_OT_reload, MP_OT_select_all, MP_OT_frame, MP_OT_align, MP_AlignProps, MP_PT_panel)


def register():
    for c in CLASSES:
        try: bpy.utils.register_class(c)
        except Exception: pass
    bpy.types.Scene.mp_align_props = bpy.props.PointerProperty(type=MP_AlignProps)


def unregister():
    for c in reversed(CLASSES):
        try: bpy.utils.unregister_class(c)
        except Exception: pass


# Auto-run on script execution
try: unregister()
except Exception: pass
register()
build_sweeps()
PY;

    return strtr($template, [
        '{{MODEL_ID}}'  => $safeModelId,
        '{{GENERATED}}' => gmdate('c'),
        '{{SWEEPS}}'    => $pySweeps,
    ]);
}

/**
 * Parse sweep list from a Matterport Showcase HTML page.
 *
 * Tries, in order:
 *   1. `window.MP_PREFETCHED_MODELDATA = {...};`  (main path — rebane2001/matterport-dl)
 *   2. `window.__INITIAL_STATE__ = {...};`        (newer builds)
 *
 * Returns: array of [ 'uuid' => ..., 'label' => ..., 'position' => [x,y,z],
 *                     'rotation' => [...] ] or null on parse failure.
 */
function matterportExtractSweeps(string $html): ?array
{
    $candidates = [];

    // 1. window.* assignment blobs (classic Showcase: MP_PREFETCHED_MODELDATA,
    //    older Next.js: __INITIAL_STATE__, Nuxt: __NUXT__)
    foreach (['MP_PREFETCHED_MODELDATA', '__INITIAL_STATE__', '__NUXT__'] as $varName) {
        $blob = matterportExtractAssignment($html, $varName);
        if ($blob !== null) $candidates[] = $blob;
    }

    // 2. Next.js SSR payload: <script id="__NEXT_DATA__" type="application/json">…</script>
    //    Used by discover.matterport.com
    if (preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/si', $html, $m)) {
        $candidates[] = $m[1];
    }

    foreach ($candidates as $jsonStr) {
        $data = json_decode(trim($jsonStr), true);
        if (!is_array($data)) continue;
        $sweeps = matterportWalkSweeps($data);
        if ($sweeps !== null && count($sweeps) > 0) {
            return $sweeps;
        }
    }

    return null;
}

/**
 * Extract a JSON object literal assigned to `window.<name>` by finding the
 * opening `{` after the assignment and walking balanced braces. Handles
 * string literals with escapes so braces inside strings don't break the count.
 */
function matterportExtractAssignment(string $html, string $varName): ?string
{
    $needle = 'window.' . $varName;
    $pos    = strpos($html, $needle);
    if ($pos === false) return null;

    // Find the `=` then the first `{`
    $eq = strpos($html, '=', $pos);
    if ($eq === false) return null;
    $start = strpos($html, '{', $eq);
    if ($start === false) return null;

    $len        = strlen($html);
    $depth      = 0;
    $inString   = false;
    $stringChar = '';
    $escape     = false;

    for ($i = $start; $i < $len; $i++) {
        $c = $html[$i];
        if ($inString) {
            if ($escape)              { $escape = false; continue; }
            if ($c === '\\')          { $escape = true;  continue; }
            if ($c === $stringChar)   { $inString = false; }
            continue;
        }
        if ($c === '"' || $c === "'") { $inString = true; $stringChar = $c; continue; }
        if ($c === '{') $depth++;
        elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($html, $start, $i - $start + 1);
            }
        }
    }
    return null;
}

/**
 * Recursively locate a "sweeps" or "locations" array inside arbitrary JSON.
 * Normalises entries to { uuid, label, position:{x,y,z}, rotation }.
 */
function matterportWalkSweeps($node, int $depth = 0): ?array
{
    if ($depth > 12 || !is_array($node)) return null;

    // Direct hit — array of sweep-like objects
    if (array_is_list($node) && count($node) > 0) {
        $first = $node[0];
        if (is_array($first) && (isset($first['uuid']) || isset($first['sid']) || isset($first['id']))
                             && (isset($first['anchor']) || isset($first['position']))) {
            $out = [];
            foreach ($node as $s) {
                if (!is_array($s)) continue;
                $uuid = $s['uuid'] ?? $s['sid'] ?? $s['id'] ?? null;
                $pos  = $s['anchor'] ?? $s['position'] ?? null;
                if (!$uuid || !is_array($pos)) continue;
                // Position can be {x,y,z} or [x,y,z]
                if (isset($pos['x'])) {
                    $xyz = ['x' => $pos['x'], 'y' => $pos['y'] ?? 0, 'z' => $pos['z'] ?? 0];
                } elseif (isset($pos[0])) {
                    $xyz = ['x' => $pos[0], 'y' => $pos[1] ?? 0, 'z' => $pos[2] ?? 0];
                } else {
                    continue;
                }
                $out[] = [
                    'uuid'     => (string) $uuid,
                    'label'    => (string) ($s['label'] ?? $s['name'] ?? ''),
                    'position' => $xyz,
                    'rotation' => $s['rotation'] ?? $s['orientation'] ?? null,
                ];
            }
            if (count($out) > 0) return $out;
        }
    }

    // Recurse
    foreach (['sweeps', 'locations', 'panos', 'panoLocations'] as $key) {
        if (isset($node[$key]) && is_array($node[$key])) {
            $result = matterportWalkSweeps($node[$key], $depth + 1);
            if ($result !== null) return $result;
        }
    }
    foreach ($node as $child) {
        if (is_array($child)) {
            $result = matterportWalkSweeps($child, $depth + 1);
            if ($result !== null) return $result;
        }
    }
    return null;
}
