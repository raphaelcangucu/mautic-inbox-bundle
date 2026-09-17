import assert from "node:assert/strict";
import test from "node:test";
import {
  decidirRolagem,
  rolagemInicial,
  type EstadoDaRolagem,
} from "../../Frontend/inbox/autoscroll";

const passo = (
  estado: EstadoDaRolagem,
  conversationId: number,
  visiveis: number,
  distanciaDoFim: number,
) => decidirRolagem(estado, { conversationId, visiveis, distanciaDoFim });

test("abrir uma conversa cai no fim, mesmo o conteudo chegando depois", () => {
  // Este e o defeito que o usuario viu: a conversa abria no topo e era preciso rolar.
  // Sao dois renders. No primeiro a conversa foi escolhida e a lista esta vazia; no segundo o
  // historico chegou e a distancia ate o fim saltou de zero para a altura inteira.
  let { aoFim, estado } = passo(rolagemInicial(), 22, 0, 0);
  assert.equal(aoFim, true, "o render vazio ja aponta para o fim");

  ({ aoFim, estado } = passo(estado, 22, 30, 4200));
  assert.equal(
    aoFim,
    true,
    "o render que traz as mensagens tambem, por maior que seja a distancia",
  );
  assert.equal(estado.aguardandoConteudo, false, "so agora a posse e tomada");
});

test("uma conversa que ja estava em cache tambem abre no fim", () => {
  const { aoFim, estado } = passo(rolagemInicial(), 22, 30, 4200);
  assert.equal(aoFim, true);
  assert.equal(estado.aguardandoConteudo, false, "um render bastou");
});

test("depois de aberta, quem subiu para ler nao e arrancado de la", () => {
  let { estado } = passo(rolagemInicial(), 22, 30, 0);

  // O atendente rolou para cima e chega mensagem nova.
  let decisao = passo(estado, 22, 31, 3000);
  assert.equal(decisao.aoFim, false, "3000 pixels acima do fim e deliberado");

  // Perto do fim, a mensagem nova puxa.
  decisao = passo(decisao.estado, 22, 32, 40);
  assert.equal(decisao.aoFim, true);
});

test("carregar anteriores nao arrasta para o fim", () => {
  // O caminho de "carregar anteriores" acrescenta itens no COMECO e preserva a posicao por conta
  // propria. Aqui a garantia e a outra metade: a contagem cresce, mas a distancia continua
  // enorme, e a decisao e nao mexer.
  const { estado } = passo(rolagemInicial(), 22, 30, 0);
  assert.equal(passo(estado, 22, 80, 9000).aoFim, false);
});

test("trocar de conversa recomeca a contagem", () => {
  const primeira = passo(rolagemInicial(), 22, 30, 0).estado;
  const segunda = passo(primeira, 41, 0, 0);

  assert.equal(segunda.aoFim, true);
  assert.equal(segunda.estado.dono, 41);
  assert.equal(
    segunda.estado.vistos,
    0,
    "a contagem da conversa anterior nao pode decidir sobre a nova",
  );

  // Voltar para a primeira e uma abertura como qualquer outra.
  assert.equal(passo(segunda.estado, 22, 30, 5000).aoFim, true);
});
