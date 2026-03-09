import React, { useEffect, useCallback } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useUIStore } from '@/stores/uiStore';
import {
  LayoutDashboard,
  BedDouble,
  Stethoscope,
  Siren,
  TrendingUp,
  BarChart3,
  X,
  Menu,
} from 'lucide-react';
import type { PageProps } from '@/types';

interface NavItem {
  readonly path: string;
  readonly label: string;
  readonly icon: React.ElementType;
  readonly section?: string;
}

const navItems: readonly NavItem[] = [
  { path: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, section: 'Overview' },
  { path: '/rtdc', label: 'RTDC', icon: BedDouble, section: 'Workflows' },
  { path: '/perioperative', label: 'Perioperative', icon: Stethoscope },
  { path: '/emergency', label: 'Emergency', icon: Siren },
  { path: '/improvement', label: 'Improvement', icon: TrendingUp, section: 'Quality' },
  { path: '/analytics', label: 'Analytics', icon: BarChart3 },
] as const;

function isActive(itemPath: string, currentUrl: string): boolean {
  if (itemPath === '/dashboard') {
    return currentUrl === '/dashboard' || currentUrl === '/';
  }
  return currentUrl.startsWith(itemPath);
}

export function MobileDrawerTrigger(): React.ReactElement {
  const { setMobileDrawerOpen } = useUIStore();

  return (
    <button
      onClick={() => setMobileDrawerOpen(true)}
      aria-label="Open navigation menu"
      className="mobile-drawer-trigger"
      style={{
        display: 'none',
        alignItems: 'center',
        justifyContent: 'center',
        width: 44,
        height: 44,
        border: 'none',
        background: 'transparent',
        color: 'var(--text-secondary)',
        cursor: 'pointer',
        borderRadius: 'var(--radius-md)',
        transition: `color var(--duration-normal) var(--ease-out)`,
      }}
      onMouseEnter={(e) => {
        e.currentTarget.style.color = 'var(--text-primary)';
      }}
      onMouseLeave={(e) => {
        e.currentTarget.style.color = 'var(--text-secondary)';
      }}
    >
      <Menu size={24} />
      <style>{`
        @media (max-width: 1023px) {
          .mobile-drawer-trigger { display: flex !important; }
        }
      `}</style>
    </button>
  );
}

export function MobileDrawer(): React.ReactElement {
  const { url } = usePage<PageProps>();
  const { mobileDrawerOpen, setMobileDrawerOpen } = useUIStore();

  const handleClose = useCallback(() => {
    setMobileDrawerOpen(false);
  }, [setMobileDrawerOpen]);

  // Close on Escape key
  useEffect(() => {
    if (!mobileDrawerOpen) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        handleClose();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    // Prevent body scroll while drawer is open
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.body.style.overflow = '';
    };
  }, [mobileDrawerOpen, handleClose]);

  // Close when navigating
  useEffect(() => {
    handleClose();
  }, [url, handleClose]);

  if (!mobileDrawerOpen) return <></>;

  let lastSection: string | undefined;

  return (
    <div
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 'var(--z-modal-backdrop)' as unknown as number,
        display: 'flex',
      }}
    >
      {/* Backdrop */}
      <div
        onClick={handleClose}
        aria-hidden="true"
        style={{
          position: 'absolute',
          inset: 0,
          background: 'rgba(0, 0, 0, 0.60)',
          backdropFilter: 'var(--blur-sm)',
          animation: 'fadeIn var(--duration-normal) var(--ease-out)',
        }}
      />

      {/* Drawer panel */}
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Navigation menu"
        style={{
          position: 'relative',
          width: 300,
          maxWidth: '85vw',
          height: '100%',
          background: 'var(--sidebar-bg)',
          borderRight: '1px solid var(--border-subtle)',
          display: 'flex',
          flexDirection: 'column',
          animation: 'slideInFromLeft var(--duration-slow) var(--ease-out)',
          zIndex: 1,
        }}
      >
        {/* Header */}
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: `var(--space-4) var(--space-5)`,
            height: 'var(--topbar-height)',
            borderBottom: '1px solid var(--border-subtle)',
            flexShrink: 0,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
            <div
              style={{
                width: 28,
                height: 28,
                borderRadius: 'var(--radius-sm)',
                background: 'var(--gradient-crimson)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                color: 'var(--text-primary)',
                fontFamily: 'var(--font-display)',
                fontWeight: 700,
                fontSize: 'var(--text-lg)',
              }}
            >
              Z
            </div>
            <span
              style={{
                fontFamily: 'var(--font-heading)',
                fontSize: 'var(--text-lg)',
                fontWeight: 600,
                color: 'var(--text-primary)',
              }}
            >
              Zephyrus
            </span>
          </div>

          <button
            onClick={handleClose}
            aria-label="Close navigation menu"
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              width: 36,
              height: 36,
              minWidth: 36,
              minHeight: 36,
              border: 'none',
              background: 'var(--glass-01)',
              color: 'var(--text-muted)',
              cursor: 'pointer',
              borderRadius: 'var(--radius-md)',
              transition: `all var(--duration-normal)`,
            }}
            onMouseEnter={(e) => {
              e.currentTarget.style.color = 'var(--text-primary)';
              e.currentTarget.style.background = 'var(--glass-03)';
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.color = 'var(--text-muted)';
              e.currentTarget.style.background = 'var(--glass-01)';
            }}
          >
            <X size={20} />
          </button>
        </div>

        {/* Navigation */}
        <nav
          style={{
            flex: 1,
            overflowY: 'auto',
            padding: `var(--space-3) 0`,
          }}
        >
          {navItems.map((item) => {
            const active = isActive(item.path, url);
            const showSection = item.section !== undefined && item.section !== lastSection;
            if (item.section !== undefined) {
              lastSection = item.section;
            }

            return (
              <div key={item.path}>
                {showSection && (
                  <div
                    style={{
                      padding: `var(--space-3) var(--space-5) var(--space-1)`,
                      fontSize: 'var(--text-xs)',
                      textTransform: 'uppercase',
                      letterSpacing: '0.8px',
                      color: 'var(--text-ghost)',
                      fontFamily: 'var(--font-body)',
                    }}
                  >
                    {item.section}
                  </div>
                )}
                <Link
                  href={item.path}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 'var(--space-3)',
                    padding: `var(--space-3) var(--space-5)`,
                    color: active ? 'var(--accent)' : 'var(--text-secondary)',
                    background: active ? 'var(--accent-bg)' : 'transparent',
                    borderRight: active ? '2px solid var(--accent)' : '2px solid transparent',
                    textDecoration: 'none',
                    fontSize: 'var(--text-base)',
                    fontFamily: 'var(--font-body)',
                    transition: `all var(--duration-normal) var(--ease-out)`,
                    minHeight: 44,
                  }}
                >
                  <item.icon size={20} style={{ flexShrink: 0 }} />
                  <span>{item.label}</span>
                </Link>
              </div>
            );
          })}
        </nav>

        {/* Branding */}
        <div
          style={{
            padding: 'var(--space-4) var(--space-5)',
            borderTop: '1px solid var(--border-subtle)',
            textAlign: 'center',
            flexShrink: 0,
          }}
        >
          <a
            href="https://www.acumenus.io"
            target="_blank"
            rel="noopener noreferrer"
            style={{
              fontFamily: 'var(--font-body)',
              fontSize: 'var(--text-xs)',
              color: 'var(--text-ghost)',
              textDecoration: 'none',
              letterSpacing: '0.3px',
            }}
          >
            Acumenus Data Sciences
          </a>
        </div>
      </div>

      {/* Slide-in animation */}
      <style>{`
        @keyframes slideInFromLeft {
          from { opacity: 0; transform: translateX(-100%); }
          to { opacity: 1; transform: translateX(0); }
        }
      `}</style>
    </div>
  );
}

export default MobileDrawer;
