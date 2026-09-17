<script lang="ts">
  export let name = "chat";
  const paths: Record<string, string> = {
    facebook: "M14 21v-9h3l1-4h-4V6c0-1 1-2 2-2h2V1h-3c-4 0-5 3-5 5v2H7v4h3v9",
    inbox: "M4 4h16v16H4z M4 14h5l2 3h2l2-3h5",
    chat: "M20 11a8 8 0 0 1-8 8H6l-4 3V11a9 9 0 0 1 18 0z",
    comment: "M4 4h16v12H9l-5 4z M8 8h8 M8 12h5",
    bolt: "M13 2L5 14h6l-1 8 9-13h-6z",
    search: "M21 21l-5-5 M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0",
    filter: "M4 6h16 M7 12h10 M10 18h4",
    panel: "M3 4h18v16H3z M15 4v16",
    back: "M14 5l-7 7 7 7",
    reply: "M9 5l-6 6 6 6 M3 11h11a7 7 0 0 1 7 7",
    note: "M5 3h14v14l-4 4H5z M9 8h6 M9 12h6",
    lock: "M6 10h12v11H6z M8 10V6a4 4 0 0 1 8 0v4",
    shield: "M12 2l8 4v6c0 5-8 10-8 10S4 17 4 12V6z M8 12l3 3 5-6",
    instagram:
      "M6 3h12a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3z M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M17.5 6.5h.01",
    whatsapp: "M21 11a9 9 0 0 1-13 8l-5 2 1-5a9 9 0 1 1 17-5 M8 7c0 5 4 9 9 9",
    settings:
      "M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21h-4v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3v-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5V3h4v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.1v4h-.1a1.7 1.7 0 0 0-1.5 1z",
    sound: "M11 5 6 9H3v6h3l5 4z M15 9a4 4 0 0 1 0 6 M18 6a8 8 0 0 1 0 12",
    agent:
      "M12 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8z M4 21a8 8 0 0 1 16 0 M18 5h4 M20 3v4",
    // Aviao de papel inclinado para cima e para a DIREITA: a ponta em 21,5, a dobra descendo
    // ate o entalhe em 10,14. Espelhado ele vira um botao de "voltar"; na horizontal, contornado,
    // ele deixa de parecer aviao e vira uma seta de avancar — as duas foram conferidas na tela.
    send: "M21 5L3 11l7 3 3 7z M21 5l-11 9",
    clock: "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M12 7v5l3 2",
  };
</script>

<span data-icon={name}
  ><svg viewBox="0 0 24 24" aria-hidden="true"
    ><path d={paths[name] || paths.chat}></path></svg
  ></span
>
