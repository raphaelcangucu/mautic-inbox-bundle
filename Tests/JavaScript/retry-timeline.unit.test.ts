import assert from "node:assert/strict";
import test from "node:test";
import { collapseRetries } from "../../Frontend/inbox/retry-timeline";
import type { TimelineItem } from "../../Frontend/shared/types";
const first: TimelineItem = {
  id: 1,
  kind: "outbound",
  request_id: "first",
  body: "Controlled message",
  status: "failed",
  timestamp: "2026-10-07T18:00:00Z",
};
test("a retry chain has one stable bubble with the newest delivery state and preserves raw attempts", () => {
  const second = {
    ...first,
    id: 2,
    request_id: "second",
    retry_of: "first",
    timestamp: "2026-10-07T18:00:20Z",
  };
  const third = {
    ...first,
    id: 3,
    request_id: "third",
    retry_of: "second",
    timestamp: "2026-10-07T18:01:00Z",
    status: "sent",
    retryable: false,
  };
  const independent = { ...first, id: 4, request_id: "independent" };
  const raw = [first, second, third, independent];
  const rows = collapseRetries(raw);
  assert.equal(rows.length, 2);
  assert.equal(rows[0].display_id, 1);
  assert.equal(rows[0].id, 3);
  assert.equal(rows[0].status, "sent");
  assert.equal(rows[0].attempt_count, 3);
  assert.equal(raw.length, 4);
  assert.equal(collapseRetries([third, second, first])[0].id, 3);
});
test("different contents, missing parents and malformed cycles remain visible", () => {
  assert.equal(
    collapseRetries([
      first,
      {
        ...first,
        id: 2,
        request_id: "other",
        retry_of: "first",
        body: "Different message",
      },
    ]).length,
    2,
  );
  assert.equal(
    collapseRetries([
      { ...first, retry_of: "other" },
      { ...first, id: 2, request_id: "other", retry_of: "first" },
    ]).length,
    2,
  );
});
