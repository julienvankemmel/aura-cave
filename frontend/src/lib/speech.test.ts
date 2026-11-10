import { describe, expect, it } from "vitest";

import {
  getRecognitionCtor,
  joinTranscript,
  speechErrorMessage,
  SpeechController,
  transcriptFromEvent,
  type RecognitionEventLike,
  type RecognitionLike,
  type RecognitionResultLike,
} from "./speech";

const result = (transcript: string, isFinal: boolean): RecognitionResultLike => ({ isFinal, length: 1, 0: { transcript } });
const event = (...results: RecognitionResultLike[]): RecognitionEventLike => ({ resultIndex: 0, results });

class FakeRecognition implements RecognitionLike {
  static last: FakeRecognition | null = null;
  lang = "";
  continuous = false;
  interimResults = false;
  onresult: ((e: RecognitionEventLike) => void) | null = null;
  onerror: ((e: { error: string }) => void) | null = null;
  onend: (() => void) | null = null;
  started = 0;
  stopped = 0;
  aborted = 0;
  constructor() {
    FakeRecognition.last = this;
  }
  start() {
    this.started += 1;
  }
  stop() {
    this.stopped += 1;
    this.onend?.();
  }
  abort() {
    this.aborted += 1;
  }
}

function setup(base = "") {
  const texts: string[] = [];
  const states: boolean[] = [];
  const errors: string[] = [];
  const controller = new SpeechController(FakeRecognition, "fr-FR", {
    onText: (t) => texts.push(t),
    onListening: (l) => states.push(l),
    onError: (m) => errors.push(m),
  });
  controller.start(base);
  return { controller, texts, states, errors, recognition: FakeRecognition.last! };
}

describe("speech helpers", () => {
  it("finds the standard or prefixed constructor, or none", () => {
    expect(getRecognitionCtor({ SpeechRecognition: FakeRecognition })).toBe(FakeRecognition);
    expect(getRecognitionCtor({ webkitSpeechRecognition: FakeRecognition })).toBe(FakeRecognition);
    expect(getRecognitionCtor({})).toBeNull();
    expect(getRecognitionCtor(undefined)).toBeNull();
  });

  it("joins dictated text to what was already typed with a single space", () => {
    expect(joinTranscript("", "un rouge")).toBe("un rouge");
    expect(joinTranscript("Je cherche ", "un rouge")).toBe("Je cherche un rouge");
    expect(joinTranscript("Je cherche", "  un rouge  ")).toBe("Je cherche un rouge");
    expect(joinTranscript("déjà saisi", "")).toBe("déjà saisi");
  });

  it("separates validated text from interim text", () => {
    expect(transcriptFromEvent(event(result("un vin rouge", true), result("pour un agneau", false)))).toEqual({
      final: "un vin rouge",
      interim: "pour un agneau",
    });
    expect(transcriptFromEvent(event(result("bonjour", true), result("budget cinquante euros", true)))).toEqual({
      final: "bonjour budget cinquante euros",
      interim: "",
    });
    expect(transcriptFromEvent(event())).toEqual({ final: "", interim: "" });
  });

  it("maps errors to readable messages and ignores harmless ones", () => {
    expect(speechErrorMessage("not-allowed")).toMatch(/micro refusé/);
    expect(speechErrorMessage("audio-capture")).toMatch(/Aucun micro/);
    expect(speechErrorMessage("network")).toMatch(/réseau/);
    expect(speechErrorMessage("no-speech")).toBeNull();
    expect(speechErrorMessage("aborted")).toBeNull();
    expect(speechErrorMessage("whatever")).toMatch(/problème/);
  });
});

describe("SpeechController", () => {
  it("configures a French continuous recognition with interim results", () => {
    const { recognition, states, controller } = setup();

    expect(recognition.lang).toBe("fr-FR");
    expect(recognition.continuous).toBe(true);
    expect(recognition.interimResults).toBe(true);
    expect(recognition.started).toBe(1);
    expect(states).toEqual([true]);
    expect(controller.listening).toBe(true);
  });

  it("streams the transcript in real time after the text already typed", () => {
    const { recognition, texts } = setup("Je cherche");

    recognition.onresult?.(event(result("un rouge", false)));
    recognition.onresult?.(event(result("un rouge pour", false)));
    recognition.onresult?.(event(result("un rouge pour un agneau", true), result("budget 50", false)));

    expect(texts).toEqual(["Je cherche un rouge", "Je cherche un rouge pour", "Je cherche un rouge pour un agneau budget 50"]);
  });

  it("stops on demand, keeps what was transcribed and can restart", () => {
    const { controller, states } = setup("");

    controller.stop();
    expect(controller.listening).toBe(false);
    expect(states).toEqual([true, false]);

    controller.start("");
    expect(controller.listening).toBe(true);
    expect(FakeRecognition.last?.started).toBe(1);
  });

  it("aborts immediately without waiting for the end event", () => {
    const { controller, recognition, states } = setup("");

    controller.abort();

    expect(recognition.aborted).toBe(1);
    expect(controller.listening).toBe(false);
    expect(states).toEqual([true, false]);
  });

  it("reports microphone errors but not silence", () => {
    const { recognition, errors } = setup("");

    recognition.onerror?.({ error: "no-speech" });
    recognition.onerror?.({ error: "not-allowed" });

    expect(errors).toHaveLength(1);
    expect(errors[0]).toMatch(/micro refusé/);
  });

  it("does not start twice while listening and reports a failing start", () => {
    const { controller, recognition } = setup("");
    controller.start("");
    expect(recognition.started).toBe(1);

    class Broken extends FakeRecognition {
      override start(): void {
        throw new Error("already started");
      }
    }
    const errors: string[] = [];
    const broken = new SpeechController(Broken, "fr-FR", { onText: () => {}, onListening: () => {}, onError: (m) => errors.push(m) });
    broken.start("");
    expect(broken.listening).toBe(false);
    expect(errors).toEqual(["La dictée vocale n'a pas pu démarrer."]);
  });
});
