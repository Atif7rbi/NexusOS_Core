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
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }
);

export function formatDate(value: string | Date): string {
  return dateFormatter.format(
    typeof value === "string" ? new Date(value) : value
  );
}

export function formatDateTime(value: string | Date): string {
  return dateTimeFormatter.format(
    typeof value === "string" ? new Date(value) : value
  );
}
