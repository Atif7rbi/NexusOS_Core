const dateFormatter = new Intl.DateTimeFormat(
  "en-GB-u-ca-gregory-nu-latn",
  {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }
);

const dateTimeFormatter = new Intl.DateTimeFormat(
  "en-GB-u-ca-gregory-nu-latn",
  {
    timeZone: "Asia/Riyadh",
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }
);

const isoDateOnlyPattern = /^(\d{4})-(\d{2})-(\d{2})$/;

export function formatDate(value: string | Date): string {
  if (typeof value === "string") {
    const dateOnlyParts = isoDateOnlyPattern.exec(value);

    if (dateOnlyParts) {
      const [, year, month, day] = dateOnlyParts;

      return `${day}/${month}/${year}`;
    }
  }

  return dateFormatter.format(
    typeof value === "string" ? new Date(value) : value
  );
}

export function formatDateTime(value: string | Date): string {
  return dateTimeFormatter.format(
    typeof value === "string" ? new Date(value) : value
  );
}
