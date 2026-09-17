/**
 * Puxar para atualizar.
 *
 * Dentro de um app instalado nao ha barra de endereco nem recarregar: se o atendente ficar com
 * uma versao velha do pacote, ele nao tem saida a nao ser desinstalar. Este gesto e a saida.
 *
 * Nao e registrado no navegador comum de proposito — ali o Chrome do Android ja tem o gesto
 * nativo e o Safari tem o botao, e sobrepor o nosso ao deles daria dois puxoes concorrendo.
 */

export interface AlvoRolavel {
  scrollTop: number;
}

export interface Gesto {
  /** Distancia que o indicador deve descer. Zero quer dizer escondido. */
  aoMover: (distancia: number) => void;
  /** Chamado uma vez, quando o dedo solta alem do limite. */
  aoSoltar: () => void;
  /** Quanto o dedo precisa andar para valer. */
  limite?: number;
  /** Onde procurar a area rolavel a partir do que foi tocado. */
  seletor?: string;
}

interface ToqueSimples {
  clientY: number;
}
interface EventoDeToque {
  target: unknown;
  touches: ToqueSimples[];
  cancelable?: boolean;
  preventDefault?: () => void;
}

const PADRAO = ".inbox-list-scroll, .inbox-messages-wrap";

/**
 * O acoplamento com o DOM fica nos tres manipuladores devolvidos, e nao dentro da regra: assim o
 * teste exercita a decisao — quando conta, quando nao conta, quando desiste no meio — sem
 * precisar de um navegador para isso.
 */
export function criarGesto(gesto: Gesto): {
  tocar: (evento: EventoDeToque) => void;
  mover: (evento: EventoDeToque) => void;
  soltar: () => void;
} {
  const limite = gesto.limite ?? 72;
  let area: AlvoRolavel | null = null;
  let inicio = -1;
  let distancia = 0;

  const desistir = (): void => {
    area = null;
    inicio = -1;
    if (0 !== distancia) {
      distancia = 0;
      gesto.aoMover(0);
    }
  };

  return {
    tocar(evento) {
      const alvo = evento.target as {
        closest?: (s: string) => AlvoRolavel | null;
      } | null;
      area = alvo?.closest?.(gesto.seletor ?? PADRAO) ?? null;
      distancia = 0;
      // So conta se a area ja estiver no topo. No meio da conversa o puxao e rolagem, e roubar
      // aquele gesto faria a pessoa recarregar a pagina tentando ler o que veio antes.
      inicio =
        null !== area && area.scrollTop <= 0
          ? (evento.touches[0]?.clientY ?? -1)
          : -1;
    },

    mover(evento) {
      if (inicio < 0 || null === area) return;

      // Rolou durante o gesto: deixa de ser puxao.
      if (area.scrollTop > 0) {
        desistir();
        return;
      }

      const andou = (evento.touches[0]?.clientY ?? inicio) - inicio;
      if (andou <= 0) {
        if (0 !== distancia) {
          distancia = 0;
          gesto.aoMover(0);
        }
        return;
      }

      // Metade do caminho: o indicador acompanha o dedo com resistencia, como em todo lugar.
      distancia = Math.min(andou / 2, limite * 1.4);
      gesto.aoMover(distancia);
      if (false !== evento.cancelable) evento.preventDefault?.();
    },

    soltar() {
      const puxou = distancia > 0;
      const valeu = distancia >= limite;
      area = null;
      inicio = -1;
      distancia = 0;

      if (valeu) {
        gesto.aoSoltar();
        return;
      }
      // So recolhe o que chegou a aparecer. Avisar em todo toque faria o componente redesenhar a
      // cada rolagem da lista, que e o caminho mais quente da tela.
      if (puxou) gesto.aoMover(0);
    },
  };
}

/** Liga o gesto a um elemento de verdade. Devolve como desligar. */
export function puxarParaAtualizar(
  raiz: HTMLElement,
  gesto: Gesto,
): () => void {
  const g = criarGesto(gesto);
  const tocar = (e: Event): void => g.tocar(e as unknown as EventoDeToque);
  const mover = (e: Event): void => g.mover(e as unknown as EventoDeToque);
  const soltar = (): void => g.soltar();

  raiz.addEventListener("touchstart", tocar, { passive: true });
  // Nao passivo: sem isto o preventDefault e ignorado e o iOS estica a pagina inteira junto.
  raiz.addEventListener("touchmove", mover, { passive: false });
  raiz.addEventListener("touchend", soltar, { passive: true });
  raiz.addEventListener("touchcancel", soltar, { passive: true });

  return () => {
    raiz.removeEventListener("touchstart", tocar);
    raiz.removeEventListener("touchmove", mover);
    raiz.removeEventListener("touchend", soltar);
    raiz.removeEventListener("touchcancel", soltar);
  };
}

/** Verdadeiro so quando a pagina roda como app instalado, e nao como aba do navegador. */
export function comoApp(): boolean {
  if ("undefined" === typeof window) return false;
  const iosStandalone = (
    window.navigator as Navigator & { standalone?: boolean }
  ).standalone;
  return (
    true === iosStandalone ||
    Boolean(window.matchMedia?.("(display-mode: standalone)").matches)
  );
}
