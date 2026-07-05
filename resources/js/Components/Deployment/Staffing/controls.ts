// Shared control classes for the Staffing Wizard — one place so every input/select/
// button matches the Deployment Console treatment and the Token Canon (healthcare-*
// tokens with dark: pairs, on-scale sizes, no raw palette). Focus is the app-global
// :focus-visible ring, so no bespoke ring here.

export const INPUT =
  'rounded-md border border-healthcare-border bg-healthcare-surface px-2.5 py-1.5 text-sm text-healthcare-text-primary placeholder:text-healthcare-text-secondary dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark dark:text-healthcare-text-primary-dark dark:placeholder:text-healthcare-text-secondary-dark';

export const SELECT = INPUT;

export const LABEL =
  'block text-xs font-medium text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark';

export const BTN_PRIMARY =
  'inline-flex items-center justify-center gap-1.5 rounded-md bg-healthcare-primary px-3.5 py-2 text-sm font-medium text-white transition-colors duration-150 hover:bg-healthcare-primary/90 disabled:cursor-not-allowed disabled:opacity-50';

export const BTN_GHOST =
  'inline-flex items-center justify-center gap-1.5 rounded-md border border-healthcare-border px-3.5 py-2 text-sm font-medium text-healthcare-text-secondary transition-colors duration-150 hover:bg-healthcare-hover disabled:cursor-not-allowed disabled:opacity-50 dark:border-healthcare-border-dark dark:text-healthcare-text-secondary-dark dark:hover:bg-healthcare-hover-dark';

export const BTN_SM =
  'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors duration-150';
