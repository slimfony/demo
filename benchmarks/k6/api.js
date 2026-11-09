// The API lane: how many requests per second the API answers, and how its latency changes as the rate rises.
// Each rate runs for a while on its own (constant arrival rate: k6 starts requests at that pace whether or not the
// server keeps up, so a server that cannot keep up shows dropped iterations and a rising latency, not a polite slowdown).
//
//   ROUTE=health  GET /healthz (no authentication, no database): the cost of the worker, the middleware chain and the metrics
//   ROUTE=auth    GET /api/batches/{id} with a verified token and a database read: a real authenticated request
import http from 'k6/http';
import { check } from 'k6';

const TARGET = __ENV.TARGET || 'http://web:8080';
const ROUTE = __ENV.ROUTE || 'health';
const RATES = (__ENV.RATES || '1000,2500,5000,7500,10000,12500').split(',').map(Number);
const STEP_SECONDS = Number(__ENV.STEP_SECONDS || 15);
const PAUSE_SECONDS = 4; // between the steps, so one step's backlog does not leak into the next

const scenarios = {};
const thresholds = {};
RATES.forEach((rate, i) => {
  scenarios[`rate_${rate}`] = {
    executor: 'constant-arrival-rate',
    rate,
    timeUnit: '1s',
    duration: `${STEP_SECONDS}s`,
    startTime: `${i * (STEP_SECONDS + PAUSE_SECONDS)}s`,
    preAllocatedVUs: Math.min(1500, Math.ceil(rate / 15) + 50),
    maxVUs: 4000,
    tags: { rate: String(rate) },
  };
  // a threshold that always holds makes k6 report the figures of every step on its own
  thresholds[`http_req_duration{rate:${rate}}`] = ['max>=0'];
  thresholds[`http_reqs{rate:${rate}}`] = ['count>=0'];
  thresholds[`http_req_failed{rate:${rate}}`] = ['rate>=0'];
  thresholds[`dropped_iterations{rate:${rate}}`] = ['count>=0'];
});

export const options = {
  scenarios,
  thresholds,
  discardResponseBodies: true,
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

http.setResponseCallback(http.expectedStatuses(200));

export function setup() {
  if ('auth' !== ROUTE) return {};
  // the bodies of the answers are discarded for the benchmark itself, but this setup needs two of them
  const token = http.post(`${TARGET}/api/demo-token?kind=valid`, null, { responseType: 'text' }).json('token');
  const zip = http.get(`${TARGET}/api/sample.zip?files=10&seed=${Date.now() % 900000000}`, { responseType: 'binary' });
  const upload = http.post(`${TARGET}/api/imports`, zip.body, { headers: { Authorization: `Bearer ${token}` }, responseType: 'text' });
  check(upload, { 'a batch to read exists': (r) => 202 === r.status });

  return { token, id: upload.json('id') };
}

export default function (data) {
  if ('auth' === ROUTE) {
    http.get(`${TARGET}/api/batches/${data.id}`, { headers: { Authorization: `Bearer ${data.token}` } });
  } else {
    http.get(`${TARGET}/healthz`);
  }
}
