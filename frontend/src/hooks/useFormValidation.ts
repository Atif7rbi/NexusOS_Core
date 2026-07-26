"use client";

import {
  useCallback,
  useRef,
  useState,
} from "react";

import {
  isApiRequestError,
  type FieldErrors,
} from "@/lib/api-error";

function firstMessage(
  messages: string[] | undefined
): string | null {
  return messages?.[0] ?? null;
}

export function useFormValidation() {
  const formRef = useRef<HTMLFormElement>(null);
  const [fieldErrors, setFieldErrors] =
    useState<FieldErrors>({});
  const [formError, setFormError] =
    useState<string | null>(null);

  const focusFirstInvalidField = useCallback(
    (errors: FieldErrors): void => {
      requestAnimationFrame(() => {
        const form = formRef.current;
        const fieldName = Object.keys(errors).find(
          (name) => firstMessage(errors[name]) !== null
        );

        if (!form || !fieldName) {
          return;
        }

        const field = form.elements.namedItem(fieldName);
        const target =
          field instanceof HTMLElement ? field : null;

        if (!target) {
          return;
        }

        target.scrollIntoView({
          behavior: "smooth",
          block: "center",
        });
        target.focus({ preventScroll: true });
      });
    },
    []
  );

  const clearValidation = useCallback((): void => {
    setFieldErrors({});
    setFormError(null);
  }, []);

  const setValidationError = useCallback(
    (error: unknown, fallbackMessage: string): void => {
      if (
        isApiRequestError(error) &&
        Object.keys(error.fieldErrors).length > 0
      ) {
        const form = formRef.current;

        const hiddenMessages = Object.entries(
          error.fieldErrors
        )
          .filter(([name]) => {
            if (!form) {
              return true;
            }

            return (
              form.elements.namedItem(name) === null
            );
          })
          .flatMap(([, messages]) => messages);

        setFieldErrors(error.fieldErrors);

        setFormError(
          hiddenMessages.length > 0
            ? hiddenMessages.join(" ")
            : null
        );

        focusFirstInvalidField(error.fieldErrors);
        return;
      }

      setFieldErrors({});
      setFormError(
        error instanceof Error
          ? error.message
          : fallbackMessage
      );
    },
    [focusFirstInvalidField]
  );

  const setClientFieldErrors = useCallback(
    (errors: FieldErrors): void => {
      setFieldErrors(errors);
      setFormError(null);
      focusFirstInvalidField(errors);
    },
    [focusFirstInvalidField]
  );

  return {
    formRef,
    fieldErrors,
    formError,
    clearValidation,
    setValidationError,
    setClientFieldErrors,
  };
}
