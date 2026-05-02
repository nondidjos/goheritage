// Measurement tool mixin — mixed into PanoViewer.prototype.
// All methods receive `this` as the PanoViewer instance.
import * as THREE from 'three';

export const measureMixin = {

  toggleMeasure() {
    this._measure.active = !this._measure.active;
    this.measureBtn.classList.toggle('toggled', this._measure.active);
    this.container.classList.toggle('measuring', this._measure.active);
    if (!this._measure.active) this._clearMeasure();
    return this;
  },

  _clearMeasure() {
    if (this._measure.line) {
      this.scene.remove(this._measure.line);
      this._measure.line.geometry.dispose();
      this._measure.line.material.dispose();
      this._measure.line = null;
    }
    this._measure.labelEls.forEach(el => el.remove());
    this._measure.labelEls = [];
    this._measure.points = [];
  },

  _drawMeasureLine() {
    const [a, b] = this._measure.points;
    const geo = new THREE.BufferGeometry().setFromPoints([a, b]);
    const mat = new THREE.LineBasicMaterial({ color: 0xffcc33, depthTest: false });
    const line = new THREE.Line(geo, mat);
    line.renderOrder = 999;
    // Visible to both pano camera (layer 0) and dollhouse camera (layer 1).
    line.layers.enableAll();
    this.scene.add(line);
    this._measure.line = line;

    this._measure.labelEls.forEach(el => el.remove());
    this._measure.labelEls = [];
    const mid = a.clone().add(b).multiplyScalar(0.5);
    const dist = a.distanceTo(b);
    this._addMeasureLabel(mid, `${dist.toFixed(2)} m`);
  },

  _addMeasureLabel(worldPos, text) {
    const el = document.createElement('div');
    el.className = 'pano-measure-label';
    el.textContent = text;
    el.dataset.x = worldPos.x;
    el.dataset.y = worldPos.y;
    el.dataset.z = worldPos.z;
    // Hidden until the render loop positions it — prevents (0,0) flash.
    el.style.display = 'none';
    this.container.appendChild(el);
    this._measure.labelEls.push(el);
  },

  _updateMeasureLabels(cam) {
    if (!this._measure.labelEls.length) return;
    const { clientWidth: w, clientHeight: h } = this.container;
    const tmp    = new THREE.Vector3();
    const camDir = new THREE.Vector3();
    cam.getWorldDirection(camDir);

    this._measure.labelEls.forEach(el => {
      tmp.set(+el.dataset.x, +el.dataset.y, +el.dataset.z);
      const toPt   = tmp.clone().sub(cam.position);
      const inFront = camDir.dot(toPt) > 0;
      if (!inFront) { el.style.display = 'none'; return; }
      tmp.project(cam);
      el.style.display = '';
      el.style.left = `${(tmp.x * 0.5 + 0.5) * w}px`;
      el.style.top  = `${(-tmp.y * 0.5 + 0.5) * h}px`;
    });
  },

};
