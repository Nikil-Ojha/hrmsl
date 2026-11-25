// pages/hr/biometric-dashboard/index.tsx
import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageTemplate } from '@/components/page-template';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Pagination } from '@/components/ui/pagination';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { hasPermission } from '@/utils/authorization';
import { toast } from '@/components/custom-toast';
import {
  Activity, AlertTriangle, ArrowUpRight, CheckCircle2, ChevronDown, ChevronUp, ChevronsUpDown,
  Clock, Cpu, Download, Eye, Fingerprint, Radio, RefreshCw, Search, Server, ShieldAlert,
  Users, Wifi, WifiOff, X,
} from 'lucide-react';
import {
  Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip as RechartsTooltip, XAxis, YAxis,
} from 'recharts';
import {
  ActiveBadge, ConnectionBadge, formatDateTime, timeAgo, visitCallbacks,
} from '../biometric-devices/components/shared';

interface DashboardSummary {
  total_devices: number;
  online_devices: number;
  offline_devices: number;
  attention_devices: number;
  inactive_devices: number;
  enrolled_employees: number;
  total_device_enrollments: number;
  today_punches: number;
  today_processed_punches: number;
  missing_clock_outs: number;
  last_successful_sync_at: string | null;
  last_successful_sync_device: string | null;
  last_successful_sync_action: string | null;
  failed_sync_count: number;
  pending_sync_failures: number;
  failed_runs_24h: number;
}

interface DeviceActivityRow {
  id: number;
  name: string;
  serial_number: string;
  device_identifier: string;
  manufacturer: string | null;
  model: string | null;
  device_type_label: string;
  protocol: string;
  protocol_label: string;
  connection_type_label: string;
  ip_address: string | null;
  port: number | null;
  branch_id: number | null;
  branch_name: string | null;
  department_name: string | null;
  agent_id: number | null;
  agent_name: string | null;
  agent_status: string | null;
  is_active: boolean;
  status: 'online' | 'offline' | 'unknown' | 'error';
  requires_attention: boolean;
  attention_reasons: string[];
  last_communication_at: string | null;
  last_seen_ip: string | null;
  last_sync_at: string | null;
  last_successful_sync_at: string | null;
  last_sync_status: string | null;
  last_sync_action: string | null;
  pending_failures_count: number;
  enrolled_employees_count: number;
  capabilities: {
    supports_tcp_probe: boolean;
    time_sync: boolean;
    employee_sync: boolean;
    log_fetch: boolean;
  };
}

export default function BiometricDashboard() {
  const { t } = useTranslation();
  const {
    auth,
    summary,
    statusBreakdown = [],
    punchTrend7d = [],
    recentFailures = [],
    agentsSummary,
    devices,
    branches = [],
    filters = {},
  } = usePage().props as any;

  const permissions: string[] = auth?.permissions || [];
  const canSyncDevice = hasPermission(permissions, 'sync-biometric-devices');
  const canViewDevice = hasPermission(permissions, 'view-biometric-devices') || hasPermission(permissions, 'manage-biometric-devices');
  const canManageDevices = hasPermission(permissions, 'manage-biometric-devices');
  const canManageEnrollments = hasPermission(permissions, 'manage-biometric-enrollments');
  const canManageSync = hasPermission(permissions, 'manage-biometric-attendance');
  const canManageAgents = hasPermission(permissions, 'manage-biometric-agents');

  const s: DashboardSummary = summary || {
    total_devices: 0,
    online_devices: 0,
    offline_devices: 0,
    attention_devices: 0,
    inactive_devices: 0,
    enrolled_employees: 0,
    total_device_enrollments: 0,
    today_punches: 0,
    today_processed_punches: 0,
    missing_clock_outs: 0,
    last_successful_sync_at: null,
    last_successful_sync_device: null,
    last_successful_sync_action: null,
    failed_sync_count: 0,
    pending_sync_failures: 0,
    failed_runs_24h: 0,
  };

  const [isRefreshing, setIsRefreshing] = useState(false);
  const [search, setSearch] = useState<string>(filters.search || '');
  const [branchId, setBranchId] = useState<string>(filters.branch_id || '_empty_');
  const [health, setHealth] = useState<string>(filters.health || 'all');

  const buildParams = (extra: Record<string, any> = {}) => ({
    search: search || undefined,
    branch_id: branchId !== '_empty_' ? branchId : undefined,
    health: health !== 'all' ? health : undefined,
    sort_field: filters.sort_field,
    sort_direction: filters.sort_direction,
    per_page: filters.per_page,
    ...extra,
  });

  const applyFilters = (extra: Record<string, any> = {}) => {
    router.get(route('hr.biometric-dashboard.index'), buildParams({ page: 1, ...extra }), {
      preserveState: true,
      preserveScroll: true,
    });
  };

  const firstRender = useRef(true);
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }
    applyFilters();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [branchId, health]);

  const handleRefresh = () => {
    setIsRefreshing(true);
    router.reload({
      onFinish: () => setTimeout(() => setIsRefreshing(false), 350),
    });
  };

  const clearFilters = () => {
    setSearch('');
    setBranchId('_empty_');
    setHealth('all');
    router.get(route('hr.biometric-dashboard.index'), {}, { preserveState: true, preserveScroll: true });
  };

  const sortBy = (field: string) => {
    const dir = filters.sort_field === field && filters.sort_direction === 'asc' ? 'desc' : 'asc';
    router.get(route('hr.biometric-dashboard.index'), buildParams({ sort_field: field, sort_direction: dir }), {
      preserveState: true,
      preserveScroll: true,
    });
  };

  const sortIcon = (field: string) => {
    if (filters.sort_field !== field) return <ChevronsUpDown className="ml-1 h-3 w-3 inline opacity-40" />;
    return filters.sort_direction === 'asc'
      ? <ChevronUp className="ml-1 h-3 w-3 inline" />
      : <ChevronDown className="ml-1 h-3 w-3 inline" />;
  };

  const runDeviceAction = (routeName: string, deviceId: number, loadingMessage: string) => {
    toast.loading(t(loadingMessage));
    router.post(route(routeName, deviceId), {}, visitCallbacks(t));
  };

  const pageActions: any[] = [
    {
      label: t('Refresh'),
      icon: <RefreshCw className={`h-4 w-4 mr-1.5 ${isRefreshing ? 'animate-spin' : ''}`} />,
      variant: 'outline' as const,
      onClick: handleRefresh,
    },
  ];
  if (canManageDevices) {
    pageActions.push({
      label: t('Manage Devices'),
      icon: <Cpu className="h-4 w-4 mr-1.5" />,
      variant: 'default' as const,
      onClick: () => router.visit(route('hr.biometric-devices.index')),
    });
  }

  const nonZeroStatusData = statusBreakdown.filter((item: any) => item.value > 0);
  const hasActiveFilters = Boolean(search) || branchId !== '_empty_' || health !== 'all';

  const kpiCards = [
    {
      label: t('Total Registered Devices'),
      value: s.total_devices,
      hint: s.inactive_devices > 0
        ? t(':active active · :inactive inactive', { active: s.total_devices - s.inactive_devices, inactive: s.inactive_devices })
        : t('Registered biometric terminals'),
      icon: Cpu,
      tone: 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400',
      onClick: () => setHealth('all'),
    },
    {
      label: t('Online Devices'),
      value: s.online_devices,
      hint: s.total_devices > 0
        ? `${Math.round((s.online_devices / Math.max(1, s.total_devices - s.inactive_devices)) * 100)}% ${t('of active fleet')}`
        : t('Heartbeat within threshold'),
      icon: Wifi,
      tone: 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400',
      onClick: () => setHealth('online'),
    },
    {
      label: t('Offline Devices'),
      value: s.offline_devices,
      hint: t('Active devices not responding'),
      icon: WifiOff,
      tone: s.offline_devices > 0
        ? 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400'
        : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
      onClick: () => setHealth('offline'),
    },
    {
      label: t('Devices Requiring Attention'),
      value: s.attention_devices,
      hint: s.attention_devices > 0 ? t('Offline, errors or pending sync failures') : t('All active devices healthy'),
      icon: AlertTriangle,
      tone: s.attention_devices > 0
        ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400'
        : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400',
      onClick: () => setHealth('attention'),
      highlight: s.attention_devices > 0,
    },
    {
      label: t('Total Enrolled Employees'),
      value: s.enrolled_employees,
      hint: t(':count device registrations total', { count: s.total_device_enrollments }),
      icon: Users,
      tone: 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-400',
      href: canManageEnrollments ? route('hr.biometric-enrollments.index') : undefined,
    },
    {
      label: t("Today's Biometric Punches"),
      value: s.today_punches,
      hint: s.missing_clock_outs > 0
        ? t(':processed processed · :missing missing clock-outs', { processed: s.today_processed_punches, missing: s.missing_clock_outs })
        : t(':processed processed into attendance', { processed: s.today_processed_punches }),
      icon: Fingerprint,
      tone: 'bg-cyan-100 text-cyan-600 dark:bg-cyan-900/40 dark:text-cyan-400',
      href: canManageSync ? route('hr.biometric-sync.index', { tab: 'punches' }) : undefined,
    },
    {
      label: t('Last Successful Sync'),
      value: s.last_successful_sync_at ? timeAgo(s.last_successful_sync_at) : t('Never'),
      hint: s.last_successful_sync_at
        ? `${s.last_successful_sync_device ?? ''}${s.last_successful_sync_action ? ` · ${t(s.last_successful_sync_action)}` : ''}`
        : t('No completed synchronization yet'),
      title: s.last_successful_sync_at ? formatDateTime(s.last_successful_sync_at) : undefined,
      icon: CheckCircle2,
      tone: 'bg-green-100 text-green-600 dark:bg-green-900/40 dark:text-green-400',
      href: canManageSync ? route('hr.biometric-sync.index', { tab: 'history' }) : undefined,
    },
    {
      label: t('Failed Sync Count'),
      value: s.failed_sync_count,
      hint: t(':pending pending retries · :runs failed runs (24h)', { pending: s.pending_sync_failures, runs: s.failed_runs_24h }),
      icon: ShieldAlert,
      tone: s.failed_sync_count > 0
        ? 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400'
        : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
      href: canManageSync ? route('hr.biometric-sync.index', { tab: 'failures' }) : undefined,
      highlight: s.failed_sync_count > 0,
    },
  ];

  const healthFilters = [
    { key: 'all', label: t('All'), count: s.total_devices },
    { key: 'online', label: t('Online'), count: s.online_devices },
    { key: 'offline', label: t('Offline'), count: s.offline_devices },
    { key: 'attention', label: t('Requires Attention'), count: s.attention_devices },
    { key: 'inactive', label: t('Inactive'), count: s.inactive_devices },
  ];

  return (
    <PageTemplate
      title={t('Biometric Dashboard')}
      description={t('Real-time overview of biometric terminals, employee enrollments, daily attendance punches and synchronization health')}
      url="/hr/biometric-dashboard"
      actions={pageActions}
      breadcrumbs={[
        { title: t('Dashboard'), href: route('dashboard') },
        { title: t('Attendance') },
        { title: t('Biometric Dashboard') },
      ]}
    >
      {/* 8 KPI Summary Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        {kpiCards.map((card) => {
          const Icon = card.icon;
          const cardBody = (
            <div
              title={card.title}
              className={`h-full bg-white dark:bg-gray-800 rounded-xl border p-5 shadow-sm transition-colors flex items-start justify-between gap-3 ${
                card.highlight
                  ? 'border-amber-300 dark:border-amber-700/70'
                  : 'border-gray-200 dark:border-gray-700'
              } hover:border-primary/50`}
            >
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground mb-1 truncate">{card.label}</p>
                <p className="text-2xl font-bold text-gray-900 dark:text-white truncate">{card.value}</p>
                <p className="text-xs text-muted-foreground mt-2 truncate">{card.hint}</p>
              </div>
              <div className={`p-3 rounded-xl shrink-0 ${card.tone}`}>
                <Icon className="h-5 w-5" />
              </div>
            </div>
          );

          if (card.href) {
            return (
              <Link key={card.label} href={card.href} className="block text-left">
                {cardBody}
              </Link>
            );
          }

          return (
            <button key={card.label} type="button" onClick={card.onClick} className="block w-full text-left cursor-pointer">
              {cardBody}
            </button>
          );
        })}
      </div>

      {/* Charts & Fleet Health Row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* 7-Day Biometric Punch Volume */}
        <Card className="lg:col-span-2">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <div>
              <CardTitle className="text-base flex items-center gap-2">
                <Activity className="h-4 w-4 text-primary" />
                {t('Biometric Punches (Last 7 Days)')}
              </CardTitle>
              <p className="text-xs text-muted-foreground mt-0.5">
                {t('Total captured punches vs processed attendance events')}
              </p>
            </div>
            {canManageSync && (
              <Link
                href={route('hr.biometric-sync.index')}
                className="text-xs font-medium text-primary hover:underline inline-flex items-center gap-1"
              >
                {t('Attendance Sync')} <ArrowUpRight className="h-3.5 w-3.5" />
              </Link>
            )}
          </CardHeader>
          <CardContent className="pt-2">
            <div className="h-56 w-full">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={punchTrend7d} margin={{ top: 8, right: 12, left: -16, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                  <XAxis dataKey="label" tick={{ fontSize: 12 }} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                  <RechartsTooltip />
                  <Bar dataKey="punches" name={t('Total Punches')} fill="#3b82f6" radius={[4, 4, 0, 0]} maxBarSize={28} />
                  <Bar dataKey="processed" name={t('Processed')} fill="#10b981" radius={[4, 4, 0, 0]} maxBarSize={28} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>

        {/* Device Fleet Status + Local Agents & Recent Failures */}
        <Card className="flex flex-col">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-base">{t('Fleet Health & Sync Alerts')}</CardTitle>
            {canManageAgents && agentsSummary && (
              <Link href={route('hr.biometric-agents.index')}>
                <Badge variant="outline" className="text-xs font-normal hover:bg-muted">
                  <Server className="h-3 w-3 mr-1" />
                  {t('Agents')}: {agentsSummary.online}/{agentsSummary.total}
                </Badge>
              </Link>
            )}
          </CardHeader>
          <CardContent className="flex-1 flex flex-col justify-between gap-4 pt-2">
            {nonZeroStatusData.length > 0 ? (
              <div className="flex items-center gap-4">
                <div className="h-32 w-32 shrink-0">
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie
                        data={nonZeroStatusData}
                        dataKey="value"
                        nameKey="name"
                        innerRadius={32}
                        outerRadius={54}
                        paddingAngle={2}
                      >
                        {nonZeroStatusData.map((entry: any) => (
                          <Cell key={entry.name} fill={entry.color} />
                        ))}
                      </Pie>
                      <RechartsTooltip />
                    </PieChart>
                  </ResponsiveContainer>
                </div>
                <div className="flex-1 space-y-1.5 text-xs">
                  {statusBreakdown.map((item: any) => (
                    <div key={item.name} className="flex items-center justify-between">
                      <span className="flex items-center gap-2 text-muted-foreground">
                        <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: item.color }} />
                        {t(item.name)}
                      </span>
                      <span className="font-semibold text-foreground">{item.value}</span>
                    </div>
                  ))}
                </div>
              </div>
            ) : (
              <div className="py-6 text-center text-xs text-muted-foreground">
                {t('No biometric devices registered yet.')}
              </div>
            )}

            <div className="border-t pt-3 space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  {t('Pending Sync Failures')}
                </span>
                {canManageSync && recentFailures.length > 0 && (
                  <Link
                    href={route('hr.biometric-sync.index', { tab: 'failures' })}
                    className="text-xs text-primary hover:underline"
                  >
                    {t('View all')}
                  </Link>
                )}
              </div>
              {recentFailures.length === 0 ? (
                <div className="flex items-center gap-2 text-xs text-emerald-600">
                  <CheckCircle2 className="h-4 w-4 shrink-0" />
                  <span>{t('No unresolved synchronization failures.')}</span>
                </div>
              ) : (
                <div className="space-y-1.5">
                  {recentFailures.slice(0, 3).map((f: any) => (
                    <div key={f.id} className="rounded-md border border-red-200/70 bg-red-50/40 dark:bg-red-950/20 px-2.5 py-1.5 text-xs">
                      <div className="flex items-center justify-between gap-2">
                        <span className="font-medium truncate">{f.device_name}</span>
                        <Badge variant="outline" className="text-[10px] font-mono shrink-0">{f.error_code}</Badge>
                      </div>
                      <p className="text-[11px] text-red-700 dark:text-red-300 truncate mt-0.5">{f.error_message}</p>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Device Activity Table */}
      <Card>
        <CardHeader className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <CardTitle className="text-base">{t('Device Activity')}</CardTitle>
              <p className="text-xs text-muted-foreground mt-0.5">
                {t('Live connectivity, synchronization status, enrollment counts and quick actions per device')}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
              {healthFilters.map((hf) => (
                <Button
                  key={hf.key}
                  size="sm"
                  variant={health === hf.key ? 'default' : 'outline'}
                  className="h-8 text-xs"
                  onClick={() => setHealth(hf.key)}
                >
                  {hf.label}
                  <span
                    className={`ml-1.5 rounded-full px-1.5 py-0 text-[10px] ${
                      health === hf.key
                        ? 'bg-white/20 text-white'
                        : hf.key === 'attention' && hf.count > 0
                          ? 'bg-amber-100 text-amber-800'
                          : 'bg-muted text-muted-foreground'
                    }`}
                  >
                    {hf.count}
                  </span>
                </Button>
              ))}
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                applyFilters();
              }}
              className="flex items-center gap-2"
            >
              <div className="relative">
                <Search className="h-4 w-4 absolute left-2.5 top-2.5 text-muted-foreground" />
                <Input
                  className="pl-8 w-64 h-9"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder={t('Search device, serial, IP, branch...')}
                />
              </div>
              <Button type="submit" size="sm" variant="outline">{t('Search')}</Button>
            </form>

            <Select value={branchId} onValueChange={setBranchId}>
              <SelectTrigger className="w-48 h-9">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="_empty_">{t('All Branches')}</SelectItem>
                {branches.map((b: { id: number; name: string }) => (
                  <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>

            {hasActiveFilters && (
              <Button size="sm" variant="ghost" onClick={clearFilters}>
                <X className="h-3.5 w-3.5 mr-1" /> {t('Reset')}
              </Button>
            )}
          </div>
        </CardHeader>

        <CardContent className="p-0 overflow-x-auto">
          <TooltipProvider>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>
                    <button type="button" onClick={() => sortBy('name')} className="font-medium hover:text-foreground cursor-pointer">
                      {t('Device Name')}{sortIcon('name')}
                    </button>
                  </TableHead>
                  <TableHead>
                    <button type="button" onClick={() => sortBy('branch_name')} className="font-medium hover:text-foreground cursor-pointer">
                      {t('Branch / Location')}{sortIcon('branch_name')}
                    </button>
                  </TableHead>
                  <TableHead>
                    <button type="button" onClick={() => sortBy('serial_number')} className="font-medium hover:text-foreground cursor-pointer">
                      {t('Serial Number')}{sortIcon('serial_number')}
                    </button>
                  </TableHead>
                  <TableHead>{t('Status')}</TableHead>
                  <TableHead>
                    <button type="button" onClick={() => sortBy('last_communication')} className="font-medium hover:text-foreground cursor-pointer">
                      {t('Last Communication')}{sortIcon('last_communication')}
                    </button>
                  </TableHead>
                  <TableHead>
                    <button type="button" onClick={() => sortBy('last_sync')} className="font-medium hover:text-foreground cursor-pointer">
                      {t('Last Synchronization')}{sortIcon('last_sync')}
                    </button>
                  </TableHead>
                  <TableHead className="text-center">
                    <button type="button" onClick={() => sortBy('enrolled_employees')} className="font-medium hover:text-foreground cursor-pointer">
                      {t('Enrolled Employees')}{sortIcon('enrolled_employees')}
                    </button>
                  </TableHead>
                  <TableHead className="text-right">{t('Actions')}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(devices?.data ?? []).map((d: DeviceActivityRow) => (
                  <TableRow key={d.id} className={d.requires_attention ? 'bg-amber-50/30 dark:bg-amber-950/10' : ''}>
                    {/* 1. Device Name */}
                    <TableCell>
                      <div className="flex items-center gap-1.5">
                        {canViewDevice ? (
                          <Link
                            href={route('hr.biometric-devices.show', d.id)}
                            className="font-medium text-primary hover:underline"
                          >
                            {d.name}
                          </Link>
                        ) : (
                          <span className="font-medium">{d.name}</span>
                        )}
                      </div>
                      <div className="text-xs text-muted-foreground">
                        {[d.manufacturer, d.model, d.protocol_label].filter(Boolean).join(' · ')}
                      </div>
                    </TableCell>

                    {/* 2. Branch / Location */}
                    <TableCell>
                      <div className="text-sm">{d.branch_name || t('Unassigned')}</div>
                      <div className="text-xs text-muted-foreground">
                        {d.department_name ? `${d.department_name} · ` : ''}
                        {d.agent_name
                          ? `${t('Agent')}: ${d.agent_name}`
                          : d.ip_address
                            ? `${d.ip_address}${d.port ? `:${d.port}` : ''}`
                            : d.connection_type_label}
                      </div>
                    </TableCell>

                    {/* 3. Serial Number */}
                    <TableCell className="whitespace-nowrap">
                      <div className="font-mono text-xs font-medium">{d.serial_number}</div>
                      <div className="font-mono text-[11px] text-muted-foreground">{d.device_identifier}</div>
                    </TableCell>

                    {/* 4. Status */}
                    <TableCell>
                      <div className="flex flex-wrap items-center gap-1.5">
                        <ConnectionBadge status={d.status} />
                        {!d.is_active && <ActiveBadge active={false} />}
                        {d.requires_attention && (
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-300 cursor-help text-[11px]">
                                <AlertTriangle className="h-3 w-3 mr-1" /> {t('Attention')}
                              </Badge>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-xs">
                              <ul className="list-disc pl-4 space-y-0.5 text-xs">
                                {d.attention_reasons.map((reason, idx) => (
                                  <li key={idx}>{reason}</li>
                                ))}
                              </ul>
                            </TooltipContent>
                          </Tooltip>
                        )}
                      </div>
                    </TableCell>

                    {/* 5. Last Communication */}
                    <TableCell className="whitespace-nowrap text-xs">
                      {d.last_communication_at ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <span className="font-medium cursor-default">{timeAgo(d.last_communication_at)}</span>
                          </TooltipTrigger>
                          <TooltipContent>{formatDateTime(d.last_communication_at)}</TooltipContent>
                        </Tooltip>
                      ) : (
                        <span className="text-muted-foreground">{t('Never')}</span>
                      )}
                      {d.last_seen_ip && (
                        <div className="text-[11px] font-mono text-muted-foreground">{d.last_seen_ip}</div>
                      )}
                    </TableCell>

                    {/* 6. Last Synchronization */}
                    <TableCell className="whitespace-nowrap text-xs">
                      {d.last_sync_at ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <span className="font-medium cursor-default">{timeAgo(d.last_sync_at)}</span>
                          </TooltipTrigger>
                          <TooltipContent>{formatDateTime(d.last_sync_at)}</TooltipContent>
                        </Tooltip>
                      ) : (
                        <span className="text-muted-foreground">{t('Never')}</span>
                      )}
                      <div className="flex items-center gap-1.5 mt-0.5">
                        {d.last_sync_action && (
                          <span className="text-[11px] text-muted-foreground">{t(d.last_sync_action)}</span>
                        )}
                        {d.pending_failures_count > 0 && (
                          <Badge variant="outline" className="text-[10px] px-1.5 py-0 bg-red-50 text-red-700 border-red-200">
                            {d.pending_failures_count} {t('failed')}
                          </Badge>
                        )}
                      </div>
                    </TableCell>

                    {/* 7. Enrolled Employees */}
                    <TableCell className="text-center">
                      {canManageEnrollments ? (
                        <Link
                          href={route('hr.biometric-enrollments.index', { device_id: d.id })}
                          className="inline-flex items-center gap-1 rounded-full bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 px-2.5 py-0.5 text-xs font-semibold hover:bg-indigo-100"
                        >
                          <Users className="h-3 w-3" /> {d.enrolled_employees_count}
                        </Link>
                      ) : (
                        <Badge variant="secondary">{d.enrolled_employees_count}</Badge>
                      )}
                    </TableCell>

                    {/* 8. Actions */}
                    <TableCell className="text-right whitespace-nowrap">
                      <div className="inline-flex items-center justify-end gap-1">
                        {canViewDevice && (
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <Link href={route('hr.biometric-devices.show', d.id)}>
                                <Button size="sm" variant="ghost" className="h-8 w-8 p-0">
                                  <Eye className="h-4 w-4 text-slate-600" />
                                </Button>
                              </Link>
                            </TooltipTrigger>
                            <TooltipContent>{t('View device details')}</TooltipContent>
                          </Tooltip>
                        )}

                        {canSyncDevice && d.is_active && (
                          <>
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <Button
                                  size="sm"
                                  variant="ghost"
                                  className="h-8 w-8 p-0"
                                  onClick={() => runDeviceAction('hr.biometric-devices.connectivity', d.id, 'Checking device connectivity...')}
                                >
                                  <Radio className="h-4 w-4 text-emerald-600" />
                                </Button>
                              </TooltipTrigger>
                              <TooltipContent>{t('Check connectivity')}</TooltipContent>
                            </Tooltip>

                            {d.capabilities?.time_sync && (
                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <Button
                                    size="sm"
                                    variant="ghost"
                                    className="h-8 w-8 p-0"
                                    onClick={() => runDeviceAction('hr.biometric-devices.sync-time', d.id, 'Synchronizing device time...')}
                                  >
                                    <Clock className="h-4 w-4 text-blue-600" />
                                  </Button>
                                </TooltipTrigger>
                                <TooltipContent>{t('Synchronize device time')}</TooltipContent>
                              </Tooltip>
                            )}

                            {d.capabilities?.employee_sync && (
                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <Button
                                    size="sm"
                                    variant="ghost"
                                    className="h-8 w-8 p-0"
                                    onClick={() => runDeviceAction('hr.biometric-devices.sync-employees', d.id, 'Synchronizing employees to device...')}
                                  >
                                    <Users className="h-4 w-4 text-indigo-600" />
                                  </Button>
                                </TooltipTrigger>
                                <TooltipContent>{t('Synchronize employees')}</TooltipContent>
                              </Tooltip>
                            )}

                            {d.capabilities?.log_fetch && (
                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <Button
                                    size="sm"
                                    variant="ghost"
                                    className="h-8 w-8 p-0"
                                    onClick={() => runDeviceAction('hr.biometric-devices.fetch-logs', d.id, 'Fetching attendance logs...')}
                                  >
                                    <Download className="h-4 w-4 text-purple-600" />
                                  </Button>
                                </TooltipTrigger>
                                <TooltipContent>{t('Fetch attendance logs')}</TooltipContent>
                              </Tooltip>
                            )}
                          </>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TooltipProvider>

          {devices?.data?.length === 0 && (
            <div className="p-10 text-center space-y-2">
              <p className="text-sm text-muted-foreground">
                {hasActiveFilters
                  ? t('No biometric devices match the current filters.')
                  : t('No biometric devices have been registered yet.')}
              </p>
              {!hasActiveFilters && canManageDevices && (
                <Link href={route('hr.biometric-devices.index')}>
                  <Button size="sm" variant="outline">{t('Register Biometric Device')}</Button>
                </Link>
              )}
            </div>
          )}

          {devices?.total > 0 && (
            <Pagination
              from={devices.from}
              to={devices.to}
              total={devices.total}
              links={devices.links}
              entityName={t('devices')}
              onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })}
              currentPerPage={String(filters.per_page || 10)}
              onPerPageChange={(perPage) => applyFilters({ per_page: perPage })}
            />
          )}
        </CardContent>
      </Card>
    </PageTemplate>
  );
}
