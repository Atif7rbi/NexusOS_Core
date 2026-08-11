type NexusBrandProps = {
  compact?: boolean;
  inverse?: boolean;
  large?: boolean;
  centered?: boolean;
};

export function NexusBrand({
  compact = false,
  inverse = false,
  large = false,
  centered = false,
}: NexusBrandProps) {
  const iconSize = large ? 88 : compact ? 42 : 48;

  return (
    <div
      className={[
        "flex items-center",
        centered ? "flex-col text-center" : "gap-3",
      ].join(" ")}
    >
      <svg
        width={iconSize}
        height={iconSize}
        viewBox="0 0 64 64"
        fill="none"
        aria-label="NexusOS"
        className="shrink-0 drop-shadow-[0_8px_18px_rgba(91,67,255,0.24)]"
      >
        <defs>
          <linearGradient
            id="nexus-gradient-a"
            x1="8"
            y1="5"
            x2="48"
            y2="58"
            gradientUnits="userSpaceOnUse"
          >
            <stop stopColor="#A855F7" />
            <stop offset="0.48" stopColor="#6D28D9" />
            <stop offset="1" stopColor="#2563EB" />
          </linearGradient>

          <linearGradient
            id="nexus-gradient-b"
            x1="27"
            y1="17"
            x2="59"
            y2="55"
            gradientUnits="userSpaceOnUse"
          >
            <stop stopColor="#7C3AED" />
            <stop offset="1" stopColor="#0EA5E9" />
          </linearGradient>
        </defs>

        <path
          d="M8 17.5L19.5 10L39 24V39L27.5 31V54L8 40V17.5Z"
          fill="url(#nexus-gradient-a)"
        />

        <path
          d="M56 10V46.5L44.5 54L25 40V25L36.5 33V10L56 24V10Z"
          fill="url(#nexus-gradient-b)"
        />

        <path
          d="M27.5 31L36.5 37.4V33L25 25V40L44.5 54V48.5L27.5 36.5V31Z"
          fill="#1D4ED8"
          fillOpacity="0.82"
        />
      </svg>

      {!compact ? (
        <div className={centered ? "mt-4" : ""}>
          <p
            className={[
              "nexus-brand-wordmark font-extrabold tracking-[-0.035em]",
              large ? "nexus-brand-wordmark--large" : "",
              inverse
                ? "text-white"
                : "text-[var(--text-primary)]",
            ].join(" ")}
            dir="ltr"
          >
            Nexus
            <span className="bg-gradient-to-r from-violet-600 to-blue-500 bg-clip-text text-transparent">
              OS
            </span>
          </p>

          <p
            className={[
              "nexus-brand-subtitle mt-0.5 font-medium tracking-wide",
              large ? "nexus-brand-subtitle--large" : "",
              inverse
                ? "text-white/55"
                : "text-[var(--text-muted)]",
            ].join(" ")}
            dir="ltr"
          >
            Business Operating System
          </p>
        </div>
      ) : null}
    </div>
  );
}
