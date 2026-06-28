import { useEddyStore } from '@/stores/eddyStore';
import { EddyMark } from './EddyMark';

export function EddyLauncher() {
  const open = useEddyStore((state) => state.open);

  return (
    <button
      type="button"
      onClick={open}
      aria-label="Ask Eddy"
      className="fixed bottom-5 right-5 z-[80] flex items-center gap-2 rounded-full bg-healthcare-primary px-4 py-3 text-white shadow-lg hover:bg-healthcare-primary-hover dark:bg-healthcare-primary-dark"
    >
      <EddyMark size={20} />
      <span className="text-sm font-medium">Ask Eddy</span>
    </button>
  );
}
