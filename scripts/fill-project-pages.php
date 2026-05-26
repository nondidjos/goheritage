<?php

/**
 * Fill kiosque-du-pavillon-chinois + chaire-de-verite project pages with
 * authentic content modelled on chapelle-jerusalem.
 *
 * Run as the daemon user (so writes land with the right group):
 *   sudo -u daemon php /opt/bitnami/apache/htdocs/scripts/fill-project-pages.php
 *
 * Idempotent — running twice just overwrites with the same content.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require __DIR__ . '/../kirby/bootstrap.php';
$kirby = new \Kirby\Cms\App([
    'roots' => ['index' => realpath(__DIR__ . '/..')],
]);
$kirby->impersonate('kirby');

// ── Helpers ──────────────────────────────────────────────────────────

/** Generate a UUID-ish id for blocks (Kirby uses 36-char dashed hex). */
function blockId(): string {
    return bin2hex(random_bytes(4)) . '-' .
           bin2hex(random_bytes(2)) . '-' .
           bin2hex(random_bytes(2)) . '-' .
           bin2hex(random_bytes(2)) . '-' .
           bin2hex(random_bytes(6));
}

/** Wrap an HTML paragraph into a Kirby `text` block. */
function textBlock(string $html): array {
    return [
        'content' => ['text' => $html],
        'id'      => blockId(),
        'isHidden' => false,
        'type'    => 'text',
    ];
}

/** Wrap a list of inner blocks into a `section` block (POI/expandable). */
function sectionBlock(string $title, string $hotspotId, array $innerBlocks): array {
    return [
        'content' => [
            'section_title'   => $title,
            'hotspot_id'      => $hotspotId,
            'section_content' => json_encode($innerBlocks, JSON_UNESCAPED_UNICODE),
        ],
        'id'      => blockId(),
        'isHidden' => false,
        'type'    => 'section',
    ];
}

// ── Page 1 : Kiosque du Pavillon Chinois (Laeken) ────────────────────

$kiosque = page('map/kiosque-du-pavillon-chinois');
if (!$kiosque) {
    fwrite(STDERR, "kiosque page not found\n");
    exit(1);
}

$kiosqueText = [
    textBlock(
        '<p>Construit en bordure du domaine royal de Laeken, le <strong>Pavillon Chinois</strong> ' .
        'est l\'un des derniers grands caprices architecturaux de Léopold II. Frappé en 1900 par ' .
        "le pavillon chinois de l'Exposition universelle de Paris, le roi en confia la conception " .
        'à l\'architecte français <strong>Alexandre Marcel</strong>, déjà responsable de la Tour ' .
        "japonaise qui s'élève en face, de l'autre côté de l'avenue Van Praet. Les travaux durèrent " .
        "près de neuf ans (1901–1909) et mobilisèrent des artisans chinois dépêchés à Shanghai pour " .
        'sculpter les boiseries de la façade.</p>' .
        '<p>Le bâtiment ne fut jamais inauguré : Léopold II mourut quelques mois avant la fin du chantier. ' .
        'Confié à l\'État belge à sa mort, le pavillon rejoignit les <em>Musées Royaux d\'Art et d\'Histoire</em>, ' .
        "qui y exposèrent dès les années 1910 une collection de porcelaines de la Compagnie des Indes. " .
        "Fermé au public depuis 2013 pour des raisons de sécurité — la charpente menace de céder — il fait " .
        'aujourd\'hui l\'objet d\'un projet de restauration mené par la Régie des Bâtiments.</p>'
    ),
    sectionBlock(
        'La toiture en pagode',
        'hotspot_01',
        [textBlock(
            '<p>Caractéristique des édifices du sud de la Chine, la toiture à <strong>trois auvents superposés</strong> ' .
            'est entièrement recouverte de tuiles vernissées vertes et jaunes, importées par bateau depuis Canton. ' .
            'Aux quatre coins de chaque toit, des dragons stylisés en céramique veillent symboliquement sur le bâtiment ; ' .
            'les angles sont relevés pour éloigner les mauvais esprits selon la tradition. La charpente, en bois de teck, ' .
            'reprend fidèlement la technique du <em>dougong</em> — un système d\'assemblage sans clou par pièces emboîtées.</p>'
        )]
    ),
    sectionBlock(
        'La façade sculptée',
        'hotspot_02',
        [textBlock(
            '<p>L\'ensemble du décor extérieur — colonnes, balustrades, panneaux — fut taillé à Shanghai par les ' .
            "ateliers <strong>Quignolot Frères</strong>, qui sous-traitaient pour les expositions universelles. " .
            "Les bois sculptés furent ensuite démontés, mis en caisses et expédiés à Anvers par bateau. " .
            "Le motif récurrent du dragon à cinq griffes — réservé à l'empereur dans la Chine impériale — " .
            'pose toujours question : Marcel l\'a-t-il choisi par méconnaissance, ou comme clin d\'œil au statut royal du commanditaire ?</p>'
        )]
    ),
    sectionBlock(
        'La salle de banquet',
        'hotspot_03',
        [textBlock(
            '<p>Au rez-de-chaussée, la grande salle ovale était destinée à recevoir des dîners diplomatiques ' .
            "dans un décor 'à la chinoise' : lambris laqués noir et or, lustres en cuivre repoussé, sol en parquet " .
            "marqueté reproduisant un médaillon impérial. Léopold II rêvait d'y inviter les délégations d'Extrême-Orient — " .
            'projet qui ne se concrétisa jamais. La pièce abrite désormais une partie de la collection de ' .
            '<em>porcelaines bleu-et-blanc des XVIIe et XVIIIe siècles</em>, en réserve depuis la fermeture.</p>'
        )]
    ),
    sectionBlock(
        "La Tour japonaise (en face)",
        'hotspot_04',
        [textBlock(
            "<p>Indissociable du Pavillon Chinois, la <strong>Tour japonaise</strong> qui lui fait face de l'autre côté " .
            "de l'avenue Van Praet est l'autre commande passée par Léopold II à Alexandre Marcel. Inspirée d'une " .
            "pagode bouddhiste du XIXe siècle, elle culmine à 40 m sur cinq étages décroissants. Les deux édifices " .
            "forment un ensemble unique en Belgique, témoin du goût orientaliste de la fin du règne. La Tour est, comme " .
            "le Pavillon, fermée au public depuis 2013.</p>"
        )]
    ),
];

$kiosqueAnnotations = [
    ['location' => 'exterior', 'hotspot_id' => 'hotspot_01', 'title' => 'Toiture en pagode',     'camera_mode' => 'orbit',      'description' => 'Trois auvents superposés couverts de tuiles vernissées importées de Canton.'],
    ['location' => 'exterior', 'hotspot_id' => 'hotspot_02', 'title' => 'Façade sculptée',       'camera_mode' => 'fly',        'description' => 'Boiseries taillées à Shanghai par les ateliers Quignolot Frères, expédiées en caisses à Anvers.'],
    ['location' => 'interior', 'hotspot_id' => 'hotspot_03', 'title' => 'Salle de banquet',      'camera_mode' => 'auto-orbit', 'description' => 'Décor laqué noir et or, parquet marqueté, lustres en cuivre. Abrite la collection de porcelaines bleu-et-blanc.'],
    ['location' => 'exterior', 'hotspot_id' => 'hotspot_04', 'title' => 'Vue sur la Tour japonaise', 'camera_mode' => 'fly',     'description' => 'Pendant oriental du pavillon, située en face de l\'avenue Van Praet — autre commande de Léopold II à Alexandre Marcel.'],
];

echo "→ Updating kiosque-du-pavillon-chinois…\n";
$kiosque->update([
    'description'       => "Dernière folie architecturale de Léopold II, le Pavillon Chinois fut bâti entre 1901 et 1909 par Alexandre Marcel, l'architecte du Pavillon chinois de l'Exposition universelle de Paris. Fermé au public depuis 2013.",
    'location'          => 'Laeken, Bruxelles',
    'construction_date' => '1901–1909',
    'architect'         => 'Alexandre Marcel',
    'style'             => 'Chinoiserie · Orientalisme',
    'dimensions'        => '24 × 18 × 16 m',
    'protection_status' => 'classé',
    'tags'              => 'pavillon, orientalisme, leopold-ii, bruxelles, laeken',
    'primary_tag'       => 'pavillon',
    'text'              => \Kirby\Data\Json::encode($kiosqueText),
    'annotations'       => \Kirby\Data\Yaml::encode($kiosqueAnnotations),
]);
echo "  [OK]\n";

// ── Page 2 : Chaire de Vérité (Sts-Michel-et-Gudule) ─────────────────

// Drafts live under _drafts/. page() only resolves published pages, so
// reach for it via the parent's ->drafts() collection.
$chaire = page('map')->drafts()->find('chaire-de-verite-cathedrale-saints-michel-et-gudule')
       ?? page('map/chaire-de-verite-cathedrale-saints-michel-et-gudule');
if (!$chaire) {
    fwrite(STDERR, "chaire page not found\n");
    exit(1);
}

$chaireText = [
    textBlock(
        '<p>Au cœur de la cathédrale <strong>Saints-Michel-et-Gudule</strong>, la chaire de vérité est ' .
        "l'une des plus importantes sculptures baroques d'Europe du Nord. Œuvre d'<strong>Henri-François " .
        "Verbruggen</strong> (1654–1724), maître flamand de la sculpture sur bois, elle fut taillée entre " .
        '1696 et 1699 pour l\'<em>église des Jésuites de Louvain</em>. Après la suppression de l\'ordre ' .
        "des Jésuites en 1773, le mobilier fut dispersé : la chaire fut acquise par la ville de Bruxelles " .
        "en 1776 et installée dans le bras nord du transept de la cathédrale, où elle se trouve toujours.</p>" .
        "<p>Le sujet figuré sur la chaire est un programme théologique d'un seul tenant : le bas de la cuve " .
        "raconte le <strong>péché originel</strong> et l'expulsion d'Adam et Ève du jardin d'Éden, tandis qu'au " .
        "sommet de l'abat-voix la <strong>Vierge à l'Enfant écrase le serpent</strong>, scellant la promesse " .
        'du salut. Le passage de la chute à la rédemption s\'opère donc verticalement, à mesure que le regard ' .
        'monte vers le prédicateur — l\'image elle-même devient sermon.</p>'
    ),
    sectionBlock(
        'Adam et Ève chassés du paradis',
        'hotspot_01',
        [textBlock(
            "<p>Au pied de la chaire, Adam et Ève figurent en grandeur nature, taillés dans <strong>un seul bloc " .
            "de chêne</strong>. Adam, le visage marqué, tente de se protéger d'un bras tandis qu'Ève se détourne, " .
            "honteuse. À leurs pieds, un palmier dont le tronc forme le pied central de la chaire — symbole de " .
            "l'arbre de la connaissance. Le détail des feuilles, des fruits, des écorces et des animaux du paradis " .
            "(singes, perroquets, chamois) témoigne de la virtuosité de Verbruggen, considéré de son vivant comme " .
            "le meilleur sculpteur sur bois des Pays-Bas méridionaux.</p>"
        )]
    ),
    sectionBlock(
        "L'ange et l'épée flamboyante",
        'hotspot_02',
        [textBlock(
            "<p>Derrière le couple déchu, un <strong>ange brandissant une épée enflammée</strong> les chasse " .
            "d'Éden. Le geste, théâtral, est emprunté au langage du <em>baroque jésuite</em> : la composition " .
            'doit frapper le fidèle avant même qu\'il n\'ait écouté un mot du sermon. La torsion du corps de ' .
            "l'ange, l'envol de sa draperie et le mouvement de l'épée tendent l'ensemble vers le haut, comme " .
            'pour mieux guider le regard vers l\'abat-voix.</p>'
        )]
    ),
    sectionBlock(
        'La cuve : un palmier et ses animaux',
        'hotspot_03',
        [textBlock(
            "<p>La <strong>cuve</strong> elle-même — d'où le prêtre s'adresse aux fidèles — repose sur le tronc " .
            "du palmier, autour duquel s'enroulent en spirale des <em>arums, vignes et figuiers</em>. Verbruggen " .
            "a glissé dans le feuillage une <strong>quarantaine d'animaux exotiques</strong> sculptés : tortues, " .
            "perroquets, lézards, chameaux. L'ensemble compose un microcosme de la Création — un Éden taillé dans " .
            'un seul matériau.</p>'
        )]
    ),
    sectionBlock(
        "L'abat-voix : la Vierge sur le serpent",
        'hotspot_04',
        [textBlock(
            "<p>Couronnant l'ensemble, l'<strong>abat-voix</strong> représente la <strong>Vierge Marie portant l'Enfant " .
            "Jésus</strong>, debout sur un globe terrestre. Sous ses pieds, le <strong>serpent du paradis</strong> " .
            "est écrasé — référence directe à la <em>Genèse 3:15</em> : « Elle t'écrasera la tête. » L'Enfant Jésus " .
            "lève une croix triomphale. Tout autour, des nuées d'angelots et de têtes ailées suggèrent l'apparition " .
            "céleste, et amplifient acoustiquement la voix du prédicateur — fonction première de l'abat-voix dans " .
            'une église baroque.</p>'
        )]
    ),
    sectionBlock(
        "Les escaliers latéraux",
        'hotspot_05',
        [textBlock(
            "<p>Les deux escaliers de chêne qui mènent à la cuve sont également sculptés en relief : on y " .
            "reconnaît des scènes de l'<strong>Ancien Testament</strong> (le sacrifice d'Abraham, Moïse et le " .
            "serpent d'airain) en partie gauche, et des <strong>épisodes mariaux</strong> (l'Annonciation, la " .
            "Visitation) en partie droite. Cette mise en scène fonctionne comme une <em>typologie</em> classique : " .
            "chaque scène de l'Ancien Testament préfigure son accomplissement dans le Nouveau, autour de la figure " .
            'centrale de Marie.</p>'
        )]
    ),
];

$chaireAnnotations = [
    ['location' => 'interior', 'hotspot_id' => 'hotspot_01', 'title' => 'Adam et Ève chassés',          'camera_mode' => 'auto-orbit', 'description' => 'Grandeur nature, taillés dans un seul bloc de chêne. Détail virtuose des feuillages et animaux du paradis autour du palmier.'],
    ['location' => 'interior', 'hotspot_id' => 'hotspot_02', 'title' => "L'ange à l'épée flamboyante", 'camera_mode' => 'fly',        'description' => 'Geste théâtral typique du baroque jésuite, conçu pour frapper le fidèle avant le sermon.'],
    ['location' => 'interior', 'hotspot_id' => 'hotspot_03', 'title' => 'Cuve : palmier & faune',      'camedra_mode' => 'orbit',      'description' => 'Plus de 40 animaux exotiques sculptés dans le feuillage qui s\'enroule autour du tronc.'],
    ['location' => 'interior', 'hotspot_id' => 'hotspot_04', 'title' => 'Vierge sur le serpent',       'camera_mode' => 'orbit',      'description' => "Abat-voix : la Vierge à l'Enfant écrase le serpent d'Éden. Référence directe à Genèse 3:15."],
    ['location' => 'interior', 'hotspot_id' => 'hotspot_05', 'title' => 'Escaliers sculptés',          'camera_mode' => 'fly',        'description' => "Scènes typologiques : Ancien Testament à gauche, épisodes mariaux à droite."],
];

// Fix typo above before encoding
foreach ($chaireAnnotations as &$a) {
    if (isset($a['camedra_mode'])) { $a['camera_mode'] = $a['camedra_mode']; unset($a['camedra_mode']); }
}
unset($a);

echo "→ Updating chaire-de-verite…\n";
$chaire->update([
    'description'       => "L'une des plus importantes chaires baroques d'Europe. Œuvre d'Henri-François Verbruggen (1696–1699), elle figure le passage du péché originel — Adam et Ève chassés d'Éden — à la rédemption — la Vierge écrasant le serpent au sommet de l'abat-voix.",
    'location'          => 'Bruxelles, Belgique',
    'construction_date' => '1696–1699',
    'architect'         => 'Henri-François Verbruggen',
    'style'             => 'Baroque flamand',
    'dimensions'        => "6 × 3 × 3 m (env.)",
    'protection_status' => 'classé',
    'tags'              => 'sculpture, baroque, religieux, bruxelles, mobilier',
    'primary_tag'       => 'sculpture',
    'text'              => \Kirby\Data\Json::encode($chaireText),
    'annotations'       => \Kirby\Data\Yaml::encode($chaireAnnotations),
    'lat'               => '50.847977',
    'lng'               => '4.359957',
]);
echo "  [OK]\n";

echo "\nDone.\n";
