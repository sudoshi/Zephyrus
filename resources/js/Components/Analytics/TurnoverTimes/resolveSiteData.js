// Resolve the site payload for the current filters. The live TurnoverTimesService
// payload is keyed by real site names, so a hard-coded legacy key ('MARH OR')
// can miss and crash downstream views — always fall back to the first available
// site, and return null (never undefined member access) when nothing matches.
export function resolveSiteData(sites, { selectedLocation, selectedHospital } = {}) {
  if (!sites || typeof sites !== 'object') {
    return null;
  }

  if (selectedLocation && sites[selectedLocation]) {
    return sites[selectedLocation];
  }

  if (selectedHospital) {
    const hospitalKey = Object.keys(sites).find((site) => site.startsWith(selectedHospital));
    if (hospitalKey) {
      return sites[hospitalKey];
    }
  }

  const firstKey = Object.keys(sites)[0];
  return firstKey ? sites[firstKey] : null;
}
