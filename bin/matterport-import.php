<?php
/**
 * Matterport GraphQL → GoHéritage JSON importer.
 *
 * Usage:
 *   php bin/matterport-import.php <modelId> <apiToken> [outputPath]
 *
 * Pulls sweep locations (position + pano ids) from Matterport's GraphQL API
 * and writes a goheritage-compatible JSON file (exterior bucket, with hotspots
 * carrying position + panorama filename). Drop the result into the project's
 * media folder as pano-hotspots.json (or upload via the panel).
 *
 * The "panorama" field is set to `{panoId}_skybox0.jpg` — matching the
 * filenames produced by the Matterport Bundle export so our SKYBOX_REGEX
 * grouping picks them up. Rename if you downloaded panos under a different
 * scheme.
 *
 * Token: create a "Public API" token pair at https://my.matterport.com
 *        (Settings → Developer Tools). Pass as: "<key>:<secret>" base64 form,
 *        or export MATTERPORT_TOKEN.
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php matterport-import.php <modelId> <apiToken> [outputPath]\n");
    exit(1);
}
$modelId = $argv[1];
$token   = $argv[2];
$out     = $argv[3] ?? "pano-hotspots.{$modelId}.json";

// Matterport Model Graph API endpoint.
$endpoint = 'https://api.matterport.com/api/models/graph';

$query = <<<'GQL'
query GetLocations($modelId: ID!) {
  model(id: $modelId) {
    id
    name
    locations {
      id
      label
      position { x y z }
      panos {
        id
        skybox { children }
      }
    }
  }
}
GQL;

$payload = json_encode(['query' => $query, 'variables' => ['modelId' => $modelId]]);

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($token),
    ],
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || $res === false) {
    fwrite(STDERR, "GraphQL request failed (HTTP {$code}): {$res}\n");
    exit(2);
}

$data = json_decode($res, true);
if (!empty($data['errors'])) {
    fwrite(STDERR, "GraphQL errors:\n" . json_encode($data['errors'], JSON_PRETTY_PRINT) . "\n");
    exit(3);
}

$locations = $data['data']['model']['locations'] ?? [];
if (!$locations) {
    fwrite(STDERR, "No locations returned for model {$modelId}.\n");
    exit(4);
}

$hotspots = [];
$missing  = [];
foreach ($locations as $loc) {
    // Extract UUID from CDN URL (most reliable) then fall back to pano.id
    $uuid  = null;
    $pano0 = $loc['panos'][0] ?? null;
    $cdnChildren = $pano0['skybox']['children'] ?? [];
    if (!empty($cdnChildren) && is_string($cdnChildren[0])) {
        if (preg_match('%/~/([0-9a-f]{32})_skybox\d%i', $cdnChildren[0], $m)) {
            $uuid = strtolower($m[1]);
        }
    }
    if ($uuid === null && isset($pano0['id'])) {
        $candidate = strtolower(str_replace('-', '', $pano0['id']));
        if (preg_match('/^[0-9a-f]{32}$/', $candidate)) $uuid = $candidate;
    }
    if ($uuid === null) {
        $uuid = strtolower(str_replace('-', '', $loc['id']));
        $missing[] = $loc['id'];
    }
    $hotspots[] = [
        'id'        => $loc['id'],
        'title'     => $loc['label'] ?: $loc['id'],
        'position'  => [
            'x' => (float) ($loc['position']['x'] ?? 0),
            'y' => (float) ($loc['position']['y'] ?? 0),
            'z' => (float) ($loc['position']['z'] ?? 0),
        ],
        'panorama'  => $uuid . '_skybox0.jpg',
        'pano_yaw'  => 0,
        'pano_pitch'=> 0,
    ];
}
if ($missing) {
    fwrite(STDERR, "Warning: could not extract CDN UUID for " . count($missing) . " location(s):\n");
    foreach ($missing as $id) fwrite(STDERR, "  $id\n");
}

$goheritage = [
    'version'   => '1.0',
    'source'    => "matterport:{$modelId}",
    'generated' => gmdate('c'),
    'exterior'  => [
        'hotspots' => $hotspots,
    ],
    'interior'  => [
        'hotspots' => [],
    ],
];

file_put_contents($out, json_encode($goheritage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf("Wrote %d hotspots → %s\n", count($hotspots), $out);
