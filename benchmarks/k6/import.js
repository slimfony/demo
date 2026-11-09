// The import lane: uploads a number of ZIPs of generated CAMT.053 statements through the API, as fast as `VUS` clients can.
// k6 measures the upload side (how fast the API takes ZIPs in). run-import.sh then waits for the importers to finish
// and measures the whole path from the first upload to the last booked statement.
import http from 'k6/http';
import exec from 'k6/execution';
import { check } from 'k6';

const TARGET = __ENV.TARGET || 'http://web:8080';
const ZIPS = Number(__ENV.ZIPS || 1000);
const VUS = Number(__ENV.VUS || 24);
const LOCK_ORDER = __ENV.LOCK_ORDER || 'sorted';
const SEED_BASE = Number(__ENV.SEED_BASE || 1); // every statement id contains the seed: a seed must not be used twice

export const options = {
  scenarios: {
    upload: { executor: 'shared-iterations', vus: VUS, iterations: ZIPS, maxDuration: '10m' },
  },
  thresholds: {
    'http_req_duration{name:upload}': ['max>=0'],
    'http_req_duration{name:sample}': ['max>=0'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export function setup() {
  return { token: http.post(`${TARGET}/api/demo-token?kind=valid`).json('token') };
}

export default function (data) {
  const seed = SEED_BASE + exec.scenario.iterationInTest;
  const sample = http.get(`${TARGET}/api/sample.zip?files=10&seed=${seed}&fault_rate=0.03`, { responseType: 'binary', tags: { name: 'sample' } });
  const upload = http.post(`${TARGET}/api/imports?lock_order=${LOCK_ORDER}`, sample.body, {
    headers: { Authorization: `Bearer ${data.token}`, 'Content-Type': 'application/zip' },
    tags: { name: 'upload' },
  });
  check(upload, { 'the ZIP was accepted': (r) => 202 === r.status });
}
