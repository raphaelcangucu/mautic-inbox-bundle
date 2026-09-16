export function messageBody(
  value: string,
  whatsapp: boolean,
  unsupported = "Unsupported link",
): HTMLElement {
  const container = document.createElement("div");
  container.className = "inbox-message-body";
  function inline(parent: Node, text: string, depth: number): void {
    if (depth > 8) {
      parent.appendChild(document.createTextNode(text));
      return;
    }
    const tokens =
      /(`[^`\n]+`|\[[^\]\n]+\]\(https?:\/\/[^\s<>]+?\)|https?:\/\/[^\s<>]+|\*\*[^\n]+?\*\*|__[^\n]+?__|~~[^\n]+?~~|\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~)/g;
    let last = 0;
    let match: RegExpExecArray | null;
    while ((match = tokens.exec(text))) {
      parent.appendChild(
        document.createTextNode(text.slice(last, match.index)),
      );
      const token = match[0];
      let node: Node;
      let suffix = "";
      if (token[0] === "`") {
        const code = document.createElement("code");
        code.textContent = token.slice(1, -1);
        node = code;
      } else if (/^https?:\/\//.test(token) || token[0] === "[") {
        const labeled = /^\[([^\]]+)\]\((.+)\)$/.exec(token);
        let raw = labeled ? labeled[2] : token;
        if (!labeled) {
          suffix = (raw.match(/[.,!?;:)]+$/) || [""])[0];
          raw = raw.slice(0, raw.length - suffix.length);
        }
        try {
          const url = new URL(raw);
          if (!/^https?:$/.test(url.protocol)) throw new Error(unsupported);
          const anchor = document.createElement("a");
          anchor.href = url.href;
          anchor.target = "_blank";
          anchor.rel = "noopener noreferrer";
          anchor.title = raw;
          if (labeled) inline(anchor, labeled[1], depth + 1);
          else {
            const label =
              url.hostname + (url.pathname === "/" ? "" : url.pathname);
            anchor.textContent =
              label.length > 64 ? `${label.slice(0, 61)}…` : label;
          }
          node = anchor;
        } catch {
          node = document.createTextNode(token);
          suffix = "";
        }
      } else {
        const double = /^(\*\*|__|~~)/.test(token);
        const size = double ? 2 : 1;
        if (
          token[0] === "_" &&
          /[\p{L}\p{N}]/u.test(text[match.index - 1] || "")
        )
          node = document.createTextNode(token);
        else {
          const tag =
            token[0] === "~"
              ? "del"
              : double
                ? "strong"
                : token[0] === "*" && whatsapp
                  ? "strong"
                  : "em";
          const element = document.createElement(tag);
          inline(element, token.slice(size, -size), depth + 1);
          node = element;
        }
      }
      parent.appendChild(node);
      if (suffix) parent.appendChild(document.createTextNode(suffix));
      last = tokens.lastIndex;
    }
    parent.appendChild(document.createTextNode(text.slice(last)));
  }
  const lines = String(value || "")
    .replace(/\r\n?/g, "\n")
    .split("\n");
  let list: HTMLOListElement | HTMLUListElement | null = null;
  let paragraph: HTMLParagraphElement | null = null;
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    let match: RegExpExecArray | null;
    if (/^\s*```/.test(line)) {
      list = paragraph = null;
      const codeLines: string[] = [];
      const opening = line.replace(/^\s*```/, "");
      if (opening.endsWith("```")) codeLines.push(opening.slice(0, -3));
      else
        while (++i < lines.length && !/^\s*```\s*$/.test(lines[i]))
          codeLines.push(lines[i]);
      const pre = document.createElement("pre");
      const code = document.createElement("code");
      code.textContent = codeLines.join("\n");
      pre.appendChild(code);
      container.appendChild(pre);
      continue;
    }
    if (!line.trim()) {
      list = paragraph = null;
      continue;
    }
    if ((match = /^\s*(?:([-*])\s+|(\d+)\.\s+)(.+)$/.exec(line))) {
      const type = match[2] ? "ol" : "ul";
      if (!list || list.tagName.toLowerCase() !== type) {
        list = document.createElement(type);
        if (match[2] && list instanceof HTMLOListElement)
          list.start = Number(match[2]);
        container.appendChild(list);
      }
      paragraph = null;
      const li = document.createElement("li");
      inline(li, match[3], 0);
      list.appendChild(li);
      continue;
    }
    list = null;
    if ((match = /^(#{1,6})\s+(.+)$/.exec(line))) {
      paragraph = null;
      const heading = document.createElement("p");
      heading.className = "inbox-message-heading";
      inline(heading, match[2], 0);
      container.appendChild(heading);
      continue;
    }
    if ((match = /^>\s?(.*)$/.exec(line))) {
      paragraph = null;
      const quote = document.createElement("blockquote");
      inline(quote, match[1], 0);
      container.appendChild(quote);
      continue;
    }
    if (!paragraph) {
      paragraph = document.createElement("p");
      container.appendChild(paragraph);
    } else paragraph.appendChild(document.createElement("br"));
    inline(paragraph, line, 0);
  }
  return container;
}

export function renderMessage(
  node: HTMLElement,
  params: { value: string; whatsapp: boolean; unsupported?: string },
) {
  const update = (next: typeof params) =>
    node.replaceChildren(
      messageBody(next.value, next.whatsapp, next.unsupported),
    );
  update(params);
  return { update };
}

export function markdownPreview(value: string): HTMLElement {
  return messageBody(value, false);
}
