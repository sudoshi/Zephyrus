interface ZephyrusMarkProps {
  className?: string;
  gradId?: string;
}

export function ZephyrusMark({ className }: ZephyrusMarkProps) {
  return (
    <img src="/images/zephyrus-icon.png" alt="" aria-hidden="true" className={`${className ?? ''} object-contain`} />
  );
}
