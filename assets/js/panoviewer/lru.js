// Tiny LRU cache. Evicted values are disposed via a custom `.onEvict(value)`
// hook (preferred), or value.dispose?.() as a fallback. Used by PanoViewer
// for texture + cube-Group caching so neighbour pans don't re-upload to GPU.
export class LRUCache {
  constructor(max = 8) { this.max = max; this.map = new Map(); this.onEvict = null; }
  has(k) { return this.map.has(k); }
  get(k) { const v = this.map.get(k); if (v) { this.map.delete(k); this.map.set(k, v); } return v; }
  set(k, v) {
    if (this.map.has(k)) this.map.delete(k);
    this.map.set(k, v);
    if (this.map.size > this.max) {
      const oldest = this.map.keys().next().value;
      const old = this.map.get(oldest);
      this.map.delete(oldest);
      if (this.onEvict) this.onEvict(old);
      else old?.dispose?.();
    }
  }
  delete(k) {
    const v = this.map.get(k);
    if (v == null) return false;
    this.map.delete(k);
    if (this.onEvict) this.onEvict(v);
    else v?.dispose?.();
    return true;
  }
  clear() {
    this.map.forEach(v => (this.onEvict ? this.onEvict(v) : v?.dispose?.()));
    this.map.clear();
  }
}
