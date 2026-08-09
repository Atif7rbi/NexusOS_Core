"use client";

import {
  Building2,
  FolderPlus,
  Plus,
  UserPlus,
} from "lucide-react";
import {
  useEffect,
  useRef,
  useState,
  type KeyboardEvent,
} from "react";

import { useTranslation } from "@/hooks/useTranslation";

export function QuickCreateMenu() {
  const { isArabic } = useTranslation();
  const [isOpen, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const itemRefs = useRef<Array<HTMLAnchorElement | null>>([]);

  const items = isArabic
    ? [
        { label: "مشروع جديد", href: "/projects/?create=1", icon: FolderPlus },
        { label: "عميل جديد", href: "/customers/?create=1", icon: UserPlus },
        { label: "إضافة وحدة", href: "/units/?create=1", icon: Building2 },
      ]
    : [
        { label: "New project", href: "/projects/?create=1", icon: FolderPlus },
        { label: "New customer", href: "/customers/?create=1", icon: UserPlus },
        { label: "Add unit", href: "/units/?create=1", icon: Building2 },
      ];

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    const handlePointerDown = (event: PointerEvent): void => {
      if (!containerRef.current?.contains(event.target as Node)) {
        setOpen(false);
      }
    };

    const handleEscape = (event: globalThis.KeyboardEvent): void => {
      if (event.key !== "Escape") {
        return;
      }

      setOpen(false);
      triggerRef.current?.focus();
    };

    document.addEventListener("pointerdown", handlePointerDown);
    document.addEventListener("keydown", handleEscape);

    return () => {
      document.removeEventListener("pointerdown", handlePointerDown);
      document.removeEventListener("keydown", handleEscape);
    };
  }, [isOpen]);

  const openAndFocusFirstItem = (): void => {
    setOpen(true);
    window.requestAnimationFrame(() => itemRefs.current[0]?.focus());
  };

  const handleTriggerKeyDown = (
    event: KeyboardEvent<HTMLButtonElement>
  ): void => {
    if (event.key !== "ArrowDown") {
      return;
    }

    event.preventDefault();
    openAndFocusFirstItem();
  };

  const handleMenuKeyDown = (
    event: KeyboardEvent<HTMLDivElement>
  ): void => {
    const currentIndex = itemRefs.current.findIndex(
      (item) => item === document.activeElement
    );

    let targetIndex: number | null = null;

    if (event.key === "ArrowDown") {
      targetIndex =
        currentIndex < 0
          ? 0
          : (currentIndex + 1) % items.length;
    } else if (event.key === "ArrowUp") {
      targetIndex =
        currentIndex < 0
          ? items.length - 1
          : (currentIndex - 1 + items.length) % items.length;
    } else if (event.key === "Home") {
      targetIndex = 0;
    } else if (event.key === "End") {
      targetIndex = items.length - 1;
    }

    if (targetIndex === null) {
      return;
    }

    event.preventDefault();
    itemRefs.current[targetIndex]?.focus();
  };

  return (
    <div ref={containerRef} className="relative">
      <button
        ref={triggerRef}
        type="button"
        aria-label={isArabic ? "إنشاء سريع" : "Quick create"}
        aria-haspopup="menu"
        aria-expanded={isOpen}
        aria-controls="topbar-quick-create-menu"
        onClick={() => {
          if (isOpen) {
            setOpen(false);
          } else {
            openAndFocusFirstItem();
          }
        }}
        onKeyDown={handleTriggerKeyDown}
        className="motion-ui inline-flex h-10 w-10 items-center justify-center rounded-[var(--radius-md)] border border-transparent bg-[var(--action-primary)] text-[var(--action-primary-foreground)] shadow-[var(--shadow-sm)] hover:-translate-y-0.5 hover:bg-[var(--action-primary-hover)] hover:shadow-[var(--shadow-md)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
      >
        <Plus size={20} aria-hidden="true" />
      </button>

      {isOpen ? (
        <div
          id="topbar-quick-create-menu"
          role="menu"
          onKeyDown={handleMenuKeyDown}
          className="absolute end-0 top-full z-[70] mt-2 w-52 max-w-[calc(100vw-2rem)] rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--surface)] p-2 shadow-[var(--shadow-lg)]"
        >
          {items.map((item, index) => {
            const Icon = item.icon;

            return (
              <a
                key={item.href}
                ref={(element) => {
                  itemRefs.current[index] = element;
                }}
                href={item.href}
                role="menuitem"
                onClick={() => setOpen(false)}
                className="motion-ui flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold text-[var(--text-primary)] hover:bg-[var(--action-accent-soft)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring-accent)]"
              >
                <Icon
                  size={17}
                  className="text-[var(--brand-gold-strong)]"
                  aria-hidden="true"
                />
                <span>{item.label}</span>
              </a>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}
