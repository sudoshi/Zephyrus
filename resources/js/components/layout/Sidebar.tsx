import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useUIStore } from '@/stores/uiStore';
import {
  LayoutDashboard,
  BedDouble,
  Stethoscope,
  Siren,
  TrendingUp,
  BarChart3,
  ChevronLeft,
  ChevronRight,
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

export function Sidebar(): React.ReactElement {
  const { url } = usePage<PageProps>();
  const { sidebarOpen, toggleSidebar } = useUIStore();

  let lastSection: string | undefined;

  return (
    <aside
      className="sidebar"
      style={{
        position: 'fixed',
        top: 0,
        left: 0,
        bottom: 0,
        width: sidebarOpen ? 'var(--sidebar-width)' : 'var(--sidebar-width-collapsed)',
        background: 'var(--sidebar-bg)',
        borderRight: '1px solid var(--border-subtle)',
        display: 'flex',
        flexDirection: 'column',
        zIndex: 'var(--z-sidebar)' as unknown as number,
        transition: `width var(--duration-slow) var(--ease-out)`,
        overflow: 'hidden',
      }}
    >
      {/* Header */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 'var(--space-3)',
          padding: sidebarOpen ? 'var(--space-4) var(--space-5)' : 'var(--space-4) 0',
          justifyContent: sidebarOpen ? 'flex-start' : 'center',
          height: 'var(--topbar-height)',
          borderBottom: '1px solid var(--border-subtle)',
          flexShrink: 0,
        }}
      >
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
            flexShrink: 0,
          }}
        >
          Z
        </div>
        {sidebarOpen && (
          <span
            style={{
              fontFamily: 'var(--font-heading)',
              fontSize: 'var(--text-lg)',
              fontWeight: 600,
              color: 'var(--text-primary)',
              whiteSpace: 'nowrap',
            }}
          >
            Zephyrus
          </span>
        )}
      </div>

      {/* Navigation */}
      <nav
        style={{
          flex: 1,
          overflowY: 'auto',
          overflowX: 'hidden',
          padding: `var(--space-3) 0`,
        }}
      >
        {navItems.map((item) => {
          const active = isActive(item.path, url);
          const showSection = sidebarOpen && item.section !== undefined && item.section !== lastSection;
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
                title={sidebarOpen ? undefined : item.label}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 'var(--space-3)',
                  padding: sidebarOpen
                    ? `var(--space-2) var(--space-5)`
                    : `var(--space-2) 0`,
                  justifyContent: sidebarOpen ? 'flex-start' : 'center',
                  color: active ? 'var(--accent)' : 'var(--text-secondary)',
                  background: active ? 'var(--accent-bg)' : 'transparent',
                  borderRight: active ? '2px solid var(--accent)' : '2px solid transparent',
                  textDecoration: 'none',
                  fontSize: 'var(--text-base)',
                  fontFamily: 'var(--font-body)',
                  transition: `all var(--duration-normal) var(--ease-out)`,
                  minHeight: 40,
                  cursor: 'pointer',
                }}
                onMouseEnter={(e) => {
                  if (!active) {
                    e.currentTarget.style.color = 'var(--text-primary)';
                    e.currentTarget.style.background = 'var(--glass-01)';
                  }
                }}
                onMouseLeave={(e) => {
                  if (!active) {
                    e.currentTarget.style.color = 'var(--text-secondary)';
                    e.currentTarget.style.background = 'transparent';
                  }
                }}
              >
                <item.icon size={18} style={{ flexShrink: 0 }} />
                {sidebarOpen && (
                  <span style={{ whiteSpace: 'nowrap' }}>{item.label}</span>
                )}
              </Link>
            </div>
          );
        })}
      </nav>

      {/* Collapse toggle */}
      <div
        style={{
          padding: sidebarOpen ? 'var(--space-3) var(--space-5)' : 'var(--space-3) 0',
          borderTop: '1px solid var(--border-subtle)',
          display: 'flex',
          justifyContent: 'center',
          flexShrink: 0,
        }}
      >
        <button
          onClick={toggleSidebar}
          aria-label={sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar'}
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: 'var(--space-2)',
            padding: `var(--space-2) var(--space-3)`,
            borderRadius: 'var(--radius-md)',
            border: '1px solid var(--border-subtle)',
            background: 'var(--glass-01)',
            color: 'var(--text-muted)',
            cursor: 'pointer',
            fontSize: 'var(--text-sm)',
            fontFamily: 'var(--font-body)',
            transition: `all var(--duration-normal) var(--ease-out)`,
            width: sidebarOpen ? '100%' : 36,
            height: 36,
            minWidth: 36,
            minHeight: 36,
          }}
          onMouseEnter={(e) => {
            e.currentTarget.style.color = 'var(--text-primary)';
            e.currentTarget.style.borderColor = 'var(--border-hover)';
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.color = 'var(--text-muted)';
            e.currentTarget.style.borderColor = 'var(--border-subtle)';
          }}
        >
          {sidebarOpen ? <ChevronLeft size={16} /> : <ChevronRight size={16} />}
          {sidebarOpen && <span>Collapse</span>}
        </button>
      </div>

      {/* Acumenus branding */}
      <div
        style={{
          padding: sidebarOpen ? 'var(--space-3) var(--space-5)' : 'var(--space-3) 0',
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
            fontSize: sidebarOpen ? 'var(--text-xs)' : '9px',
            color: 'var(--text-ghost)',
            textDecoration: 'none',
            letterSpacing: '0.3px',
            transition: `color var(--duration-normal)`,
          }}
          onMouseEnter={(e) => {
            e.currentTarget.style.color = 'var(--text-muted)';
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.color = 'var(--text-ghost)';
          }}
        >
          {sidebarOpen ? 'Acumenus Data Sciences' : 'ADS'}
        </a>
      </div>
    </aside>
  );
}

export default Sidebar;
