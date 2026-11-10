"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { getRecognitionCtor, SpeechController, type SpeechWindow } from "@/lib/speech";

/**
 * Dictée vocale d'un champ de saisie : `toggle()` démarre ou arrête l'écoute ; le texte dicté apparaît en direct
 * dans le champ (via `onText`). `supported` reste faux côté serveur et sur les navigateurs sans reconnaissance vocale.
 */
export function useSpeechInput(value: string, onText: (text: string) => void, lang = "fr-FR") {
  const [supported, setSupported] = useState(false);
  const [listening, setListening] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const controller = useRef<SpeechController | null>(null);
  const valueRef = useRef(value);
  const onTextRef = useRef(onText);
  valueRef.current = value;
  onTextRef.current = onText;

  useEffect(() => {
    const ctor = getRecognitionCtor(window as unknown as SpeechWindow);
    if (!ctor) return;
    setSupported(true);
    const instance = new SpeechController(ctor, lang, {
      onText: (text) => onTextRef.current(text),
      onListening: setListening,
      onError: setError,
    });
    controller.current = instance;
    return () => {
      instance.abort();
      controller.current = null;
    };
  }, [lang]);

  const toggle = useCallback(() => {
    const instance = controller.current;
    if (!instance) return;
    setError(null);
    if (instance.listening) instance.stop();
    else instance.start(valueRef.current);
  }, []);

  const stop = useCallback(() => controller.current?.abort(), []);

  return { supported, listening, error, toggle, stop };
}
