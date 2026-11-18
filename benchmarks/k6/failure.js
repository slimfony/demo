// Failure injection: a steady authenticated read (a verified token and a database read) while run-failure.sh breaks
// things around the service. Every request is tagged with the 5-second slice of the run it was started in, so the
// summary shows when the errors and the latency spikes happened, and how fast the service came back.
import http from 'k6/http';
import exec from 'k6/execution';

const TARGET = __ENV.TARGET || 'http://web:8080';
const RATE = Number(__ENV.RATE || 1500);
const SECONDS = Number(__ENV.SECONDS || 150);
const SLICES = Math.ceil(SECONDS / 5);

const thresholds = {};
for (let i = 0; i < SLICES; i++) {
  thresholds[`http_req_failed{slice:${i * 5}}`] = ['rate>=0'];
  thresholds[`http_req_duration{slice:${i * 5}}`] = ['max>=0'];
  thresholds[`http_reqs{slice:${i * 5}}`] = ['count>=0'];
}

export const options = {
  scenarios: {
    steady: { executor: 'constant-arrival-rate', rate: RATE, timeUnit: '1s', duration: `${SECONDS}s`, preAllocatedVUs: 200, maxVUs: 2000 },
  },
  thresholds,
  discardResponseBodies: true,
  summaryTrendStats: ['avg', 'p(99)', 'max'],
};

http.setResponseCallback(http.expectedStatuses(200));

export function setup() {
  const token = http.post(`${TARGET}/api/demo-token?kind=valid`, null, { responseType: 'text' }).json('token');
  const zip = http.get(`${TARGET}/api/sample.zip?files=10&seed=${Date.now() % 900000000}`, { responseType: 'binary' });
  const upload = http.post(`${TARGET}/api/imports`, zip.body, { headers: { Authorization: `Bearer ${token}` }, responseType: 'text' });
  return { token, id: upload.json('id') };
}

export default function (data) {
  const slice = Math.floor(exec.instance.currentTestRunDuration / 5000) * 5;
  http.get(`${TARGET}/api/batches/${data.id}`, { headers: { Authorization: `Bearer ${data.token}` }, tags: { slice: String(slice) } });
}
