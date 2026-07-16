export function withAdminScope(href: string, query: Record<string, number> | undefined): string {
  if (!query || Object.keys(query).length === 0 || !href.startsWith('/')) return href;

  const url = new URL(href, 'https://zephyrus.local');
  for (const [key, value] of Object.entries(query)) {
    url.searchParams.set(key, String(value));
  }

  return `${url.pathname}${url.search}${url.hash}`;
}
