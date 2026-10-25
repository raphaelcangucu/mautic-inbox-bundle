// Ponto de entrada do teste de montagem dos componentes de e-mail: o vite compila os .svelte e
// os .ts que eles importam, e o runtime do svelte sai junto, para o teste montar com a mesma
// instancia que os componentes usam.
export { mount, unmount, flushSync, tick } from "svelte";
export { default as EmailThread } from "../../../Frontend/inbox/EmailThread.svelte";
export { default as EmailCard } from "../../../Frontend/inbox/EmailCard.svelte";
export { default as EmailBody } from "../../../Frontend/inbox/EmailBody.svelte";
export { default as EmailComposer } from "../../../Frontend/inbox/EmailComposer.svelte";
export { default as ConversationList } from "../../../Frontend/inbox/ConversationList.svelte";
