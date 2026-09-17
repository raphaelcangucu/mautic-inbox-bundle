import assert from "node:assert/strict";
import test from "node:test";
import { criarGesto } from "../../Frontend/shared/pullToRefresh";

/** Uma area rolavel de mentira, com o alvo do toque apontando para ela. */
function area(scrollTop = 0) {
  const caixa = { scrollTop };
  return {
    caixa,
    alvo: { closest: () => caixa },
  };
}

const toque = (alvo: unknown, y: number, cancelavel = true) => {
  let impedido = false;
  return {
    evento: {
      target: alvo,
      touches: [{ clientY: y }],
      cancelable: cancelavel,
      preventDefault: () => {
        impedido = true;
      },
    },
    impedido: () => impedido,
  };
};

function espiao() {
  const movimentos: number[] = [];
  let soltou = 0;
  return {
    movimentos,
    soltou: () => soltou,
    gesto: {
      aoMover: (d: number) => movimentos.push(d),
      aoSoltar: () => {
        soltou += 1;
      },
    },
  };
}

test("puxar do topo alem do limite atualiza", () => {
  const e = espiao();
  const g = criarGesto(e.gesto);
  const a = area(0);

  g.tocar(toque(a.alvo, 100).evento);
  // Metade do caminho: 300 pixels de dedo viram 150 de indicador, acima do limite de 72.
  g.mover(toque(a.alvo, 250).evento);
  g.soltar();

  assert.equal(e.soltou(), 1, "soltou alem do limite: atualiza");
  assert.ok(
    e.movimentos.some((d) => d >= 72),
    "o indicador chegou a passar do limite",
  );
});

test("puxar sem chegar ao limite nao atualiza, e recolhe o indicador", () => {
  const e = espiao();
  const g = criarGesto(e.gesto);
  const a = area(0);

  g.tocar(toque(a.alvo, 100).evento);
  g.mover(toque(a.alvo, 140).evento); // 40 de dedo, 20 de indicador
  g.soltar();

  assert.equal(e.soltou(), 0, "nao passou do limite: nada acontece");
  assert.equal(
    e.movimentos.at(-1),
    0,
    "o indicador volta a zero em vez de ficar pendurado",
  );
});

test("com a lista rolada o puxao continua sendo rolagem", () => {
  const e = espiao();
  const g = criarGesto(e.gesto);
  const a = area(340);

  g.tocar(toque(a.alvo, 100).evento);
  g.mover(toque(a.alvo, 400).evento);
  g.soltar();

  assert.equal(e.soltou(), 0, "no meio do historico, puxar para baixo e ler");
  assert.deepEqual(e.movimentos, [], "e o indicador nem aparece");
});

test("rolar durante o gesto cancela o que ja tinha sido puxado", () => {
  const e = espiao();
  const g = criarGesto(e.gesto);
  const a = area(0);

  g.tocar(toque(a.alvo, 100).evento);
  g.mover(toque(a.alvo, 300).evento);
  a.caixa.scrollTop = 25;
  g.mover(toque(a.alvo, 320).evento);
  g.soltar();

  assert.equal(e.soltou(), 0, "desistiu no meio: nao atualiza");
  assert.equal(e.movimentos.at(-1), 0, "e o indicador some");
});

test("fora de uma area rolavel o gesto nao existe", () => {
  const e = espiao();
  const g = criarGesto(e.gesto);
  const solto = { closest: () => null };

  g.tocar(toque(solto, 100).evento);
  g.mover(toque(solto, 400).evento);
  g.soltar();

  assert.equal(e.soltou(), 0);
  assert.deepEqual(e.movimentos, []);
});

test("o puxao segura a pagina, para o iOS nao esticar tudo junto", () => {
  const e = espiao();
  const g = criarGesto(e.gesto);
  const a = area(0);

  g.tocar(toque(a.alvo, 100).evento);
  const movimento = toque(a.alvo, 260);
  g.mover(movimento.evento);

  assert.equal(movimento.impedido(), true);
});
