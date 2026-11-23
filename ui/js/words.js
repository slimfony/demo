// One wording per meaning, used by the tables, the tiles, the legend and the forms.
export const MODES = { partial: 'Keep the good ones', strict: 'Roll back the whole ZIP' };
export const SENDERS = { jwt: 'With a token', public: 'Anonymous sandbox' };

// What can happen to a ZIP: the tile states, the legend and the batch table.
export const STATES = {
  queued: 'waiting',
  running: 'importing',
  done: 'booked',
  problems: 'booked, some statements rejected',
  failed: 'rolled back',
  refused: 'refused',
};

/** The tile state of a batch row as the database has it. */
export function stateOf(row) {
  if ('done' === row.status && row.statements_failed > 0) return 'problems';
  return { running: 'running', done: 'done', failed: 'failed' }[row.status] ?? 'queued';
}

const OUTCOMES = {
  202: 'Accepted',
  400: 'Refused: the request was not valid',
  401: 'Refused: the token was not accepted',
  403: 'Refused: the token has no permission to import',
  413: 'Refused: the file is too large',
  422: 'Refused: the file is not an acceptable ZIP',
  429: 'Refused: too many requests, try again in a moment',
};

/** What the API answered, in words a visitor understands (0: no answer at all). */
export function describeStatus(status) {
  if (OUTCOMES[status]) return OUTCOMES[status];
  if (!status) return 'The server did not answer';
  return status >= 500 ? `The server had a problem (HTTP ${status})` : `Refused (HTTP ${status})`;
}
