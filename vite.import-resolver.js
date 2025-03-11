// Vite plugin to ensure consistent import resolution across dev and prod
export default function importResolver() {
  return {
    name: 'import-resolver',
    enforce: 'pre',
    resolveId(source, importer) {
      // Only handle @/hooks/* imports
      if (!source.startsWith('@/hooks/')) {
        return null;
      }

      // If the import already has an extension, don't modify it
      if (source.endsWith('.js') || source.endsWith('.jsx')) {
        return null;
      }

      // Try resolving with .js extension first
      const withJs = `${source}.js`;
      try {
        return this.resolve(withJs, importer, { skipSelf: true })
          .then(resolved => resolved?.id || null);
      } catch (e) {
        // If .js fails, try .jsx
        const withJsx = `${source}.jsx`;
        return this.resolve(withJsx, importer, { skipSelf: true })
          .then(resolved => resolved?.id || null);
      }
    }
  };
}
