const { NodeIO } = require('@gltf-transform/core');

const args = process.argv.slice(2);
if (args.length < 1) {
  process.exit(1);
}

const file = args[0];

(async () => {
    try {
        const io = new NodeIO();
        const document = await io.read(file);
        const root = document.getRoot();

        const hotspots = [];

        root.listNodes().forEach(node => {
            const name = node.getName() || '';
            const extras = node.getExtras() || {};

            const isHotspot = 
                extras.hotspot === true || 
                extras.hotspot === 1 || 
                name.toLowerCase().startsWith('hotspot_');

            if (!isHotspot) return;

            const id = extras.hotspot_id || name;
            if (!id) return;

            hotspots.push({
                id: id,
                title: extras.title || name || id,
                camera_mode: extras.camera_mode || 'fly'
            });
        });

        console.log(JSON.stringify(hotspots));

    } catch (e) {
        console.error(e.message);
        process.exit(1);
    }
})();
