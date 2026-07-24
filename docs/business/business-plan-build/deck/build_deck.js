// ACUM-ZEP-FIN-DECK-002 — "The Reconciled Case" — investor ROI deck
const pptxgen = require("pptxgenjs");
const p = new pptxgen();
p.layout = "LAYOUT_WIDE"; // 13.333 x 7.5

// ---- palette (Fifteen Numbers series) ----
const INK = "1A2238";      // navy ink
const PAPER = "F4F2EC";    // warm paper
const DARK = "10151F";     // cover navy
const DARK2 = "161C29";    // card on dark
const GOLD = "B08A2E";     // label gold
const GOLD2 = "C9A227";    // bright gold
const RUST = "A8442A";     // warn
const GRAY = "6B6F7B";     // secondary
const LINE = "E2DFD6";     // hairline on paper
const CARD = "FFFFFF";     // card on paper
const TINT = "F7F5EF";     // zebra
const SERIF = "Source Serif 4", SANS = "Helvetica Neue", MONO = "IBM Plex Mono"; // Deck-001 stack (install Source Serif 4 + IBM Plex Mono; HelveticaNeue ships with macOS)
const W = 13.333, H = 7.5, MX = 0.62;

const FOOT = (s, left, right) => {
  s.addShape("line", { x: MX, y: 6.98, w: W - 2 * MX, h: 0, line: { color: LINE, width: 0.75 } });
  s.addText(left, { x: MX, y: 7.04, w: 8.4, h: 0.36, fontFace: MONO, fontSize: 8.5, color: GRAY, valign: "top", margin: 0 });
  s.addText(right, { x: 9.2, y: 7.04, w: W - MX - 9.2, h: 0.36, fontFace: MONO, fontSize: 8.5, color: GRAY, align: "right", valign: "top", margin: 0 });
};
const KICK = (s, t, color = GOLD) => s.addText(t.toUpperCase(), { x: MX, y: 0.40, w: 11, h: 0.3, fontFace: MONO, fontSize: 11, color, charSpacing: 4, bold: true, margin: 0 });
const TITLE = (s, t, opts = {}) => s.addText(t, { x: MX, y: 0.70, w: opts.w ?? 11.6, h: opts.h ?? 0.78, fontFace: SERIF, fontSize: opts.size ?? 30, color: INK, bold: true, margin: 0, valign: "top" });
const PAGE = (s, bg = PAPER) => { s.background = { color: bg }; };
const chip = (s, x, y, t, color) => {
  const w = 0.16 + t.length * 0.082;
  s.addShape("rect", { x, y, w, h: 0.24, fill: { color } });
  s.addText(t, { x, y, w, h: 0.24, fontFace: MONO, fontSize: 9, bold: true, color: "FFFFFF", align: "center", valign: "middle", margin: 0 });
  return w;
};
// formula bar (dark strip with mono formula left, gold result right)
const FORMULA = (s, y, label, formula, result, h = 0.52) => {
  s.addShape("rect", { x: MX, y, w: W - 2 * MX, h, fill: { color: DARK } });
  s.addText([
    { text: label + "  ", options: { fontFace: MONO, fontSize: 10.5, color: "8A93A6" } },
    { text: formula, options: { fontFace: MONO, fontSize: 11.5, color: "FFFFFF" } },
  ], { x: MX + 0.22, y, w: 7.6, h, valign: "middle", margin: 0 });
  s.addText(result, { x: 8.0, y, w: W - MX - 8.0 - 0.22, h, fontFace: MONO, fontSize: 11.5, bold: true, color: GOLD2, align: "right", valign: "middle", margin: 0 });
};

// =========================================================
// 1 · COVER (dark)
// =========================================================
let s = p.addSlide(); PAGE(s, DARK);
s.addImage({ path: "logo.png", x: MX, y: 0.52, w: 0.62, h: 0.62 });
s.addText([
  { text: "ACUMENUS, INC.", options: { fontFace: MONO, fontSize: 12, color: "E8E4DA", charSpacing: 4, bold: true, breakLine: true } },
  { text: "ZEPHYRUS PLATFORM", options: { fontFace: MONO, fontSize: 10.5, color: GOLD2, charSpacing: 4 } },
], { x: 1.42, y: 0.56, w: 5.4, h: 0.6, valign: "middle", margin: 0 });
s.addText([
  { text: "INVESTOR BRIEFING · RECONCILED EDITION", options: { fontFace: MONO, fontSize: 10.5, color: "8A93A6", charSpacing: 3, align: "right", breakLine: true } },
  { text: "JULY 2026", options: { fontFace: MONO, fontSize: 10.5, color: "8A93A6", charSpacing: 3, align: "right" } },
], { x: 7.6, y: 0.56, w: W - MX - 7.6, h: 0.6, valign: "middle", margin: 0 });
s.addText("ZEPHYRUS · HOSPITAL OPERATIONS PLATFORM", { x: MX, y: 1.78, w: 11, h: 0.3, fontFace: MONO, fontSize: 11.5, color: GOLD2, charSpacing: 5, bold: true, margin: 0 });
s.addText([
  { text: "One plan, one deck, one model — ", options: { color: "F2EFE7" } },
  { text: "and the ROI at every layer.", options: { color: GOLD2, italic: true } },
], { x: MX, y: 2.12, w: 11.8, h: 1.9, fontFace: SERIF, fontSize: 40, bold: true, valign: "top", margin: 0 });
const coverStats = [
  ["589%", "return on each acquisition dollar"],
  ["6–13×", "customer ROI per hospital, sourced"],
  ["$1.20", "lifetime capital per $1 of ARR"],
];
coverStats.forEach(([v, c], i) => {
  const x = MX + i * 3.55;
  s.addShape("line", { x, y: 4.28, w: 2.9, h: 0, line: { color: GOLD, width: 1 } });
  s.addText(v, { x, y: 4.42, w: 2.9, h: 0.55, fontFace: SERIF, fontSize: 27, bold: true, color: "F2EFE7", margin: 0 });
  s.addText(c, { x, y: 4.97, w: 3.1, h: 0.55, fontFace: SANS, fontSize: 11.5, color: "9AA1B0", margin: 0 });
});
s.addText("Reconciles ACUM-ZEP-FIN-DECK-001 (The Fifteen Numbers, Jul 11) with Business Plan v2.0 + Financial Model (ACUM-BIZ-ZEPH-002, Jul 17). Every divergence shown, every choice explained — nothing quietly changed.",
  { x: MX, y: 5.72, w: 8.6, h: 0.85, fontFace: SANS, fontSize: 12.5, color: "B9BFCB", valign: "top", margin: 0 });
s.addText([
  { text: "Sanjay M. Udoshi, MD", options: { fontFace: SERIF, fontSize: 14, italic: true, color: "F2EFE7", breakLine: true } },
  { text: "Founder, Acumenus, Inc.", options: { fontFace: SANS, fontSize: 10.5, color: "8A93A6" } },
], { x: MX, y: 6.62, w: 4.5, h: 0.7, margin: 0 });
s.addText("ACUM-ZEP-FIN-DECK-002 · CONFIDENTIAL", { x: 8.0, y: 6.86, w: W - MX - 8.0, h: 0.3, fontFace: MONO, fontSize: 10, color: GOLD2, charSpacing: 3, align: "right", margin: 0 });

// =========================================================
// 2 · RECONCILIATION TABLE
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "01 · The Reconciliation");
TITLE(s, "Three documents. One set of numbers.");
const th = { fontFace: MONO, fontSize: 9.5, bold: true, color: "FFFFFF", fill: { color: DARK }, valign: "middle", align: "left" };
const td = (o = {}) => ({ fontFace: SANS, fontSize: 9.6, color: INK, valign: "middle", ...o });
const rows = [
  [{ text: "METRIC", options: th }, { text: "DECK-001 · JUL 11", options: th }, { text: "PLAN v2.0 · JUL 17", options: th }, { text: "THIS DECK — RESOLUTION", options: { ...th, color: GOLD2 } }],
  ["First institutional raise", "$7M validation · Jan-27", "$15M Series A · H2-26", "$7M Jan-27 — matches product reality (candor slide)"],
  ["Total staged capital", "$23M ($1M seed + $7M + $16M)", "$40M ($15M A + $25M B)", "$23M base · $40M labeled accelerated option"],
  ["Year-5 ARR (base)", "$20.1M (2031) · 36 logos · 55 sites", "$48.9M (2030) · 159 hospitals", "$20.1M base · $35.6M upside · $48.9M accelerated"],
  ["Blended ACV", "$300K → $365K per site (module stack)", "$203K → $289K net of launch discounts", "Same list stack — v2.0 nets off 25→5% early discounts"],
  ["SAM (US 200+ beds)", "$725M · 1,404 hospitals (CMS certified)", "$821M · 1,462 hospitals (AHA staffed)", "$725–821M — two independent counts, 12% apart"],
  ["LTV : CAC (year 5)", "6.9× (5-yr capped CLV)", "6.0–8.6× (7-yr life)", "6.9× — keep the stricter 5-yr cap"],
  ["NRR steady state", "110%", "106–107%", "106–110% band; expansion only after measured results"],
  ["EBITDA break-even", "2031 (+$0.06M)", "During 2029 (+20% margin 2030)", "2031 base; 2029–30 only under accelerated capital"],
  ["EV · base case", "~$160M (8× ARR) · 6.7× capital", "~$391M (8× ARR)", "$160M base · $390M accelerated — same 8× lens"],
].map((r, i) => i === 0 ? r : r.map((c, j) => ({ text: c, options: td({ fill: { color: i % 2 ? CARD : TINT }, bold: j === 0, fontSize: j === 0 ? 9.6 : 9.3, color: j === 3 ? "5A4A1E" : INK }) })));
s.addTable(rows, {
  x: MX, y: 1.66, w: W - 2 * MX, colW: [2.35, 3.10, 3.05, 3.59],
  border: { type: "solid", color: LINE, pt: 0.5 }, rowH: 0.42, margin: 0.06,
});
FOOT(s, "Deck-001 contributed capital discipline and gating · Plan v2.0 contributed sourced market evidence and customer ROI", "Sources: ACUM-ZEP-FIN-DECK-001 · ACUM-BIZ-ZEPH-002 + model");

// =========================================================
// 3 · WHAT EACH GOT RIGHT
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "02 · Method");
TITLE(s, "The deck's discipline, the plan's evidence");
const colCard = (x, w, head, headColor, items) => {
  s.addShape("rect", { x, y: 1.72, w, h: 4.42, fill: { color: CARD }, line: { color: LINE, width: 1 } });
  s.addText(head, { x: x + 0.28, y: 1.98, w: w - 0.56, h: 0.3, fontFace: MONO, fontSize: 11, bold: true, color: headColor, charSpacing: 2, margin: 0 });
  s.addText(items.map((t, i) => ({
    text: t, options: { bullet: { code: "2022", indent: 12 }, breakLine: i < items.length - 1, paraSpaceAfter: 8 },
  })), { x: x + 0.28, y: 2.42, w: w - 0.56, h: 3.6, fontFace: SANS, fontSize: 11.6, color: INK, valign: "top", margin: 0 });
};
colCard(MX, 5.95, "KEPT FROM DECK-001 (THE FIFTEEN NUMBERS)", GOLD, [
  "Staged, evidence-gated capital: $7M validation now, $16M scale at M19 — gates, not calendar",
  "2027 revenue start — consistent with the candor slide: security remediation and production EHR connectors come first",
  "ARR ≡ sites × ACV identity, enforced by a model check every year",
  "The two WARN flags, disclosed — OpEx capacity gap and the year-1 cash-buffer dip",
  "5-yr-capped CLV and strict unit-economics definitions",
]);
colCard(MX + 6.16, 5.95, "ADOPTED FROM PLAN v2.0 + FINANCIAL MODEL", "2E5A46", [
  "Sourced problem quantification: margins, OR $/minute, boarding minutes, RN-turnover economics",
  "Per-hospital customer ROI model — the number that actually closes hospital deals (6–13×)",
  "Independent AHA-based SAM cross-check ($821M vs $725M CMS) — the sizing now has two legs",
  "Benchmark context: KeyBanc payback medians, Benchmarkit NRR bands, healthy LTV:CAC bars",
  "An explicit accelerated case ($40M staged) so ambition is labeled, never smuggled into the base",
]);
s.addShape("rect", { x: MX, y: 6.32, w: W - 2 * MX, h: 0.5, fill: { color: DARK } });
s.addText([
  { text: "RULE  ", options: { fontFace: MONO, fontSize: 10, color: GOLD2, bold: true } },
  { text: "The base case is what the current product state and $23M of gated capital support. Everything faster is labeled accelerated and priced separately.", options: { fontFace: SANS, fontSize: 11, color: "E8E4DA" } },
], { x: MX + 0.25, y: 6.32, w: W - 2 * MX - 0.5, h: 0.5, valign: "middle", margin: 0 });
FOOT(s, "Nothing from either artifact was quietly changed — divergences resolve on the previous slide", "Method note");

// =========================================================
// 4 · THE PROBLEM, PRICED
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "03 · Why hospitals pay");
TITLE(s, "The operational losses are measured, not anecdotal");
const stat4 = [
  ["1–2%", "median hospital operating margin; ~40% of US hospitals lose money on operations", "Kaufman Hall 2025 · Advisory Board"],
  ["$36–46", "cost of one OR minute — while prime-time utilization runs 60–75% vs the 80–85% target", "JAMA Surgery 2018 · lit. review 2022"],
  ["190 min", "median ED boarding for admitted patients — up ~60% vs the 2013–19 baseline; ~5 hrs at large EDs", "ACEP / ENA compendium"],
  ["$5.19M", "average annual cost of RN turnover per hospital — 17.6% turnover at $60,090 per departure", "NSI Retention Report 2026"],
];
stat4.forEach(([v, c, src], i) => {
  const x = MX + (i % 2) * 6.16, y = 1.72 + Math.floor(i / 2) * 2.28;
  s.addShape("rect", { x, y, w: 5.95, h: 2.08, fill: { color: CARD }, line: { color: LINE, width: 1 } });
  s.addText(v, { x: x + 0.28, y: y + 0.18, w: 2.6, h: 0.72, fontFace: SERIF, fontSize: 34, bold: true, color: INK, margin: 0 });
  s.addText(c, { x: x + 0.28, y: y + 0.92, w: 5.4, h: 0.78, fontFace: SANS, fontSize: 11.3, color: "3A4152", valign: "top", margin: 0 });
  s.addText(src.toUpperCase(), { x: x + 0.28, y: y + 1.74, w: 5.4, h: 0.24, fontFace: MONO, fontSize: 8.5, color: GRAY, margin: 0 });
});
FOOT(s, "Every figure carried into the customer-ROI model on the next slide — same sources as Plan v2.0 Appendix A", "Source: Plan v2.0 §2 · model 'Sources' sheet");

// =========================================================
// 5 · CUSTOMER ROI (the engine)
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "04 · The ROI that closes deals");
TITLE(s, "Per hospital: 6–13× return on the subscription");
FORMULA(s, 1.62, "CUSTOMER ROI", "identified annual value ÷ platform subscription", "$2.1–4.0M ÷ $300–365K  =  6–13×");
const roiRows = [
  [{ text: "VALUE LEVER", options: th }, { text: "MECHANISM", options: th }, { text: "ANNUAL VALUE", options: { ...th, align: "right" } }, { text: "TYPE", options: th }],
  ["OR utilization +2–4 pts", "Fill released block time — ~$40/min × recovered prime-time minutes", "$1.0–2.0M", "Hard"],
  ["ED walkouts −0.5–1.0 pt", "≈600 retained visits × ~$600 average net revenue", "$0.2–0.4M", "Hard"],
  ["RN turnover −1–2 pts", "$289K per point of turnover (NSI 2026)", "$0.3–0.6M", "Hard"],
  ["Boarding / LOS −0.2–0.3 days", "Excess-day cost avoidance at ~$1,300/day variable cost", "$0.5–0.8M", "Soft"],
  ["Improvement-analyst leverage", "OCEL process mining replaces weeks of manual mapping", "$0.1–0.2M", "Soft"],
].map((r, i) => i === 0 ? r : r.map((c, j) => ({ text: c, options: td({ fill: { color: i % 2 ? CARD : TINT }, bold: j === 0 || j === 2, align: j === 2 ? "right" : "left", fontSize: 10.2, color: (j === 3 && c === "Soft") ? GRAY : INK }) })));
s.addTable(roiRows, { x: MX, y: 2.36, w: 8.3, colW: [2.35, 3.85, 1.30, 0.80], border: { type: "solid", color: LINE, pt: 0.5 }, rowH: 0.45, margin: 0.06 });
s.addShape("rect", { x: 9.16, y: 2.36, w: W - MX - 9.16, h: 2.72, fill: { color: DARK } });
s.addText("THE FLOOR", { x: 9.42, y: 2.58, w: 3.2, h: 0.28, fontFace: MONO, fontSize: 10, bold: true, color: GOLD2, charSpacing: 2, margin: 0 });
s.addText([
  { text: "Hard dollars only: ", options: { bold: true, color: "F2EFE7" } },
  { text: "$1.5–3.0M against a $365K fully-built site — a 4–8× floor before counting a single soft dollar.", options: { color: "C6CBD6" } },
], { x: 9.42, y: 2.92, w: 3.35, h: 1.3, fontFace: SANS, fontSize: 11.5, valign: "top", margin: 0 });
s.addText("Representative 400-bed hospital: 12 ORs · 60K ED visits · 20K admissions", { x: 9.42, y: 4.30, w: 3.05, h: 0.66, fontFace: SANS, fontSize: 9.3, italic: true, color: "8A93A6", valign: "top", margin: 0 });
s.addText([
  { text: "Why it matters to investors: ", options: { bold: true } },
  { text: "a 4–8× hard-dollar floor is what sustains 92–94% gross retention, 110% NRR, and pricing power — the retention math on slides 8–11 is downstream of this table.", options: {} },
], { x: MX, y: 5.42, w: 8.3, h: 0.9, fontFace: SANS, fontSize: 11.5, color: INK, valign: "top", margin: 0 });
FOOT(s, "Hard = revenue or cash-cost effect · Soft = capacity release, converts when backfilled — labeled, never blended", "Source: Plan v2.0 §2.6 · sourced levers, slide 03");

// =========================================================
// 6 · MARKET — TWO BOTTOM-UPS
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "05 · The market");
TITLE(s, "Two independent bottom-ups land within 12%");
const mkt = (x, head, big, sub, lines, hl) => {
  s.addShape("rect", { x, y: 1.72, w: 5.95, h: 3.55, fill: { color: hl ? DARK : CARD }, line: { color: hl ? DARK : LINE, width: 1 } });
  s.addText(head, { x: x + 0.28, y: 1.94, w: 5.4, h: 0.28, fontFace: MONO, fontSize: 10, bold: true, color: hl ? GOLD2 : GOLD, charSpacing: 2, margin: 0 });
  s.addText(big, { x: x + 0.28, y: 2.26, w: 5.4, h: 0.75, fontFace: SERIF, fontSize: 36, bold: true, color: hl ? "F2EFE7" : INK, margin: 0 });
  s.addText(sub, { x: x + 0.28, y: 3.02, w: 5.4, h: 0.35, fontFace: SANS, fontSize: 11.5, bold: true, color: hl ? "C6CBD6" : "3A4152", margin: 0 });
  s.addText(lines.map((t, i) => ({ text: t, options: { bullet: { code: "2022", indent: 10 }, breakLine: i < lines.length - 1, paraSpaceAfter: 6 } })),
    { x: x + 0.28, y: 3.44, w: 5.4, h: 1.7, fontFace: SANS, fontSize: 10.6, color: hl ? "AEB4C2" : "4A5162", valign: "top", margin: 0 });
};
mkt(MX, "LENS A · CMS CERTIFIED BEDS (DECK-001)", "$725M SAM", "1,404 hospitals with 200+ certified beds", [
  "TAM $1.10B across 4,232 sites — every acute, children's & critical-access hospital with emergency services",
  "Planning ACVs $90K–$750K by bed class",
  "Beachhead: 1,023 hospitals of 200–499 beds",
], false);
mkt(MX + 6.16, "LENS B · AHA STAFFED BEDS (PLAN v2.0)", "$821M SAM", "1,462 hospitals with 200+ staffed beds", [
  "TAM $986M across 2,564 hospitals of 100+ beds — displaceable point-solution spend at observed price points",
  "Calibrated to LeanTaaS $400K+/workflow, TeleTracking, EDIS, WFM $75–300K",
  "Beachhead: 877 hospitals of 300+ beds ($616M)",
], false);
s.addShape("rect", { x: MX, y: 5.48, w: W - 2 * MX, h: 0.92, fill: { color: DARK } });
s.addText([
  { text: "SAM $725–821M", options: { fontFace: SERIF, fontSize: 20, bold: true, color: GOLD2 } },
  { text: "   — different federal datasets, different ACV ladders, same answer. Base-case SOM stays humble: $20.1M = 2.8% of SAM dollars by 2031. A sizing, not a forecast.", options: { fontFace: SANS, fontSize: 12, color: "E8E4DA" } },
], { x: MX + 0.28, y: 5.48, w: W - 2 * MX - 0.56, h: 0.92, valign: "middle", margin: 0 });
FOOT(s, "Growing 13–15%/yr with the underlying categories — capacity mgmt, OR software, EDIS, WFM, process mining", "Sources: CMS Hospital General Info (May-26) · AHA Fast Facts 2026");

// =========================================================
// 7 · THE GATED PLAN
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "06 · The plan");
TITLE(s, "Five-year build — gated, with the throttle visible");
const stages = [
  ["2027", "Prove", "2 logos · 2 sites", "$0.6M ARR", false],
  ["2028", "Convert", "5 logos · 6 sites", "$1.95M ARR", false],
  ["2029", "Repeat", "11 logos · 14 sites", "$4.9M ARR", false],
  ["2030", "Scale", "21 logos · 29 sites", "$10.6M ARR", false],
  ["2031", "Earn", "36 logos · 55 sites", "$20.1M ARR", true],
];
stages.forEach(([yr, name, logos, arr, hl], i) => {
  const x = MX + i * 2.45, w = 2.28;
  s.addShape("rect", { x, y: 1.72, w, h: 3.1, fill: { color: hl ? DARK : CARD }, line: { color: hl ? DARK : LINE, width: 1 } });
  s.addText(yr, { x: x + 0.2, y: 1.92, w: w - 0.4, h: 0.26, fontFace: MONO, fontSize: 10.5, color: hl ? "8A93A6" : GRAY, margin: 0 });
  s.addText(name, { x: x + 0.2, y: 2.20, w: w - 0.4, h: 0.5, fontFace: SERIF, fontSize: 23, bold: true, color: hl ? GOLD2 : INK, margin: 0 });
  s.addText(logos, { x: x + 0.2, y: 3.86, w: w - 0.4, h: 0.26, fontFace: MONO, fontSize: 9.5, color: hl ? "C6CBD6" : "4A5162", margin: 0 });
  s.addText(arr, { x: x + 0.2, y: 4.14, w: w - 0.4, h: 0.34, fontFace: MONO, fontSize: 12.5, bold: true, color: hl ? GOLD2 : INK, margin: 0 });
});
s.addText([
  { text: "Every stage transition is an evidence gate — ", options: { bold: true } },
  { text: "references, connectors, install time, margin, security posture. Hiring, spending, and the scale raise wait for proof. YoY ARR growth: 225% → 151% → 116% → 90%.", options: {} },
], { x: MX, y: 5.10, w: 12.1, h: 0.62, fontFace: SANS, fontSize: 11.8, color: INK, valign: "top", margin: 0 });
s.addShape("rect", { x: MX, y: 5.84, w: W - 2 * MX, h: 0.72, fill: { color: TINT }, line: { color: LINE, width: 1 } });
s.addText([
  { text: "ACCELERATED OVERLAY  ", options: { fontFace: MONO, fontSize: 9.5, bold: true, color: "5A4A1E" } },
  { text: "If the 2028 gates land early and the scale round upsizes to $25M, Plan-v2.0 capacity math (AE-throttled: 14 → 30 → 50 → 68 new hospitals/yr) supports $35–49M ARR by 2031. Same gates — more fuel after they pass.", options: { fontFace: SANS, fontSize: 10.8, color: INK } },
], { x: MX + 0.24, y: 5.84, w: W - 2 * MX - 0.48, h: 0.72, valign: "middle", margin: 0 });
FOOT(s, "Base plan identical to Deck-001 — reverified against the fourteen-sheet model this week", "Source: Revenue Model rows 6–11 · Plan v2.0 §11");

// =========================================================
// 8 · FIFTEEN NUMBERS SCOREBOARD
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "07 · Scoreboard · year-5 values");
TITLE(s, "The Fifteen Numbers — reverified, unchanged");
const nums = [
  ["01 · CAC", "$220K", "per new logo — down 45% from $400K", null],
  ["02 · CLV", "$1.51M", "gross-profit LTV per site, 5-yr cap", null],
  ["03 · ARR", "$20.1M", "55 sites × $365K — identity enforced", null],
  ["04 · MRR", "$1.67M", "December-2031 run-rate", null],
  ["05 · ROI", "589%", "net return per acquisition dollar", null],
  ["06 · BURN", "0.14×", "bar < 1.0× — elite territory", "PASS"],
  ["07 · EBITDA", "+$0.06M", "break-even inside the window", "PASS"],
  ["08 · CHURN", "6.0%", "of beginning ARR — from 8% in yr 2", null],
  ["09 · LTV:CAC", "6.9×", "healthy bar 3× — crossed 2028", "PASS"],
  ["10 · NRR", "110%", "expansion outruns churn — bar 100%", "PASS"],
  ["11 · GM", "74.0%", "blended, from 55% in year 1", null],
  ["12 · OP MARGIN", "−1.7%", "D&A gap after EBITDA break-even", "WATCH"],
  ["13 · PAYBACK", "8.7 mo", "bar 12 months — crossed 2030", "PASS"],
  ["14 · RULE OF 40", "94", "growth + margin — clears 40 in 2030", "PASS"],
  ["15 · RUNWAY", "$4.4M", "EOY-31 cash, above $4.0M buffer", "WATCH"],
];
nums.forEach(([label, v, c, tag], i) => {
  const col = i % 5, row = Math.floor(i / 5);
  const x = MX + col * 2.45, y = 1.66 + row * 1.72, w = 2.28, h = 1.58;
  s.addShape("rect", { x, y, w, h, fill: { color: CARD }, line: { color: LINE, width: 1 } });
  s.addText(label, { x: x + 0.16, y: y + 0.12, w: w - 0.9, h: 0.24, fontFace: MONO, fontSize: 8.5, bold: true, color: GRAY, margin: 0 });
  if (tag) chip(s, x + w - (0.16 + tag.length * 0.082) - 0.12, y + 0.12, tag, tag === "PASS" ? GOLD : RUST);
  s.addText(v, { x: x + 0.16, y: y + 0.40, w: w - 0.32, h: 0.52, fontFace: SERIF, fontSize: 24, bold: true, color: INK, margin: 0 });
  s.addText(c, { x: x + 0.16, y: y + 0.95, w: w - 0.32, h: 0.56, fontFace: SANS, fontSize: 8.8, color: "4A5162", valign: "top", margin: 0 });
});
FOOT(s, "Base scenario · every value recomputed against the model this week — all fifteen tie", "Source: Deck-001 scoreboard · model Checks tab");

// =========================================================
// 9 · ROI PER ACQUISITION DOLLAR (chart)
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "08 · Unit economics · I");
TITLE(s, "Return on the acquisition dollar");
FORMULA(s, 1.60, "ROI", "(CLV − CAC) ÷ CAC", "($1.515M − $0.220M) ÷ $0.220M  =  589%");
s.addChart(p.ChartType.bar, [{
  name: "ROI per acquisition dollar", labels: ["2027", "2028", "2029", "2030", "2031"], values: [181, 262, 367, 499, 589],
}], {
  x: MX, y: 2.42, w: 7.9, h: 4.15, barDir: "col",
  chartColors: [INK], chartColorsOpacity: 100,
  showLegend: false, showTitle: false,
  showValue: true, dataLabelPosition: "outEnd", dataLabelColor: INK, dataLabelFontFace: SERIF, dataLabelFontSize: 13, dataLabelFormatCode: '0"%"',
  catAxisLabelColor: GRAY, catAxisLabelFontFace: MONO, catAxisLabelFontSize: 10, catGridLine: { style: "none" },
  valAxisHidden: true, valGridLine: { style: "none" }, valAxisMaxVal: 660,
  barGapWidthPct: 55,
});
s.addShape("rect", { x: 9.16, y: 2.42, w: W - MX - 9.16, h: 2.0, fill: { color: CARD }, line: { color: LINE, width: 1 } });
s.addText("READ IT AS CASH", { x: 9.4, y: 2.62, w: 3.3, h: 0.26, fontFace: MONO, fontSize: 9.5, bold: true, color: GOLD, charSpacing: 2, margin: 0 });
s.addText([
  { text: "$1.00 of CAC  →  $6.89 gross profit\n", options: { fontFace: MONO, fontSize: 11, color: INK } },
  { text: "net of the dollar  →  $5.89 kept", options: { fontFace: MONO, fontSize: 11, color: INK } },
], { x: 9.4, y: 2.95, w: 3.35, h: 0.85, valign: "top", margin: 0 });
s.addText("Numerator is 5-yr-capped gross profit, not revenue — the strict version of the metric.", { x: 9.4, y: 3.78, w: 3.3, h: 0.6, fontFace: SANS, fontSize: 9.5, italic: true, color: GRAY, valign: "top", margin: 0 });
s.addShape("rect", { x: 9.16, y: 4.62, w: W - MX - 9.16, h: 1.95, fill: { color: DARK } });
s.addText("CAPITAL-LEVEL VIEW", { x: 9.4, y: 4.82, w: 3.3, h: 0.26, fontFace: MONO, fontSize: 9.5, bold: true, color: GOLD2, charSpacing: 2, margin: 0 });
s.addText("$24M lifetime capital stands behind $20.1M of ARR at 110% NRR — $1.20 of capital per $1 of self-compounding recurring revenue.", { x: 9.4, y: 5.14, w: 3.35, h: 1.3, fontFace: SANS, fontSize: 10.8, color: "C6CBD6", valign: "top", margin: 0 });
FOOT(s, "Falling CAC ($400K → $220K) × rising CLV ($1.13M → $1.51M) is the whole story", "Source: Unit Economics rows 9–11");

// =========================================================
// 10 · LTV:CAC & PAYBACK vs BENCHMARKS (charts)
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "09 · Unit economics · II");
TITLE(s, "Both bars crossed inside the plan window");
const mkCombo = (x, title2, vals, bench, benchLabel, fmt, maxV) => {
  s.addText(title2, { x, y: 1.66, w: 5.9, h: 0.3, fontFace: MONO, fontSize: 10, bold: true, color: GRAY, charSpacing: 2, margin: 0 });
  s.addChart([
    { type: p.ChartType.bar, data: [{ name: "value", labels: ["2027", "2028", "2029", "2030", "2031"], values: vals }], options: { chartColors: [INK], barGapWidthPct: 55, showValue: true, dataLabelPosition: "outEnd", dataLabelColor: INK, dataLabelFontFace: SERIF, dataLabelFontSize: 12, dataLabelFormatCode: fmt } },
    { type: p.ChartType.line, data: [{ name: benchLabel, labels: ["2027", "2028", "2029", "2030", "2031"], values: [bench, bench, bench, bench, bench] }], options: { chartColors: [GOLD], lineSize: 2, lineDash: "dash", lineDataSymbol: "none", showValue: false } },
  ], {
    x, y: 2.02, w: 5.9, h: 4.3,
    showLegend: false, showTitle: false,
    catAxisLabelColor: GRAY, catAxisLabelFontFace: MONO, catAxisLabelFontSize: 10, catGridLine: { style: "none" },
    valAxisHidden: true, valGridLine: { style: "none" }, valAxisMaxVal: maxV,
  });
};
mkCombo(MX, "LTV : CAC — HIGHER IS BETTER · 3× HEALTHY BAR (GOLD)", [2.8, 3.6, 4.7, 6.0, 6.9], 3, "3x bar", '0.0"×"', 7.6);
mkCombo(MX + 6.2, "CAC PAYBACK, MONTHS — LOWER IS BETTER · 12-MO BAR (GOLD)", [21.3, 16.6, 12.9, 10.0, 8.7], 12, "12mo bar", '0.0', 23);
s.addText([
  { text: "Benchmark context: ", options: { bold: true } },
  { text: "B2B SaaS median payback is ~20 months (KeyBanc 2024); enterprise NRR medians run 110–118% (Benchmarkit 2025). Doubly conservative: per-site CLV against whole-logo CAC, while the average logo runs 1.5 sites by 2031.", options: {} },
], { x: MX, y: 6.42, w: 12.1, h: 0.5, fontFace: SANS, fontSize: 10.8, color: INK, valign: "top", margin: 0 });
FOOT(s, "LTV:CAC crosses the 3× bar in 2028 · payback crosses 12 months in 2030", "Source: Unit Economics rows 8–12 · Benchmarkit · KeyBanc");

// =========================================================
// 11 · RETENTION & CAPITAL EFFICIENCY
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "10 · Retention & efficiency");
TITLE(s, "The book compounds; the burn disappears");
s.addText("CHURN · NRR TRAJECTORY", { x: MX, y: 1.66, w: 5.9, h: 0.3, fontFace: MONO, fontSize: 10, bold: true, color: GRAY, charSpacing: 2, margin: 0 });
const nrrRows = [
  [{ text: "YEAR", options: th }, { text: "CHURN", options: { ...th, align: "right" } }, { text: "NRR", options: { ...th, align: "right" } }, { text: "GATE", options: th }],
  ["2027", "— assumed", "100%", "No expansion credit in year 1 — by design"],
  ["2028", "8.0%", "103%", "Expansion only after measured results"],
  ["2029", "7.0%", "105%", "Module 2–3 attach on referenced sites"],
  ["2030", "7.0%", "108%", "Multi-site logos begin (1.4 sites/logo)"],
  ["2031", "6.0%", "110%", "GRR 94% · expansion outruns churn"],
].map((r, i) => i === 0 ? r : r.map((c, j) => ({ text: c, options: td({ fill: { color: i % 2 ? CARD : TINT }, align: j === 1 || j === 2 ? "right" : "left", bold: j === 2, fontSize: 10.2 }) })));
s.addTable(nrrRows, { x: MX, y: 2.02, w: 5.9, colW: [0.95, 1.05, 0.95, 2.95], border: { type: "solid", color: LINE, pt: 0.5 }, rowH: 0.44, margin: 0.06 });
s.addText([
  { text: "FY2031 ARR bridge ($M):  ", options: { fontFace: MONO, fontSize: 9.5, color: GRAY } },
  { text: "10.59 − 0.64 churn + 1.69 expansion + 8.43 new = 20.08", options: { fontFace: MONO, fontSize: 9.5, bold: true, color: INK } },
], { x: MX, y: 4.85, w: 5.9, h: 0.3, margin: 0 });
s.addText("BURN MULTIPLE — DOLLARS BURNED PER NET-NEW ARR DOLLAR · 1.0× BAR (GOLD)", { x: MX + 6.2, y: 1.66, w: 5.9, h: 0.3, fontFace: MONO, fontSize: 10, bold: true, color: GRAY, charSpacing: 1, margin: 0 });
s.addChart([
  { type: p.ChartType.bar, data: [{ name: "burn multiple", labels: ["2027", "2028", "2029", "2030", "2031"], values: [6.96, 3.55, 1.75, 0.73, 0.14] }], options: { chartColors: [RUST], barGapWidthPct: 55, showValue: true, dataLabelPosition: "outEnd", dataLabelColor: INK, dataLabelFontFace: SERIF, dataLabelFontSize: 12, dataLabelFormatCode: '0.00"×"' } },
  { type: p.ChartType.line, data: [{ name: "1.0x", labels: ["2027", "2028", "2029", "2030", "2031"], values: [1, 1, 1, 1, 1] }], options: { chartColors: [GOLD], lineSize: 2, lineDash: "dash", lineDataSymbol: "none", showValue: false } },
], {
  x: MX + 6.2, y: 2.02, w: 5.9, h: 3.6,
  showLegend: false, showTitle: false,
  catAxisLabelColor: GRAY, catAxisLabelFontFace: MONO, catAxisLabelFontSize: 10, catGridLine: { style: "none" },
  valAxisHidden: true, valGridLine: { style: "none" }, valAxisMaxVal: 7.6,
});
s.addText("Under 1.0× is elite; the plan crosses in 2030 and finishes at 14 cents per new recurring dollar. Net burn includes working capital and capex — not an EBITDA proxy.", { x: MX + 6.2, y: 5.72, w: 5.9, h: 0.62, fontFace: SANS, fontSize: 10.3, color: "4A5162", valign: "top", margin: 0 });
s.addText([
  { text: "Rule of 40: ", options: { bold: true } },
  { text: "7 → 38 → 84 → 94 across 2028–2031 — small-base growth flatters it early; weight 2030–31, when the base is $9.4M and $18.3M.", options: {} },
], { x: MX, y: 5.30, w: 5.9, h: 1.0, fontFace: SANS, fontSize: 10.8, color: INK, valign: "top", margin: 0 });
FOOT(s, "GRR 92% → 94% · churn = 1 − GRR applied to beginning ARR", "Source: Revenue Model rows 11–14 · Cash Runway row 10");

// =========================================================
// 12 · CAPITAL STRATEGY
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "11 · Capital strategy");
TITLE(s, "Staged, gated — with a priced acceleration option");
const cap = (x, w, head, amt, body, hl) => {
  s.addShape("rect", { x, y: 1.72, w, h: 1.95, fill: { color: hl ? DARK : CARD }, line: { color: hl ? DARK : LINE, width: 1 } });
  s.addText(head, { x: x + 0.26, y: 1.94, w: w - 1.9, h: 0.3, fontFace: MONO, fontSize: 9.5, bold: true, color: hl ? GOLD2 : GOLD, charSpacing: 1, margin: 0 });
  s.addText(amt, { x: x + w - 1.85, y: 1.90, w: 1.6, h: 0.62, fontFace: SERIF, fontSize: 30, bold: true, color: hl ? "F2EFE7" : INK, align: "right", margin: 0 });
  s.addText(body, { x: x + 0.26, y: 2.36, w: w - 0.52, h: 1.2, fontFace: SANS, fontSize: 10.6, color: hl ? "C6CBD6" : "3A4152", valign: "top", margin: 0 });
};
cap(MX, 5.95, "VALIDATION ROUND · M1 (JAN-27)", "$7M", "18 months: security remediation first, production EHR connectors, paid design-partner pilots at contracted success metrics, founder-led GTM. Trough cover ≈ 3 months at M18 — named, not hidden.", true);
cap(MX + 6.16, 5.95, "SCALE ROUND · M19 (JUL-28)", "$16M", "Sales and implementation scale-up — raised when the five evidence gates below are met, not when the calendar says so. Total need through break-even ≈ $23.6M incl. $4M reserve.", true);
s.addText("FIVE EVIDENCE GATES FOR THE SCALE ROUND", { x: MX, y: 3.94, w: 8, h: 0.28, fontFace: MONO, fontSize: 10, bold: true, color: GOLD, charSpacing: 2, margin: 0 });
const gates = [["3+", "production reference customers"], ["$2–4M", "contracted ARR"], ["2", "repeatable EHR connectors"], ["<150d", "implementation per site"], [">75%", "subscription GM + security posture"]];
gates.forEach(([v, c], i) => {
  const x = MX + i * 2.45, w = 2.28;
  s.addShape("rect", { x, y: 4.28, w, h: 1.28, fill: { color: CARD }, line: { color: LINE, width: 1 } });
  s.addText(v, { x: x + 0.18, y: 4.42, w: w - 0.36, h: 0.44, fontFace: SERIF, fontSize: 21, bold: true, color: INK, margin: 0 });
  s.addText(c, { x: x + 0.18, y: 4.88, w: w - 0.36, h: 0.58, fontFace: SANS, fontSize: 9.6, color: "4A5162", valign: "top", margin: 0 });
});
s.addShape("rect", { x: MX, y: 5.80, w: W - 2 * MX, h: 0.82, fill: { color: TINT }, line: { color: LINE, width: 1 } });
s.addText([
  { text: "ACCELERATION OPTION  ", options: { fontFace: MONO, fontSize: 9.5, bold: true, color: "5A4A1E" } },
  { text: "Gates passed early → scale round upsizes toward $25M (Plan-v2.0 structure, $40M lifetime). Funds 4 AE pods instead of 2; base $20.1M becomes $35–49M by 2031; break-even pulls from 2031 toward 2029–30. Ambition is a priced option on evidence — never the base.", options: { fontFace: SANS, fontSize: 10.6, color: INK } },
], { x: MX + 0.24, y: 5.80, w: W - 2 * MX - 0.48, h: 0.82, valign: "middle", margin: 0 });
FOOT(s, "Seed in: $1M · staged institutional rounds: $23M · lifetime capital $24M", "Source: Assumptions rows 9–13 · Plan v2.0 §14");

// =========================================================
// 13 · SCENARIOS
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "12 · Stress test");
TITLE(s, "Year-5 outcomes — four labeled worlds");
const scen = [
  ["DOWNSIDE", "$7.8M", "18 logos · 24 sites", [["EBITDA", "−$2.74M"], ["Capital-need indicator", "+$6.7M"], ["Hiring throttle", "75% of plan"]], "Early retrenchment or added capital — the model says so on its own Scenarios tab.", RUST, false],
  ["BASE — THIS PLAN", "$20.1M", "36 logos · 55 sites", [["EBITDA", "+$0.06M"], ["Ending cash vs buffer", "$4.4M vs $4.0M"], ["Hiring", "100% of plan"]], "All fifteen scoreboard numbers come from this column.", GOLD2, true],
  ["UPSIDE", "$35.6M", "58 logos · 95 sites", [["EBITDA", "+$4.87M"], ["Capital-need indicator", "$0"], ["Hiring", "115% of plan"]], "Same two raises — upside flows to cash, it doesn't get spent.", GOLD, false],
  ["ACCELERATED", "$48.9M", "159 hospitals (Plan v2.0 ramp)", [["Capital", "$40M staged"], ["EBITDA 2031", "≈ +20% margin"], ["Requires", "gates early + $25M scale"]], "The v2.0 trajectory, priced as an option — not blended into the base.", "5A4A1E", false],
];
scen.forEach(([head, arr, sub, kv, note, hc, hl], i) => {
  const x = MX + i * 3.07, w = 2.92;
  s.addShape("rect", { x, y: 1.72, w, h: 4.55, fill: { color: hl ? DARK : CARD }, line: { color: hl ? DARK : LINE, width: 1 } });
  s.addText(head, { x: x + 0.22, y: 1.94, w: w - 0.44, h: 0.28, fontFace: MONO, fontSize: 9.5, bold: true, color: hc, charSpacing: 1, margin: 0 });
  s.addText(arr, { x: x + 0.22, y: 2.24, w: w - 0.44, h: 0.62, fontFace: SERIF, fontSize: 30, bold: true, color: hl ? "F2EFE7" : INK, margin: 0 });
  s.addText("ending ARR · " + sub, { x: x + 0.22, y: 2.88, w: w - 0.44, h: 0.42, fontFace: SANS, fontSize: 9.6, color: hl ? "8A93A6" : GRAY, valign: "top", margin: 0 });
  kv.forEach(([k, v], j) => {
    const yy = 3.38 + j * 0.45;
    s.addText(k, { x: x + 0.22, y: yy, w: 1.75, h: 0.42, fontFace: SANS, fontSize: 9.3, color: hl ? "AEB4C2" : "4A5162", valign: "middle", margin: 0 });
    s.addText(v, { x: x + 1.32, y: yy, w: w - 1.32 - 0.18, h: 0.42, fontFace: MONO, fontSize: 9.3, bold: true, color: hl ? "F2EFE7" : INK, align: "right", valign: "middle", margin: 0 });
  });
  s.addText(note, { x: x + 0.22, y: 5.02, w: w - 0.44, h: 1.1, fontFace: SANS, fontSize: 9.2, italic: true, color: hl ? "8A93A6" : GRAY, valign: "top", margin: 0 });
});
FOOT(s, "16 drivers move per scenario: logos, sites, ACV, retention, GM, OpEx, CAC, conversion, DSO, capex, hiring", "Source: Scenarios rows 6–16 · Plan v2.0 §11.4");

// =========================================================
// 14 · INVESTOR RETURNS
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "13 · Illustrative returns");
TITLE(s, "What the capital turns into");
const ev = [
  ["CONSERVATIVE", "~$100M", "base ARR $20.1M × 5×", "4.2×", false],
  ["BASE", "~$160M", "base ARR $20.1M × 8×", "6.7×", true],
  ["UPSIDE", "~$285M", "upside ARR $35.6M × 8×", "11.9×", false],
  ["ACCELERATED", "~$390M", "accel. ARR $48.9M × 8×", "9.5×", false],
];
ev.forEach(([head, v, sub, mult, hl], i) => {
  const x = MX + i * 3.07, w = 2.92;
  s.addShape("rect", { x, y: 1.72, w, h: 2.9, fill: { color: hl ? DARK : CARD }, line: { color: hl ? DARK : LINE, width: 1 } });
  s.addText(head, { x: x + 0.22, y: 1.94, w: w - 0.44, h: 0.28, fontFace: MONO, fontSize: 9.5, bold: true, color: hl ? GOLD2 : GOLD, charSpacing: 1, margin: 0 });
  s.addText(v, { x: x + 0.22, y: 2.26, w: w - 0.44, h: 0.66, fontFace: SERIF, fontSize: 31, bold: true, color: hl ? "F2EFE7" : INK, margin: 0 });
  s.addText("enterprise value · " + sub, { x: x + 0.22, y: 2.94, w: w - 0.44, h: 0.52, fontFace: SANS, fontSize: 9.6, color: hl ? "8A93A6" : GRAY, valign: "top", margin: 0 });
  s.addShape("line", { x: x + 0.22, y: 3.62, w: w - 0.44, h: 0, line: { color: hl ? "2A3242" : LINE, width: 0.75 } });
  s.addText([
    { text: "EV ÷ lifetime capital   ", options: { fontFace: SANS, fontSize: 10, color: hl ? "AEB4C2" : "4A5162" } },
    { text: mult, options: { fontFace: MONO, fontSize: 13, bold: true, color: hl ? GOLD2 : INK } },
  ], { x: x + 0.22, y: 3.74, w: w - 0.44, h: 0.6, valign: "middle", margin: 0 });
});
s.addShape("rect", { x: MX, y: 4.92, w: W - 2 * MX, h: 1.18, fill: { color: CARD }, line: { color: LINE, width: 1 } });
s.addText([
  { text: "The driver is capital efficiency, not the multiple. ", options: { bold: true } },
  { text: "$20.1M of ARR on $24M of lifetime capital — $1.20 per recurring dollar at 110% NRR — means ownership stays concentrated; conventional paths to the same ARR raise 2–3× more and dilute accordingly. The accelerated case spends $40M to reach $48.9M ($0.82 per recurring dollar) — better efficiency, taken only after the evidence gates pass.", options: {} },
], { x: MX + 0.26, y: 5.06, w: W - 2 * MX - 0.52, h: 0.95, fontFace: SANS, fontSize: 11.3, color: INK, valign: "top", margin: 0 });
s.addText("Illustrative only. Multiples (5–8× ARR, mid-range of healthcare-SaaS transactions) and timing are assumptions, not commitments — the plan sets no valuation. Actual returns depend on terms, dilution, performance, and exit conditions. Not an offer or a guarantee of returns.",
  { x: MX, y: 6.24, w: W - 2 * MX, h: 0.6, fontFace: MONO, fontSize: 8.8, color: GRAY, valign: "top", margin: 0 });
FOOT(s, "Category acquirers priced this space: Bain (LeanTaaS $1B+ EV) · KKR (Qventus) · Aionex (TeleTracking)", "Source: Deck-001 returns frame · Plan v2.0 §14.1");

// =========================================================
// 15 · TWO FLAGS
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "14 · Disclosed, not suppressed");
TITLE(s, "The two flags the model still raises");
const flag = (x, head, v, sub, body, action) => {
  s.addShape("rect", { x, y: 1.72, w: 5.95, h: 4.5, fill: { color: CARD }, line: { color: LINE, width: 1 } });
  s.addText(head, { x: x + 0.26, y: 1.94, w: 4.4, h: 0.28, fontFace: MONO, fontSize: 9.5, bold: true, color: RUST, charSpacing: 1, margin: 0 });
  chip(s, x + 5.95 - 0.75, y = 1.94, "WARN", RUST);
  s.addText(v, { x: x + 0.26, y: 2.26, w: 5.4, h: 0.62, fontFace: SERIF, fontSize: 30, bold: true, color: INK, margin: 0 });
  s.addText(sub, { x: x + 0.26, y: 2.90, w: 5.4, h: 0.3, fontFace: SANS, fontSize: 10, color: GRAY, margin: 0 });
  s.addText(body, { x: x + 0.26, y: 3.28, w: 5.42, h: 1.35, fontFace: SANS, fontSize: 10.8, color: "3A4152", valign: "top", margin: 0 });
  s.addText("MANAGEMENT ACTION", { x: x + 0.26, y: 4.70, w: 5.4, h: 0.24, fontFace: MONO, fontSize: 8.5, bold: true, color: GRAY, charSpacing: 2, margin: 0 });
  s.addText(action, { x: x + 0.26, y: 4.98, w: 5.42, h: 1.1, fontFace: SANS, fontSize: 10.3, color: INK, valign: "top", margin: 0 });
};
flag(MX, "FLAG 1 · OPEX CAPACITY", "−$1.62M", "2031 gap — bottom-up OpEx vs business-plan cap",
  "Role-level HR and technology schedules cost more than the plan-calibrated OpEx targets from 2028 onward: −$0.17M → −$0.64M → −$1.04M → −$1.62M.",
  "Slower hiring, lower cash comp, or higher ARR per FTE — the $309K ARR/employee assumption is the lever, reviewed before every hiring and fundraising commitment.");
flag(MX + 6.16, "FLAG 2 · YEAR-1 BUFFER DIP", "−$0.18M", "EOY-2027 cash $3.82M vs $4.0M board minimum",
  "Ending 2027 cash sits just below the board's minimum buffer, and the pre-raise trough reaches $1.29M in month 18 — about three months of cover.",
  "Sequencing, not hope: the validation round is sized for 18 months, the scale round is evidence-gated at M19, and the buffer is restored in every year thereafter.");
s.addText("11 / 11 structural checks PASS — revenue bridges, cash roll-forward, allocation totals. These two flags are also why the base case, not the accelerated case, is the plan.", { x: MX, y: 6.38, w: 12.1, h: 0.5, fontFace: SANS, fontSize: 10.8, color: INK, valign: "top", margin: 0 });
FOOT(s, "Unchanged from Deck-001 — still true, still disclosed", "Source: Checks rows 8–25 · Operating Expenses rows 16–20");

// =========================================================
// 16 · WHAT WE ARE NOT CLAIMING
// =========================================================
s = p.addSlide(); PAGE(s);
KICK(s, "15 · Candor");
TITLE(s, "What we are not claiming");
const nots = [
  ["No $8.5B TAM.", "The v1.0 figure blended analyst categories Zephyrus doesn't sell into. The bottom-up SAM is $725–821M — big enough, and real."],
  ["No 43:1 LTV:CAC.", "The honest ratio is 6.9× on a 5-yr cap with fully-loaded ~$220K CAC — still exceptional, and it survives diligence."],
  ["Not production-ready today.", "Security remediation, live EHR connectors, and HA/DR are the first 18 months of the validation round — the gap is named, concentrated, and funded."],
  ["No expansion credit before results.", "NRR is 100% in year 1 by construction; module expansion is earned by measured outcomes, then modeled."],
  ["No blended ambition.", "The accelerated $48.9M path exists only as a labeled option behind the same evidence gates, with its own $40M capital plan."],
  ["No valuation set by this deck.", "EV figures are illustrative multiples on modeled ARR — terms belong to the term sheet, not the plan."],
];
nots.forEach(([h2, b], i) => {
  const x = MX + (i % 2) * 6.16, y = 1.70 + Math.floor(i / 2) * 1.56;
  s.addShape("rect", { x, y, w: 5.95, h: 1.42, fill: { color: i % 2 === 0 ? CARD : TINT }, line: { color: LINE, width: 1 } });
  s.addText([
    { text: h2 + "  ", options: { fontFace: SERIF, fontSize: 13.5, bold: true, color: INK } },
    { text: b, options: { fontFace: SANS, fontSize: 10.6, color: "3A4152" } },
  ], { x: x + 0.24, y: y + 0.12, w: 5.5, h: 1.2, valign: "top", margin: 0 });
});
FOOT(s, "A founder can talk themselves into growth; these numbers cannot be talked into anything", "Source: Plan v2.0 Appendix B · Deck-001 candor slide");

// =========================================================
// 17 · CLOSE (dark)
// =========================================================
s = p.addSlide(); PAGE(s, DARK);
s.addImage({ path: "logo.png", x: MX, y: 0.52, w: 0.55, h: 0.55 });
s.addText("ACUMENUS, INC. · ZEPHYRUS", { x: 1.35, y: 0.60, w: 7, h: 0.4, fontFace: MONO, fontSize: 11, color: "E8E4DA", charSpacing: 4, bold: true, valign: "middle", margin: 0 });
s.addText("THE ASK", { x: MX, y: 1.55, w: 6, h: 0.3, fontFace: MONO, fontSize: 11, color: GOLD2, charSpacing: 4, bold: true, margin: 0 });
s.addText([
  { text: "$7M proves it. $16M scales it.\n", options: { color: "F2EFE7" } },
  { text: "Evidence gates every dollar — and every claim.", options: { color: GOLD2, italic: true } },
], { x: MX, y: 1.90, w: 12.0, h: 1.75, fontFace: SERIF, fontSize: 36, bold: true, valign: "top", margin: 0 });
const closes = [
  ["NOW · VALIDATION", "$7M", "18 months of proof: security, connectors, paid design partners, founder-led sales."],
  ["GATED · SCALE", "$16M", "Unlocked by 3+ references · $2–4M ARR · 2 connectors · <150-day installs · >75% sub GM."],
  ["BY 2031 · LANDS AT", "$20.1M ARR", "36 logos · 55 sites · 74% GM · EBITDA break-even — on $23M staged, $1.20 per recurring dollar."],
];
closes.forEach(([h2, v, b], i) => {
  const x = MX + i * 4.12, w = 3.95;
  s.addShape("rect", { x, y: 3.85, w, h: 1.85, fill: { color: DARK2 }, line: { color: "2A3242", width: 1 } });
  s.addText(h2, { x: x + 0.22, y: 4.02, w: w - 0.44, h: 0.26, fontFace: MONO, fontSize: 9, bold: true, color: GOLD2, charSpacing: 1, margin: 0 });
  s.addText(v, { x: x + 0.22, y: 4.30, w: w - 0.44, h: 0.5, fontFace: SERIF, fontSize: 23, bold: true, color: "F2EFE7", margin: 0 });
  s.addText(b, { x: x + 0.22, y: 4.82, w: w - 0.44, h: 0.8, fontFace: SANS, fontSize: 9.6, color: "AEB4C2", valign: "top", margin: 0 });
});
s.addText("589% ACQ ROI  ·  6.9× LTV:CAC  ·  8.7 MO PAYBACK  ·  0.14× BURN  ·  110% NRR  ·  RULE OF 40 = 94  ·  2 FLAGS DISCLOSED",
  { x: MX, y: 6.02, w: W - 2 * MX, h: 0.35, fontFace: MONO, fontSize: 9.5, color: GOLD2, margin: 0 });
s.addShape("line", { x: MX, y: 6.52, w: W - 2 * MX, h: 0, line: { color: "2A3242", width: 0.75 } });
s.addText([
  { text: "The model is in the data room — change any assumption and all fifteen numbers recompute. ", options: { color: "AEB4C2" } },
  { text: "Sanjay M. Udoshi, MD · Founder · smudoshi@acumenus.net", options: { color: "F2EFE7", bold: true } },
], { x: MX, y: 6.62, w: 9.6, h: 0.6, fontFace: SANS, fontSize: 10.5, valign: "top", margin: 0 });
s.addText("ACUM-ZEP-FIN-DECK-002 · CONFIDENTIAL", { x: 9.6, y: 6.66, w: W - MX - 9.6, h: 0.3, fontFace: MONO, fontSize: 9.5, color: GOLD2, charSpacing: 2, align: "right", margin: 0 });

p.writeFile({ fileName: "Zephyrus_Investor_Deck_Reconciled.pptx" }).then(() => console.log("written"));
