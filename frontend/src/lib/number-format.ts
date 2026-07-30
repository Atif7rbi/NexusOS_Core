export type NumericValue = number | string;

const integerFormatter = new Intl.NumberFormat("en-US-u-nu-latn", {
  useGrouping: true,
  maximumFractionDigits: 0,
});

const decimalFormatter = new Intl.NumberFormat("en-US-u-nu-latn", {
  useGrouping: true,
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});

function toFiniteNumber(value: NumericValue): number | null {
  const number = typeof value === "number" ? value : Number(value);

  return Number.isFinite(number) ? number : null;
}

export function formatInteger(value: NumericValue): string {
  const number = toFiniteNumber(value);

  return number === null ? String(value) : integerFormatter.format(number);
}

export function formatDecimal(value: NumericValue): string {
  const number = toFiniteNumber(value);

  return number === null ? String(value) : decimalFormatter.format(number);
}

export const formatMoney = formatDecimal;
