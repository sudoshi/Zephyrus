import { useEddyStore } from '@/stores/eddyStore';
import { EddyAvatar } from './EddyAvatar';

export function EddyLauncher() {
  const open = useEddyStore((state) => state.open);

  return (
    <button
      type="button"
      onClick={open}
      aria-label="Ask Eddy"
      className="fixed bottom-5 right-5 z-[80] flex items-center gap-2 rounded-full bg-healthcare-primary py-2 pl-2 pr-4 text-white shadow-lg hover:bg-healthcare-primary-hover dark:bg-healthcare-primary-dark"
    >
      <EddyAvatar size={28} className="border-white/30" />
      <span className="text-sm font-medium">Ask Eddy</span>
    </button>
  );
}
