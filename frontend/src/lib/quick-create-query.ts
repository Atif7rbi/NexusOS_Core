export function isQuickCreateRequested(
  search: string
): boolean {
  return new URLSearchParams(search).get("create") === "1";
}

export function removeQuickCreateQuery(
  pathname: string,
  search: string
): string {
  const params = new URLSearchParams(search);

  params.delete("create");

  const query = params.toString();

  return query === ""
    ? pathname
    : `${pathname}?${query}`;
}
