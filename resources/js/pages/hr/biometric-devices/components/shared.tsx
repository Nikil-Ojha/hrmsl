// pages/hr/biometric-devices/components/shared.tsx
import { Badge } from '@/components/ui/badge';
import { toast } from '@/components/custom-toast';
import { cn } from '@/lib/utils';
import { useTranslation } from 'react-i18next';
import { CheckCircle2, CircleDashed, Loader2, WifiOff, Wifi, AlertTriangle, XCircle, Info, AlertCircle } from 'lucide-react';

export interface Option {
  value: string;
  label: string;
}

export interface CredentialField {
  name: string;
  label: string;
  type: 'text' | 'password';
  secret: boolean;
  required: boolean;
  help?: string;
}

export interface DriverMeta {
  value: string;
  label: string;
  description: string;
  manufacturers: string[];
  default_port: number | null;
  capabilities: {
    communication: 'push' | 'pull' | 'simulated';
    supports_tcp_probe: boolean;
    time_sync: boolean;
    employee_sync: boolean;
    log_fetch: boolean;
  };
  auth_types: Option[];
  credential_fields: CredentialField[];
  development_only?: boolean;
  allow_unverified?: boolean;
  operations?: Record<string, { level: 'supported' | 'unverified' | 'unsupported'; available: boolean }>;
}

export interface DeviceOptions {
  drivers: DriverMeta[];
  operations?: Option[];
  device_types: Option[];
  connection_types: Option[];
  connection_statuses: Option[];
  auth_types: Option[];
  sync_actions: Option[];
  sync_statuses: Option[];
  event_levels: Option[];
  command_statuses: Option[];
  branches: { id: number; name: string }[];
  departments: { id: number; name: string; branch_id: number | null }[];
  agents?: { id: number; name: string; agent_identifier: string; branch_id: number | null; status: string }[];
  timezones: string[];
  default_timezone: string;
}

export interface DeviceFormValues {
  name: string;
  serial_number: string;
  device_identifier: string;
  manufacturer: string;
  model: string;
  device_type: string;
  protocol: string;
  connection_type: string;
  ip_address: string;
  port: number | string | null;
  branch_id: number | string | null;
  department_id: number | string | null;
  agent_id?: number | string | null;
  timezone: string;
  auth_type: string;
  credentials: Record<string, string>;
  secrets_set?: Record<string, boolean>;
  is_active: boolean;
  notes: string;
}

export interface DeviceListItem {
  id: number;
  name: string;
  serial_number: string;
  device_identifier: string;
  manufacturer: string | null;
  model: string | null;
  device_type: string;
  device_type_label: string;
  protocol: string;
  protocol_label: string;
  connection_type: string;
  connection_type_label: string;
  ip_address: string | null;
  port: number | null;
  branch: string | null;
  department: string | null;
  agent_id?: number | null;
  agent_name?: string | null;
  agent_identifier?: string | null;
  agent_status?: string | null;
  is_active: boolean;
  connection_status: 'online' | 'offline' | 'unknown' | 'error';
  last_seen_at: string | null;
  last_sync_at: string | null;
  last_successful_sync_at: string | null;
  last_error: string | null;
  last_error_at: string | null;
  form: DeviceFormValues;
}

export const formatDateTime = (value?: string | null): string => {
  if (!value) return '—';
  const appSettings = (window as any).appSettings;
  if (appSettings?.formatDateTime) {
    try {
      return appSettings.formatDateTime(value);
    } catch {
      /* fall through */
    }
  }
  return new Date(value).toLocaleString();
};

export const timeAgo = (value?: string | null): string => {
  if (!value) return '';
  const diff = Math.round((Date.now() - new Date(value).getTime()) / 1000);
  if (diff < 60) return `${Math.max(diff, 0)}s ago`;
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  return `${Math.floor(diff / 86400)}d ago`;
};

const pill = 'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset';

export function ConnectionBadge({ status }: { status: string }) {
  const { t } = useTranslation();
  const map: Record<string, { cls: string; icon: React.ReactNode; label: string }> = {
    online: { cls: 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-900/30 dark:text-green-400', icon: <Wifi className="h-3 w-3" />, label: t('Online') },
    offline: { cls: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-900/30 dark:text-red-400', icon: <WifiOff className="h-3 w-3" />, label: t('Offline') },
    error: { cls: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-900/30 dark:text-orange-400', icon: <AlertTriangle className="h-3 w-3" />, label: t('Error') },
    unknown: { cls: 'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-400', icon: <CircleDashed className="h-3 w-3" />, label: t('Unknown') },
  };
  const item = map[status] ?? map.unknown;
  return (
    <span className={cn(pill, item.cls)}>
      {item.icon}
      {item.label}
    </span>
  );
}

export function ActiveBadge({ active }: { active: boolean }) {
  const { t } = useTranslation();
  return (
    <span className={cn(pill, active ? 'bg-green-50 text-green-700 ring-green-600/20' : 'bg-gray-100 text-gray-600 ring-gray-500/20')}>
      {active ? t('Active') : t('Inactive')}
    </span>
  );
}

export function RunStatusBadge({ status }: { status: string }) {
  const { t } = useTranslation();
  const map: Record<string, { cls: string; icon: React.ReactNode }> = {
    success: { cls: 'bg-green-50 text-green-700 ring-green-600/20', icon: <CheckCircle2 className="h-3 w-3" /> },
    partial: { cls: 'bg-amber-50 text-amber-700 ring-amber-600/20', icon: <AlertTriangle className="h-3 w-3" /> },
    failed: { cls: 'bg-red-50 text-red-700 ring-red-600/20', icon: <XCircle className="h-3 w-3" /> },
    running: { cls: 'bg-blue-50 text-blue-700 ring-blue-600/20', icon: <Loader2 className="h-3 w-3 animate-spin" /> },
    queued: { cls: 'bg-gray-50 text-gray-600 ring-gray-500/20', icon: <CircleDashed className="h-3 w-3" /> },
  };
  const item = map[status] ?? map.queued;
  return (
    <span className={cn(pill, item.cls)}>
      {item.icon}
      {t(status.charAt(0).toUpperCase() + status.slice(1))}
    </span>
  );
}

export function CommandStatusBadge({ status }: { status: string }) {
  const { t } = useTranslation();
  const cls: Record<string, string> = {
    queued: 'bg-gray-50 text-gray-600 ring-gray-500/20',
    sent: 'bg-blue-50 text-blue-700 ring-blue-600/20',
    acknowledged: 'bg-green-50 text-green-700 ring-green-600/20',
    failed: 'bg-red-50 text-red-700 ring-red-600/20',
    expired: 'bg-orange-50 text-orange-700 ring-orange-600/20',
    cancelled: 'bg-gray-100 text-gray-500 ring-gray-400/20',
  };
  return <span className={cn(pill, cls[status] ?? cls.queued)}>{t(status.charAt(0).toUpperCase() + status.slice(1))}</span>;
}

export function EventLevelIcon({ level }: { level: string }) {
  if (level === 'error') return <AlertCircle className="h-4 w-4 text-red-500" />;
  if (level === 'warning') return <AlertTriangle className="h-4 w-4 text-amber-500" />;
  return <Info className="h-4 w-4 text-blue-500" />;
}

export function CapabilityBadges({ driver }: { driver?: DriverMeta }) {
  const { t } = useTranslation();
  if (!driver) return null;
  const caps = [
    { key: 'time_sync', label: t('Time Sync') },
    { key: 'employee_sync', label: t('Employee Sync') },
    { key: 'log_fetch', label: t('Log Fetch') },
  ] as const;
  return (
    <div className="flex flex-wrap gap-1">
      <Badge variant="outline" className="text-[10px] uppercase">
        {driver.capabilities.communication === 'push' ? t('Push') : driver.capabilities.communication === 'simulated' ? t('Simulated') : t('Pull')}
      </Badge>
      {caps.map((c) => (
        <Badge
          key={c.key}
          variant="outline"
          className={cn('text-[10px]', driver.capabilities[c.key] ? 'border-green-300 text-green-700' : 'border-gray-200 text-gray-400 line-through')}
        >
          {c.label}
        </Badge>
      ))}
    </div>
  );
}

/**
 * Shared Inertia visit callbacks matching the toast conventions of the HRMS.
 */
export const visitCallbacks = (t: (k: string) => string, onDone?: () => void) => ({
  preserveScroll: true,
  onSuccess: (page: any) => {
    toast.dismiss();
    if (page.props.flash?.success) toast.success(t(page.props.flash.success));
    else if (page.props.flash?.error) toast.error(t(page.props.flash.error));
    onDone?.();
  },
  onError: (errors: any) => {
    toast.dismiss();
    toast.error(typeof errors === 'string' ? errors : Object.values(errors).join(', '));
  },
});
