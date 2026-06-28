import { useEffect, useRef } from 'react';
import type { EddyChatMessage } from '@/stores/eddyStore';
import { EddyAvatar } from './EddyAvatar';
import { EddyApprovalCard } from './EddyApprovalCard';

interface EddyMessageListProps {
  messages: EddyChatMessage[];
  isSending: boolean;
  surface?: string;
}

const PROVIDER_LABEL: Record<string, string> = {
  ollama: 'MedGemma · local',
  anthropic: 'Claude · frontier',
};

export function EddyMessageList({ messages, isSending, surface }: EddyMessageListProps) {
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages.length, isSending]);

  return (
    <div className="flex-1 space-y-3 overflow-y-auto px-4 py-4" aria-live="polite">
      {messages.length === 0 && <EmptyState />}
      {messages.map((message) => (
        <div key={message.id}>
          <Bubble message={message} />
          {message.role === 'assistant' && message.proposedAction && (
            <EddyApprovalCard message={message} surface={surface} />
          )}
        </div>
      ))}
      {isSending && <TypingIndicator />}
      <div ref={endRef} />
    </div>
  );
}

function EmptyState() {
  return (
    <div className="mt-6 flex flex-col items-center gap-3 px-6 text-center">
      <EddyAvatar size={72} />
      <p className="text-sm font-medium text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
        Ask Eddy about this screen or the operational picture.
      </p>
      <p className="text-xs text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
        I advise — I never take an action without your approval.
      </p>
    </div>
  );
}

function Bubble({ message }: { message: EddyChatMessage }) {
  if (message.role === 'user') {
    return (
      <div className="flex justify-end">
        <div className="max-w-[85%] rounded-lg bg-healthcare-primary px-3 py-2 text-sm text-white dark:bg-healthcare-primary-dark">
          {message.content}
        </div>
      </div>
    );
  }

  return (
    <div className="flex justify-start gap-2">
      <EddyAvatar size={26} className="mt-0.5" />
      <div className="max-w-[85%] rounded-lg border border-healthcare-border bg-healthcare-surface px-3 py-2 dark:border-healthcare-border-dark dark:bg-healthcare-surface-dark">
        <p className="whitespace-pre-wrap text-sm text-healthcare-text-primary dark:text-healthcare-text-primary-dark">
          {message.content}
        </p>
        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
          {message.provider && PROVIDER_LABEL[message.provider] && (
            <span className="text-xs text-healthcare-text-secondary tabular-nums dark:text-healthcare-text-secondary-dark">
              {PROVIDER_LABEL[message.provider]}
            </span>
          )}
          {message.fallbackReason && <StatusChip tone="warning" label={`fell back · ${message.fallbackReason}`} />}
          {message.status === 'error' && <StatusChip tone="critical" label="degraded" />}
        </div>
      </div>
    </div>
  );
}

function StatusChip({ tone, label }: { tone: 'warning' | 'critical'; label: string }) {
  const classes =
    tone === 'warning'
      ? 'border-healthcare-warning/40 text-healthcare-warning dark:text-healthcare-warning-dark'
      : 'border-healthcare-critical/40 text-healthcare-critical dark:text-healthcare-critical-dark';
  return (
    // Status paired with an icon + label, never colour alone (design canon).
    <span className={`inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs ${classes}`}>
      <span aria-hidden="true">▲</span>
      {label}
    </span>
  );
}

function TypingIndicator() {
  // Smooth staggered fade (no bounce/elastic easing — design canon).
  return (
    <div className="flex items-center gap-1 px-1 text-healthcare-text-secondary dark:text-healthcare-text-secondary-dark">
      <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-current" />
      <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-current [animation-delay:0.15s]" />
      <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-current [animation-delay:0.3s]" />
    </div>
  );
}
