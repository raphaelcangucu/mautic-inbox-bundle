/**
 * Uma tarefa em voo por chave. Quem chega depois espera a que ja esta correndo, em vez de
 * disparar a sua.
 *
 * Existe por causa do `take`. Ele manda a `version` que o cliente leu no momento da chamada.
 * Sem trava de envio — e solta-la e a premissa desta mudanca — dois envios disparados dentro
 * da mesma ida de rede leem a MESMA versao: o primeiro a incrementa, o segundo leva 409. O
 * atendente veria a segunda mensagem marcada como falha embora a conversa tenha sido tomada
 * com sucesso, e nada na tela explicaria por que.
 *
 * A janela e estreita, mas mandar duas mensagens seguidas e justamente a premissa do desenho.
 */
export function umPorVez<T>(): (
  chave: string | number,
  tarefa: () => Promise<T>,
) => Promise<T> {
  const emVoo = new Map<string | number, Promise<T>>();

  return (chave, tarefa) => {
    const corrente = emVoo.get(chave);
    if (undefined !== corrente) {
      return corrente;
    }

    // A limpeza vai num finally encadeado, e nao depois do await: se a tarefa lancar, a chave
    // precisa sair do mapa do mesmo jeito, senao a conversa fica travada para sempre.
    const proxima = tarefa().finally(() => {
      if (proxima === emVoo.get(chave)) {
        emVoo.delete(chave);
      }
    });

    emVoo.set(chave, proxima);

    return proxima;
  };
}
