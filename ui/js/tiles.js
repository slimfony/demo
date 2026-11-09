// The grid of tiles: one per ZIP. A tile shows where its ZIP is: queued, being imported, booked, booked with problems,
// rolled back, or refused at the door. A tile with a shield was uploaded with a verified token.
const STATES = ['queued', 'running', 'done', 'problems', 'failed', 'refused'];
const LABELS = {
  queued: 'waiting for an importer',
  running: 'being imported',
  done: 'booked',
  problems: 'booked, some statements rejected',
  failed: 'rolled back',
  refused: 'refused by the API',
};

export function createTiles(root) {
  let tiles = [];
  const byId = new Map();

  function paint(tile, state, extra = '') {
    for (const name of STATES) tile.classList.toggle(name, name === state);
    const via = tile.classList.contains('jwt') ? 'with a token' : 'anonymous sandbox';
    tile.title = `ZIP ${tile.dataset.n}, ${via}: ${LABELS[state]}${extra}`;
  }

  return {
    /** Starts a new run with `count` empty tiles. */
    reset(count) {
      byId.clear();
      tiles = Array.from({ length: count }, (_, i) => {
        const tile = document.createElement('div');
        tile.className = 'tile';
        tile.dataset.n = String(i + 1);
        return tile;
      });
      root.replaceChildren(...tiles);
    },
    /** The API took ZIP number `index`: it is waiting now. */
    accepted(index, id, withToken) {
      const tile = tiles[index];
      tile.classList.toggle('jwt', withToken);
      byId.set(id, tile);
      paint(tile, 'queued');
    },
    refused(index, status, withToken) {
      tiles[index].classList.toggle('jwt', withToken);
      paint(tiles[index], 'refused', ` (HTTP ${status})`);
    },
    /** An importer reported on a batch. Returns false when this page does not know the batch (another visitor's). */
    update(event) {
      const tile = byId.get(event.id);
      if (!tile) return false;
      if ('running' === event.state) {
        paint(tile, 'running', `, importer ${event.worker}`);
      } else if ('done' === event.state) {
        paint(tile, event.failed > 0 ? 'problems' : 'done', `: ${event.ok} booked, ${event.failed} rejected, ${event.ms} ms`);
      } else {
        paint(tile, 'failed', `: ${event.failed} rejected, ${event.rolled_back} good ones rolled back with them`);
      }
      return true;
    },
    /** The state of a batch as the database has it (the page asks for these once a second, so a lost event costs nothing). */
    apply(row) {
      const tile = byId.get(row.id);
      if (!tile) return false;
      if ('running' === row.status) paint(tile, 'running', row.worker ? `, importer ${row.worker}` : '');
      else if ('done' === row.status) paint(tile, row.statements_failed > 0 ? 'problems' : 'done', `: ${row.statements_ok} booked, ${row.statements_failed} rejected`);
      else if ('failed' === row.status) paint(tile, 'failed', `: ${row.statements_failed} rejected, ${row.statements_rolled_back} good ones rolled back with them`);
      else paint(tile, 'queued');
      return true;
    },
    /** One ZIP of a run as the server has it: refused at the door, or a batch that waits, runs or is finished. */
    fromItem(item) {
      const tile = tiles[item.idx];
      if (!tile) return;
      tile.classList.toggle('jwt', 'jwt' === item.via);
      if (202 !== item.http_status) {
        paint(tile, 'refused', ` (HTTP ${item.http_status || 'no answer'})`);
        return;
      }
      byId.set(item.id, tile);
      this.apply(item);
    },
    knows(id) {
      return byId.has(id);
    },
  };
}
