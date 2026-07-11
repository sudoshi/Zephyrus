export interface AuditActor {
  id: number | string | null;
  name: string;
  username: string | null;
  email: string | null;
  role: string | null;
}

export interface AuditEvent {
  eventUuid: string;
  occurredAt: string;
  actor: AuditActor | null;
  actorRole: string | null;
  action: string;
  category: string;
  outcome: string;
  reasonCode: string | null;
  authMethod: string | null;
  sourceSurface: string | null;
  targetType: string | null;
  targetId: string | number | null;
  routeName: string | null;
  routeUri: string | null;
  httpMethod: string | null;
  responseStatus: number | null;
  clientIp: string | null;
  userAgent: string | null;
  changes: unknown;
  metadata: unknown;
}

export function formatAuditTime(value: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value || 'Unknown time';
  return date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
}

export function actorDisplayName(event: AuditEvent): string {
  return event.actor?.name || event.actor?.username || 'System';
}
