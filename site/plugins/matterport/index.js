panel.plugin('goheritage/matterport', {
  fields: {
    'matterport-import': {
      props: {
        pageId:      { type: String, default: '' },
        modelId:     { type: String, default: null },
        skyboxCount: { type: Number, default: 0 },
      },
      computed: {
        snippet() {
          return `(function(){var s=window.MP_PREFETCHED_MODELDATA||window.__INITIAL_STATE__;if(!s){var n=document.getElementById('__NEXT_DATA__');if(n)try{s=JSON.parse(n.textContent);}catch(e){}}if(!s){alert('Données introuvables.');return;}var iK=['uuid','sid','id','panoId'],pK=['position','anchor','pose'];function getPos(o){for(var k of pK){var v=o[k];if(!v||typeof v!=='object')continue;if(typeof v.x==='number')return v;if(v.position&&typeof v.position.x==='number')return v.position;}return null;}function isSw(o){return o&&typeof o==='object'&&iK.some(k=>typeof o[k]==='string')&&getPos(o)!=null;}function findSweeps(n,d){if(d>15||!n||typeof n!=='object')return null;if(Array.isArray(n)&&n[0]&&isSw(n[0]))return n;if(Array.isArray(n)){for(var i=0;i<n.length;i++){var r=findSweeps(n[i],d+1);if(r)return r;}return null;}for(var k of['sweeps','locations','panos','panoLocations','nodes','data','model']){if(n[k]){var r=findSweeps(n[k],d+1);if(r)return r;}}for(var v of Object.values(n)){if(v&&typeof v==='object'){var r=findSweeps(v,d+1);if(r)return r;}}return null;}function buildPanoMap(n,d,out){if(d>15||!n||typeof n!=='object')return;if(Array.isArray(n)){if(n[0]&&typeof n[0]==='object'){var p=n[0];var pu=String(p.uuid||p.id||'').replace(/-/g,'').toLowerCase();var swk=['sweep','sweepUuid','sweepId','sweep_uuid','sweep_id','location','locationId'].find(k=>p[k]);if(/^[a-f0-9]{32}$/i.test(pu)&&swk){n.forEach(q=>{var u=String(q.uuid||q.id||'').replace(/-/g,'').toLowerCase();var sw=String(q[swk]||'').toLowerCase();if(u&&sw)out[sw]=u;});return;}}n.forEach(c=>buildPanoMap(c,d+1,out));return;}for(var v of Object.values(n)){if(v&&typeof v==='object')buildPanoMap(v,d+1,out);}}var rawSweeps=findSweeps(s,0);if(!rawSweeps||!rawSweeps.length){alert('Sweeps non trouvés.');return;}window.__GH_DEBUG={firstSweep:rawSweeps[0],sweepKeys:Object.keys(rawSweeps[0]),sweepCount:rawSweeps.length,firstPano:rawSweeps[0].pano};console.log('GoHéritage DEBUG — first sweep:',JSON.stringify(rawSweeps[0],null,2));console.log('GoHéritage DEBUG — sweep keys:',Object.keys(rawSweeps[0]));console.log('GoHéritage DEBUG — first sweep.pano:',JSON.stringify(rawSweeps[0].pano,null,2));console.log('GoHéritage DEBUG — first sweep.pano keys:',rawSweeps[0].pano&&typeof rawSweeps[0].pano==='object'?Object.keys(rawSweeps[0].pano):'(not object)');var hexScan=function(o,d,found){if(d>5||!o||typeof o!=='object'||found.length>5)return;for(var k in o){var v=o[k];if(typeof v==='string'&&/^[a-f0-9-]{32,36}$/i.test(v)&&v.replace(/-/g,'').length===32){found.push(k+'='+v);}else if(v&&typeof v==='object'){hexScan(v,d+1,found);}}};var hexFound=[];hexScan(rawSweeps[0],0,hexFound);console.log('GoHéritage DEBUG — hex UUIDs in first sweep:',hexFound);var panoMap={};buildPanoMap(s,0,panoMap);console.log('GoHéritage DEBUG — pano map size:',Object.keys(panoMap).length);console.log('GoHéritage DEBUG — pano map sample:',Object.entries(panoMap).slice(0,3));var qYaw=function(r){if(!r||typeof r!=='object')return 0;var x=+r.x||0,y=+r.y||0,z=+r.z||0,w=typeof r.w==='number'?r.w:1;return Math.atan2(2*(w*z+x*y),1-2*(y*y+z*z))*180/Math.PI;};var qConv=function(r){if(!r||typeof r!=='object')return null;var qx=+r.x||0,qy=+r.y||0,qz=+r.z||0,qw=typeof r.w==='number'?r.w:1;return{x:+qx.toFixed(6),y:+qz.toFixed(6),z:+(-qy).toFixed(6),w:+qw.toFixed(6)};};var hs=rawSweeps.map(sw=>{var p=getPos(sw)||{x:0,y:0,z:0};var sid=iK.map(k=>sw[k]).find(v=>v)||'';var sidL=sid.toLowerCase();var puF=function(o){if(!o)return '';if(typeof o==='string')return o.replace(/-/g,'').toLowerCase();return String(o.sweepUuid||o.uuid||o.panoId||o.id||o.sid||'').replace(/-/g,'').toLowerCase();};var pu=panoMap[sidL]||puF(sw.pano)||(Array.isArray(sw.panos)?puF(sw.panos[0]):'')||sid;var py=sw.pano&&sw.pano.rotation?qYaw(sw.pano.rotation):0;var pq=sw.pano&&sw.pano.rotation?qConv(sw.pano.rotation):null;var bx=+(p.x||0),by=+(p.y||0),bz=+(p.z||0);var h={id:pu,sweep_sid:sid,title:sw.label||sw.name||pu.slice(0,8),position:{x:+bx.toFixed(4),y:+bz.toFixed(4),z:+(-by).toFixed(4)},panorama:pu+'_skybox0.jpg',pano_yaw:+py.toFixed(3),pano_pitch:0};if(pq)h.pano_quat=pq;return h;});var nz=hs.filter(h=>h.position.x||h.position.y||h.position.z).length;var nh=hs.filter(h=>/^[a-f0-9]{32}$/i.test(h.id)).length;var j=JSON.stringify({version:'1.0',source:'browser-extract',generated:new Date().toISOString(),exterior:{hotspots:hs},interior:{hotspots:[]}},null,2);var a=document.createElement('a');a.href='data:application/json,'+encodeURIComponent(j);a.download='pano-hotspots.json';a.click();console.log('GoHéritage: '+hs.length+' sweeps ('+nz+' positions, '+nh+' avec pano UUID hex). Pano map: '+Object.keys(panoMap).length+'.');})();`;
        },
      },
      methods: {
        copy() {
          const el = this.$el.querySelector('.k-matterport-code-input');
          if (!el) return;
          el.select();
          try {
            document.execCommand('copy');
            this.$panel.notification.success('Code copié !');
          } catch {
            this.$panel.notification.error('Copie échouée — sélectionnez manuellement.');
          }
        },
        selectAll() {
          const el = this.$el.querySelector('.k-matterport-code-input');
          if (el) el.select();
        },
      },
      template: `
        <k-field v-bind="$props" class="k-matterport-field">
          <div class="k-matterport-wrap">

            <ol class="k-matterport-steps">
              <li>Ouvrez le tour Matterport dans votre navigateur</li>
              <li>Appuyez sur <kbd>F12</kbd> → onglet <strong>Console</strong></li>
              <li>Copiez et collez le code ci-dessous, appuyez sur <kbd>Entrée</kbd></li>
              <li><strong>pano-hotspots.json</strong> se télécharge automatiquement</li>
              <li>Uploadez-le via <em>JSON Hotspots (manuel)</em> ci-dessous</li>
            </ol>

            <div class="k-matterport-code-row">
              <div class="k-matterport-code-wrap">
                <input
                  type="text"
                  readonly
                  class="k-matterport-code-input"
                  :value="snippet"
                  @focus="selectAll"
                />
                <button class="k-matterport-select-btn" @click="selectAll" title="Sélectionner tout">
                  ⌃A
                </button>
              </div>
              <k-button
                icon="copy"
                size="sm"
                variant="filled"
                theme="positive"
                @click="copy"
              >Copier</k-button>
            </div>

          </div>
        </k-field>
      `,
    },
  },
});
