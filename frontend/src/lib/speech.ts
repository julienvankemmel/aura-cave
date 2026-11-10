/**
 * Saisie vocale via l'API Web Speech du navigateur (SpeechRecognition). La logique est isolée du DOM pour être
 * testable : le contrôleur reçoit le constructeur de reconnaissance, le hook React ne fait que le brancher.
 * Attention : sur Chrome et Edge, l'audio est traité par le service de reconnaissance du navigateur (hors de l'application).
 */

export interface RecognitionResultLike {
  readonly isFinal: boolean;
  readonly length: number;
  readonly [index: number]: { readonly transcript: string };
}

export interface RecognitionEventLike {
  readonly resultIndex: number;
  readonly results: ArrayLike<RecognitionResultLike>;
}

export interface RecognitionLike {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  onresult: ((event: RecognitionEventLike) => void) | null;
  onerror: ((event: { error: string }) => void) | null;
  onend: (() => void) | null;
  start(): void;
  stop(): void;
  abort(): void;
}

export type RecognitionCtor = new () => RecognitionLike;

export type SpeechWindow = { SpeechRecognition?: RecognitionCtor; webkitSpeechRecognition?: RecognitionCtor };

/** Constructeur de reconnaissance vocale du navigateur, ou null s'il n'est pas disponible (Firefox, par exemple). */
export function getRecognitionCtor(win: SpeechWindow | undefined): RecognitionCtor | null {
  return win?.SpeechRecognition ?? win?.webkitSpeechRecognition ?? null;
}

/** Colle le texte dicté au texte déjà saisi, avec une seule espace entre les deux. */
export function joinTranscript(base: string, spoken: string): string {
  const head = base.trimEnd();
  const tail = spoken.trim();
  if (tail === "") return base;
  return head === "" ? tail : `${head} ${tail}`;
}

/** Texte validé et texte provisoire d'un événement de reconnaissance (tous les résultats depuis le début de la session). */
export function transcriptFromEvent(event: RecognitionEventLike): { final: string; interim: string } {
  let final = "";
  let interim = "";
  for (let i = 0; i < event.results.length; i++) {
    const result = event.results[i];
    const text = result?.[0]?.transcript ?? "";
    if (result?.isFinal) final += `${text} `;
    else interim += text;
  }
  return { final: final.trim(), interim: interim.trim() };
}

/** Message lisible pour un code d'erreur de reconnaissance ; null pour les cas sans importance (arrêt volontaire, silence). */
export function speechErrorMessage(code: string): string | null {
  switch (code) {
    case "not-allowed":
    case "service-not-allowed":
      return "Accès au micro refusé : autorisez-le dans votre navigateur pour dicter.";
    case "audio-capture":
      return "Aucun micro détecté.";
    case "network":
      return "Reconnaissance vocale indisponible (réseau).";
    case "language-not-supported":
      return "Langue non prise en charge par la reconnaissance vocale.";
    case "no-speech":
    case "aborted":
      return null;
    default:
      return "La dictée vocale a rencontré un problème.";
  }
}

export interface SpeechHandlers {
  /** Texte complet du champ (texte de départ + dictée) à chaque mise à jour, y compris provisoire. */
  onText: (text: string) => void;
  onListening: (listening: boolean) => void;
  onError: (message: string) => void;
}

export class SpeechController {
  private recognition: RecognitionLike | null = null;

  constructor(
    private readonly ctor: RecognitionCtor,
    private readonly lang: string,
    private readonly handlers: SpeechHandlers,
  ) {}

  get listening(): boolean {
    return this.recognition !== null;
  }

  /** Démarre l'écoute ; la dictée s'ajoute à `base` (texte déjà saisi). */
  start(base: string): void {
    if (this.recognition) return;
    const recognition = new this.ctor();
    recognition.lang = this.lang;
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.onresult = (event) => {
      const { final, interim } = transcriptFromEvent(event);
      this.handlers.onText(joinTranscript(base, `${final} ${interim}`));
    };
    recognition.onerror = (event) => {
      const message = speechErrorMessage(event.error);
      if (message) this.handlers.onError(message);
    };
    recognition.onend = () => {
      if (this.recognition === recognition) this.recognition = null;
      this.handlers.onListening(false);
    };
    this.recognition = recognition;
    try {
      recognition.start();
      this.handlers.onListening(true);
    } catch {
      this.recognition = null;
      this.handlers.onError("La dictée vocale n'a pas pu démarrer.");
    }
  }

  /** Arrête l'écoute en conservant ce qui a déjà été transcrit. */
  stop(): void {
    this.recognition?.stop();
  }

  /** Coupe immédiatement (démontage, envoi du message). */
  abort(): void {
    const recognition = this.recognition;
    this.recognition = null;
    recognition?.abort();
    if (recognition) this.handlers.onListening(false);
  }
}
