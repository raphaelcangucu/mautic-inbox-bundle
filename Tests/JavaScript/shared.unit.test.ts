import assert from "node:assert/strict";
import test from "node:test";
import { JSDOM } from "jsdom";
import { createInboxAlerts } from "../../Frontend/shared/alerts";
import { createInboxHistory } from "../../Frontend/shared/history";
import { messageBody } from "../../Frontend/shared/markdown";

function expose(window: Window & typeof globalThis): void {
  Object.assign(globalThis, {
    window,
    document: window.document,
    location: window.location,
    history: window.history,
    Node: window.Node,
    HTMLElement: window.HTMLElement,
    HTMLOListElement: window.HTMLOListElement,
    Image: window.Image,
    localStorage: window.localStorage,
  });
  Object.defineProperty(globalThis, "navigator", {
    value: window.navigator,
    configurable: true,
  });
}

test("message renderer keeps formatting useful and untrusted HTML inert", () => {
  const dom = new JSDOM("<!doctype html><body></body>", {
    url: "https://mautic.test/inbox",
  });
  expose(dom.window as unknown as Window & typeof globalThis);
  const render = (value: string, whatsapp = true) =>
    messageBody(value, whatsapp);
  let element = render(
    "*Continue setting up your account*\n\nGo to *Setup guidance* on *desktop*:\nhttps://business.facebook.com/latest/whatsapp%5Fmanager/setup%5Fguidance?nav%5Fref=nudge&asset%5Fid=1380901514187902",
  );
  assert.equal(element.querySelectorAll("strong").length, 3);
  assert.equal(element.querySelectorAll("p").length, 2);
  assert.ok(!element.textContent?.includes("nav%5Fref"));
  assert.equal(
    new URL(element.querySelector("a")!.href).searchParams.get("nav_ref"),
    "nudge",
  );
  element = render(
    "**Bold** and _italic_ and ~removed~\n\n- One\n- Two\n\n3. Third\n4. Fourth\n\n> Quote\n\n`literal *text*`",
  );
  assert.equal(element.querySelector("strong")?.textContent, "Bold");
  assert.equal(element.querySelector("em")?.textContent, "italic");
  assert.equal(element.querySelector("del")?.textContent, "removed");
  assert.equal(element.querySelectorAll("li").length, 4);
  assert.equal(element.querySelector("blockquote")?.textContent, "Quote");
  assert.equal(element.querySelector("code")?.textContent, "literal *text*");
  assert.equal(
    render("*italic*", false).querySelector("em")?.textContent,
    "italic",
  );
  assert.equal(
    render("```\n<img src=x onerror=alert(1)>\n```").querySelector("code")
      ?.textContent,
    "<img src=x onerror=alert(1)>",
  );
  element = render(
    "<script>alert(1)</script> <img src=x onerror=alert(1)> [bad](javascript:alert(1)) [report](https://macro.markets/pt/blog/test)",
  );
  assert.equal(element.querySelectorAll("script,img").length, 0);
  assert.equal(element.querySelectorAll("a").length, 1);
  assert.equal(element.querySelector("a")?.textContent, "report");
  assert.equal(element.querySelector("a")?.rel, "noopener noreferrer");
  assert.equal(render("some_id_value").textContent, "some_id_value");
  assert.equal(
    render("No formatting here.").textContent,
    "No formatting here.",
  );
  dom.window.close();
});

test("conversation history preserves local Inbox navigation and releases popstate", () => {
  const dom = new JSDOM("<!doctype html><title>Support inbox</title>", {
    url: "https://mautic.test/s/inbox",
  });
  expose(dom.window as unknown as Window & typeof globalThis);
  const opened: number[] = [];
  let cleared = 0;
  let stopped = 0;
  const inboxHistory = createInboxHistory(
    "/s/inbox",
    "/s/inbox/conversations/0",
    (id) => opened.push(id),
    () => {
      cleared++;
    },
  );
  assert.equal(inboxHistory.current(), 0);
  inboxHistory.open(17);
  assert.equal(location.pathname, "/s/inbox/conversations/17");
  assert.equal(history.state.mauticInbox, true);
  assert.equal(history.state.stateId, 17);
  const length = history.length;
  inboxHistory.open(17);
  assert.equal(history.length, length);
  inboxHistory.clear(true);
  assert.equal(location.pathname, "/s/inbox");
  history.replaceState({}, "", "/s/inbox/conversations/29");
  window.dispatchEvent(
    Object.assign(new dom.window.PopStateEvent("popstate"), {
      stopImmediatePropagation() {
        stopped++;
      },
    }),
  );
  assert.deepEqual(opened, [29]);
  assert.equal(stopped, 1);
  history.replaceState({}, "", "/s/inbox");
  window.dispatchEvent(
    Object.assign(new dom.window.PopStateEvent("popstate"), {
      stopImmediatePropagation() {
        stopped++;
      },
    }),
  );
  assert.equal(cleared, 1);
  history.replaceState({}, "", "/s/meta");
  window.dispatchEvent(
    Object.assign(new dom.window.PopStateEvent("popstate"), {
      stopImmediatePropagation() {
        stopped++;
      },
    }),
  );
  assert.equal(stopped, 2, "routes outside Inbox remain under Mautic control");
  assert.deepEqual(opened, [29]);
  inboxHistory.dispose();
  history.replaceState({}, "", "/s/inbox/conversations/31");
  window.dispatchEvent(new dom.window.PopStateEvent("popstate"));
  assert.deepEqual(opened, [29]);
  dom.window.close();
});

test("notifications honor gesture, deduplicate, mute, badge and cleanup", async () => {
  const dom = new JSDOM(
    '<!doctype html><head><link rel="icon" href="/favicon.ico"></head><body></body>',
    { url: "https://mautic.test/inbox" },
  );
  expose(dom.window as unknown as Window & typeof globalThis);
  let starts = 0;
  class AudioContextStub {
    state: AudioContextState = "suspended";
    currentTime = 0;
    destination = {} as AudioDestinationNode;
    resume = async () => {
      this.state = "running";
    };
    close = async () => {
      this.state = "closed";
    };
    createOscillator = () =>
      ({
        frequency: { value: 0 },
        connect() {},
        start() {
          starts++;
        },
        stop() {},
      }) as unknown as OscillatorNode;
    createGain = () =>
      ({
        gain: {
          setValueAtTime() {},
          linearRampToValueAtTime() {},
          exponentialRampToValueAtTime() {},
        },
        connect() {},
      }) as unknown as GainNode;
  }
  Object.defineProperty(dom.window, "AudioContext", {
    value: AudioContextStub,
    configurable: true,
  });
  Object.defineProperty(dom.window.HTMLCanvasElement.prototype, "getContext", {
    value: () => ({
      fillStyle: "",
      font: "",
      textAlign: "",
      textBaseline: "",
      fillRect() {},
      drawImage() {},
      beginPath() {},
      arc() {},
      fill() {},
      fillText() {},
    }),
    configurable: true,
  });
  Object.defineProperty(dom.window.HTMLCanvasElement.prototype, "toDataURL", {
    value: () => "data:image/png;base64,test",
    configurable: true,
  });
  const states: Array<{
    enabled: boolean;
    ready: boolean;
    unavailable: boolean;
    pending: number;
  }> = [];
  const alerts = createInboxAlerts(1, (state) => states.push(state));
  assert.equal(starts, 0);
  assert.equal(states.at(-1)?.enabled, true);
  assert.equal(states.at(-1)?.ready, false);
  alerts.unlock();
  await Promise.resolve();
  await Promise.resolve();
  assert.equal(states.at(-1)?.enabled, true);
  assert.equal(states.at(-1)?.ready, true);
  alerts.receive([{ id: 10, state_id: 2 }]);
  assert.equal(starts, 2);
  assert.equal(states.at(-1)?.pending, 1);
  assert.match(document.querySelector("link")?.href || "", /^data:image\//);
  alerts.receive([{ id: 10, state_id: 2 }]);
  assert.equal(starts, 2);
  alerts.acknowledge(3);
  assert.equal(states.at(-1)?.pending, 1);
  assert.match(document.querySelector("link")?.href || "", /^data:image\//);
  alerts.acknowledge(2);
  assert.equal(states.at(-1)?.pending, 0);
  assert.equal(
    document.querySelector("link")?.getAttribute("href"),
    "/favicon.ico",
  );
  alerts.toggle();
  alerts.receive([{ id: 11, state_id: 3 }]);
  assert.equal(starts, 2);
  assert.equal(states.at(-1)?.enabled, false);
  alerts.dispose();
  assert.equal(
    document.querySelector("link")?.getAttribute("href"),
    "/favicon.ico",
  );
  dom.window.close();
});
