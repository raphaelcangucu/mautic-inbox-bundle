import assert from "node:assert/strict";
import test from "node:test";
import { umPorVez } from "../../Frontend/shared/umPorVez";

const adiavel = <T>() => {
  let resolver!: (v: T) => void;
  let rejeitar!: (e: unknown) => void;
  const promessa = new Promise<T>((ok, erro) => {
    resolver = ok;
    rejeitar = erro;
  });
  return { promessa, resolver, rejeitar };
};

test("dois envios numa conversa nao assumida disparam um unico take", async () => {
  const fila = umPorVez<string>();
  const porta = adiavel<string>();
  let chamadas = 0;

  const primeiro = fila(7, () => {
    chamadas += 1;
    return porta.promessa;
  });
  const segundo = fila(7, () => {
    chamadas += 1;
    return porta.promessa;
  });

  porta.resolver("tomada");

  assert.equal(await primeiro, "tomada");
  assert.equal(await segundo, "tomada", "o segundo recebe o mesmo resultado");
  assert.equal(chamadas, 1, "e um unico take foi ao servidor");
});

test("conversas diferentes nao esperam uma pela outra", async () => {
  const fila = umPorVez<number>();
  let chamadas = 0;

  const a = fila(7, async () => ++chamadas);
  const b = fila(8, async () => ++chamadas);

  await Promise.all([a, b]);
  assert.equal(chamadas, 2);
});

test("depois de terminar, a proxima chamada dispara de novo", async () => {
  const fila = umPorVez<number>();
  let chamadas = 0;
  const tarefa = async () => ++chamadas;

  await fila(7, tarefa);
  await fila(7, tarefa);

  assert.equal(chamadas, 2, "a trava e por voo, nao por conversa para sempre");
});

test("uma falha nao deixa a conversa travada", async () => {
  const fila = umPorVez<number>();
  let chamadas = 0;

  await assert.rejects(
    fila(7, async () => {
      chamadas += 1;
      throw new Error("409");
    }),
  );

  // Sem o finally encadeado, esta segunda chamada devolveria a promessa ja rejeitada da
  // primeira e a conversa nunca mais conseguiria ser tomada.
  assert.equal(await fila(7, async () => ++chamadas), 2);
});

test("quem chega junto recebe a MESMA falha, e nao um sucesso silencioso", async () => {
  const fila = umPorVez<number>();
  const porta = adiavel<number>();

  const primeiro = fila(7, () => porta.promessa);
  const segundo = fila(7, () => porta.promessa);
  porta.rejeitar(new Error("conflito de versao"));

  await assert.rejects(primeiro, /conflito de versao/);
  await assert.rejects(segundo, /conflito de versao/);
});
