import {
  commercialModuleKeys,
  type CommercialModuleKey,
} from "@/types/entitlement";

const commercialModuleSet = new Set<string>(
  commercialModuleKeys
);

const modulePathPrefixes: Array<{
  prefix: string;
  module: CommercialModuleKey;
}> = [
  { prefix: "/projects", module: "projects" },
  { prefix: "/units", module: "units" },
  { prefix: "/customers", module: "customers" },
  { prefix: "/crm", module: "crm" },
  { prefix: "/reservations", module: "reservations" },
  { prefix: "/contracts", module: "contracts" },
  { prefix: "/collections", module: "collections" },
];

export function normalizeEffectiveModules(
  value: unknown
): CommercialModuleKey[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return Array.from(
    new Set(
      value.filter(
        (item): item is CommercialModuleKey =>
          typeof item === "string" &&
          commercialModuleSet.has(item)
      )
    )
  );
}

export function hasModuleEntitlement(
  effectiveModules: readonly CommercialModuleKey[],
  module: CommercialModuleKey
): boolean {
  return effectiveModules.includes(module);
}

export function getCommercialModuleForPath(
  pathname: string
): CommercialModuleKey | null {
  return (
    modulePathPrefixes.find(
      ({ prefix }) =>
        pathname === prefix ||
        pathname.startsWith(`${prefix}/`)
    )?.module ?? null
  );
}
