export const commercialModuleKeys = [
  "projects",
  "units",
  "customers",
  "crm",
  "reservations",
  "contracts",
  "collections",
] as const;

export type CommercialModuleKey =
  (typeof commercialModuleKeys)[number];
