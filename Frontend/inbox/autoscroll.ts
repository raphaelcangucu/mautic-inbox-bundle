/**
 * Quando o historico deve saltar para o fim.
 *
 * A regra mora aqui, e nao dentro do afterUpdate, porque ela tem tres casos e um deles so
 * acontece na juncao de dois renders — o tipo de coisa que ninguem reproduz a mao e que um teste
 * prende em tres linhas.
 */
export interface EstadoDaRolagem {
  /** A conversa cujo conteudo ja foi mostrado. */
  dono: number;
  /** Quantos itens o render anterior desenhou. */
  vistos: number;
  /** A conversa foi escolhida, mas o conteudo dela ainda nao chegou. */
  aguardandoConteudo: boolean;
}

export const rolagemInicial = (): EstadoDaRolagem => ({
  dono: 0,
  vistos: 0,
  aguardandoConteudo: false,
});

/** A partir de quantos pixels do fim consideramos que o atendente rolou para tras de proposito. */
export const PERTO_DO_FIM = 180;

export function decidirRolagem(
  estado: EstadoDaRolagem,
  entrada: { conversationId: number; visiveis: number; distanciaDoFim: number },
): { aoFim: boolean; estado: EstadoDaRolagem } {
  let { dono, vistos, aguardandoConteudo } = estado;

  if (dono !== entrada.conversationId) {
    dono = entrada.conversationId;
    vistos = 0;
    aguardandoConteudo = true;
  }

  let aoFim: boolean;

  if (aguardandoConteudo) {
    // A posse so e tomada quando ha conteudo. Entre escolher a conversa e desenhar as mensagens
    // existe um render com a lista vazia; reivindicar a conversa ali fazia o render seguinte —
    // o que de fato desenha o historico — cair no ramo de baixo, onde a distancia ate o fim
    // acabara de saltar de zero para a altura inteira da conversa. O ramo concluia, corretamente
    // para o caso dele, que o atendente havia rolado para tras — e toda conversa abria no topo.
    aoFim = true;
    if (entrada.visiveis > 0) {
      aguardandoConteudo = false;
    }
  } else {
    // Depois disso a regra e de cortesia: mensagem nova puxa o fim, mas so se o atendente ja
    // estiver perto dele. Quem subiu para ler algo antigo nao e arrancado de la.
    aoFim = entrada.visiveis >= vistos && entrada.distanciaDoFim < PERTO_DO_FIM;
  }

  return {
    aoFim,
    estado: { dono, vistos: entrada.visiveis, aguardandoConteudo },
  };
}
