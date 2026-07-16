import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * Accessible replacement for Inertia's bundled NProgress indicator.
 *
 * Inertia v2 ships an NProgress-derived bar whose markup hard-codes
 * `role="bar"`/`role="spinner"` — invalid ARIA roles that fail WCAG 2.2 AA
 * (4.1.2 Name, Role, Value) — and it locates that bar for animation via the
 * `[role="bar"]` selector, so the invalid role cannot simply be stripped. We
 * therefore disable Inertia's indicator (`progress: false` in app.tsx) and
 * render our own top-of-viewport bar driven by the router lifecycle events.
 *
 * The bar is purely supplementary visual feedback — page transitions are
 * already announced to assistive technology through the document title change —
 * so it is marked `aria-hidden` rather than exposing an incomplete progressbar
 * role, and it uses the on-canon `healthcare-primary` interactive color.
 */
export function NavigationProgress(): React.ReactElement | null {
  const [visible, setVisible] = useState(false);
  const [width, setWidth] = useState(0);
  const finishTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const startDelay = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    const clearTimers = (): void => {
      if (finishTimer.current) clearTimeout(finishTimer.current);
      if (startDelay.current) clearTimeout(startDelay.current);
    };

    const onStart = router.on('start', () => {
      clearTimers();
      // Match Inertia's default 250ms delay so instant cached visits do not flash.
      startDelay.current = setTimeout(() => {
        setVisible(true);
        setWidth(8);
      }, 250);
    });

    const onProgress = router.on('progress', (event) => {
      const percentage = event.detail.progress?.percentage;
      if (typeof percentage === 'number') {
        setVisible(true);
        setWidth(Math.max(8, Math.min(99, percentage)));
      }
    });

    const finish = (): void => {
      if (startDelay.current) clearTimeout(startDelay.current);
      setWidth(100);
      finishTimer.current = setTimeout(() => {
        setVisible(false);
        setWidth(0);
      }, 250);
    };

    const onFinish = router.on('finish', finish);

    return () => {
      clearTimers();
      onStart();
      onProgress();
      onFinish();
    };
  }, []);

  if (!visible) {
    return null;
  }

  return (
    <div
      aria-hidden="true"
      className="pointer-events-none fixed inset-x-0 top-0 z-[9999] h-0.5"
    >
      <div
        className="h-full bg-healthcare-primary transition-[width,opacity] duration-200 ease-linear dark:bg-healthcare-primary-dark"
        style={{ width: `${width}%`, opacity: width >= 100 ? 0 : 1 }}
      />
    </div>
  );
}
