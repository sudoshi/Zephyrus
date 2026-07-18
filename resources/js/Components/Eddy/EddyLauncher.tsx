import { useEddyStore } from '@/stores/eddyStore';
import { EddyAvatar } from './EddyAvatar';

export function EddyLauncher() {
  const open = useEddyStore((state) => state.open);

  return (
    <button
      type="button"
      onClick={open}
      aria-label="Ask Eddy"
      className="fixed bottom-4 right-4 z-[80] flex items-center gap-2 rounded-full bg-healthcare-primary p-2 text-white shadow-lg hover:bg-healthcare-primary-hover sm:bottom-5 sm:right-5 sm:pr-4 dark:bg-healthcare-primary-dark"
    >
      <EddyAvatar size={28} className="border-white/30" />
      {/* Icon-only on phones — the pill label eats scarce viewport width and
          overlaps board content (HFE audit RESP-01). */}
      <span className="hidden text-sm font-medium sm:inline">Ask Eddy</span>
    </button>
  );
}
