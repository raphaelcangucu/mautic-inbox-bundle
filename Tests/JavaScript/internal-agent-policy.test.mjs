import test from "node:test";
import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import { execFileSync } from "node:child_process";

test("internal agents enforce role, tool caps and no fallback without a database", () => {
  const output = execFileSync(
    "php",
    [
      fileURLToPath(
        new URL("../Standalone/internal-agent-policy.php", import.meta.url),
      ),
    ],
    { encoding: "utf8" },
  );
  assert.match(output, /all checks passed/);
});
