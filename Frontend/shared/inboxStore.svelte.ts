import { endpoint, jsonRequest, RequestError, requestId } from "./api";
import { criar, estadoVazio, type Buscar } from "./store/fetcher";
import {
  acceptSend,
  applyServerItems,
  beginRetry,
  failSend,
  findPending,
  startSend,
} from "./store/reducer";
import type { InboxState, ListFilter, PollResult } from "./store/types";
import type { Conversation, TimelineItem } from "./types";

/**
 * A unica fonte de verdade do cliente, e uma casca fina por cima dela.
 *
 * Aqui nao se decide nada. Quem decide o que entra no estado e o reducer; quem decide o que
 * vai a rede e o fetcher. Este arquivo tem o `$state`, gera o que so a borda pode gerar —
 * instante e identificador — e delega. Uma condicao escrita aqui e uma regra que deixou de
 * ter teste, porque `$state` e construcao de compilador e o `tsx` do pipeline nao a compila.
 */

export interface DependenciasDaStore {
  csrf: string;
  urls: Record<string, string>;
  /** Mesma razao do fetcher: a rede entra por parametro para o teste poder adia-la. */
  buscar?: Buscar;
}

/**
 * O que o envio devolve. Os tres campos sao opcionais porque as duas rotas respondem coisas
 * diferentes e uma delas ainda vai mudar: `reply` devolve o item criado e o resumo, `note`
 * devolve so o id — que e a chave que a nota nao tinha antes da resposta voltar.
 */
interface RespostaDoEnvio {
  item?: TimelineItem;
  summary?: Conversation;
  id?: number;
}

function mensagemDe(erro: unknown): string {
  return erro instanceof Error ? erro.message : String(erro);
}

/**
 * O que adianta tentar de novo e o que nao adianta.
 *
 * Rede caida e servidor fora do ar voltam sozinhos. Recusa do canal — janela fechada,
 * permissao, corpo invalido — nao volta nunca, e oferecer o botao ali faria o atendente
 * repetir uma mensagem que jamais vai sair.
 */
function retentavel(erro: unknown): boolean {
  const status = erro instanceof RequestError ? erro.status : 0;

  return status < 400 || status >= 500;
}

export function criarInboxStore(deps: DependenciasDaStore) {
  let estado = $state<InboxState>(estadoVazio());

  const buscar: Buscar =
    deps.buscar ??
    ((url, options) => jsonRequest<unknown>(url, deps.csrf, options));

  // Os ganchos sao o ponto em que o fetcher para de ter estado proprio: ele le e escreve
  // ESTE `$state`, e nao uma copia que ficaria em silencio fora de sincronia com a tela.
  const fetcher = criar({
    buscar,
    csrf: deps.csrf,
    urls: deps.urls,
    lerEstado: () => estado,
    gravarEstado: (proximo) => {
      estado = proximo;
    },
  });

  const gravar = (proximo: InboxState): void => {
    estado = proximo;
  };

  /**
   * O caminho de rede de um envio, igual para o primeiro toque e para a retentativa — e
   * precisa ser igual, porque e o reuso da chave que impede a duplicata.
   *
   * A resposta vai inteira para o reducer: item presente vira historico, item ausente com id
   * vira a chave da nota. Ler a forma da resposta aqui seria decidir duas vezes.
   */
  async function despachar(envio: {
    conversationId: number;
    localId: string;
    mode: "reply" | "note";
    body: string;
    requestId?: string;
  }): Promise<void> {
    try {
      const resposta = (await buscar(
        endpoint(deps.urls[envio.mode], envio.conversationId),
        {
          method: "POST",
          body: JSON.stringify({
            body: envio.body,
            request_id: envio.requestId,
          }),
        },
      )) as RespostaDoEnvio;

      gravar(
        acceptSend(estado, {
          localId: envio.localId,
          item: resposta.item,
          noteId: resposta.id,
          summary: resposta.summary,
        }),
      );
    } catch (erro) {
      gravar(
        failSend(estado, {
          localId: envio.localId,
          failure: mensagemDe(erro),
          retryable: retentavel(erro),
        }),
      );
    }
  }

  return {
    get lists() {
      return estado.lists;
    },
    get conversations() {
      return estado.conversations;
    },
    get timelines() {
      return estado.timelines;
    },
    get pending() {
      return estado.pending;
    },

    async ensureList(filtro: ListFilter): Promise<void> {
      await fetcher.ensureList(filtro);
    },

    async ensureConversation(id: number): Promise<void> {
      await fetcher.ensureConversation(id);
    },

    async ensureTimeline(id: number): Promise<void> {
      await fetcher.ensureTimeline(id);
    },

    /**
     * As passagens abaixo nao sao metodos novos no sentido de regra: cada uma entrega ao
     * reducer ou ao fetcher exatamente o que recebeu.
     *
     * O desenho pedia seis metodos, e a contagem era um jeito de dizer "nenhuma decisao mora
     * aqui". Essa parte continua valendo — nao ha uma condicao nestas linhas. O que a
     * contagem nao previa e que o componente tem sete caminhos que escrevem historico e sete
     * que escrevem a conversa, e sem uma porta para eles a alternativa seria cada um mexer no
     * estado por fora, que e precisamente o que a porta unica existe para impedir.
     */

    /** As tres chamadas de abertura, juntas. */
    abrir(id: number) {
      return fetcher.abrirConversa(id);
    },

    /** Verdadeiro enquanto a abertura daquela conversa esta em voo: e o esqueleto girando. */
    carregando(id: number): boolean {
      return fetcher.carregando(id);
    },

    /** A porta unica do historico. Os sete caminhos entram por aqui, cada um com seu modo. */
    aplicarItens(acao: {
      conversationId: number;
      items: TimelineItem[];
      mode: "replace" | "merge" | "prepend";
      cursor?: string | null;
    }): void {
      gravar(applyServerItems(estado, acao));
    },

    /** O detalhe que voltou de um take, de uma transicao ou do proprio envio. */
    guardarConversa(conversa: Conversation): void {
      fetcher.guardarConversa(conversa);
    },

    /**
     * A pendente aparece no mesmo quadro do toque; o servidor confirma depois. O instante e a
     * chave nascem aqui porque o reducer e puro e nao gera nenhum dos dois.
     */
    async sendReply(
      conversationId: number,
      mode: "reply" | "note",
      body: string,
      /**
       * O que precisa dar certo antes de a mensagem sair — hoje, assumir a conversa e esperar
       * a escrita de rascunho em voo. Roda DEPOIS da pendente aparecer, porque o ganho visual
       * e justamente nao esperar por ele; se lancar, a pendente ja nasce marcada como falha,
       * com o texto do atendente dentro e o botao de tentar de novo.
       *
       * Fica no chamador, e nao aqui, porque depende de rascunho e de selecao — coisas do
       * componente. O que a store garante e que nenhum caminho deixe uma pendente presa em
       * "enviando".
       */
      preparar?: () => Promise<void>,
    ): Promise<void> {
      const localId = requestId();
      // Nota nao recebe chave. O servidor nao guarda request_id de nota e grava uma nova a
      // cada chamada: uma chave aqui fingiria uma idempotencia que a nota nao tem.
      const chave = "note" === mode ? undefined : requestId();

      gravar(
        startSend(estado, {
          conversationId,
          mode,
          body,
          requestId: chave,
          localId,
          now: new Date().toISOString(),
        }),
      );

      if (undefined !== preparar) {
        try {
          await preparar();
        } catch (erro) {
          gravar(
            failSend(estado, {
              localId,
              failure: mensagemDe(erro),
              retryable: retentavel(erro),
            }),
          );
          return;
        }
      }

      await despachar({
        conversationId,
        localId,
        mode,
        body,
        requestId: chave,
      });
    },

    /**
     * De novo, com o MESMO requestId e o mesmo texto — que e o que faz o servidor reconhecer
     * a segunda tentativa como a primeira quando foi so o retorno que se perdeu.
     */
    async retryPending(localId: string): Promise<void> {
      const pendente = findPending(estado, localId);
      if (undefined === pendente) {
        return;
      }

      gravar(beginRetry(estado, { localId }));

      await despachar({
        conversationId: pendente.conversationId,
        localId,
        mode: pendente.mode,
        body: pendente.body,
        requestId: pendente.requestId,
      });
    },

    /**
     * O poll ja traz os resumos que mudaram e o incremento do historico da conversa aberta.
     * Rebuscar os dois — que e o que o cliente faz hoje — sao duas idas a mais por tique.
     *
     * O historico do poll e INCREMENTAL e so da conversa que o chamador pediu por `state_id`:
     * entra por "merge". Um "replace" aqui trocaria a conversa inteira pelas ultimas
     * mensagens a cada tique. O id vem por parametro porque a carga do poll nao diz a quem o
     * historico pertence — quem sabe e quem montou a consulta.
     */
    applyPoll(resultado: PollResult, conversationId?: number): void {
      for (const conversa of resultado.conversations ?? []) {
        fetcher.guardarConversa(conversa);
      }

      if (undefined !== conversationId) {
        gravar(
          applyServerItems(estado, {
            conversationId,
            items: resultado.timeline ?? [],
            mode: "merge",
          }),
        );
      }
    },
  };
}

export type InboxStore = ReturnType<typeof criarInboxStore>;
