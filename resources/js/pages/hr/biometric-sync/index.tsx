// pages/hr/biometric-sync/index.tsx
import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageTemplate } from '@/components/page-template';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Pagination } from '@/components/ui/pagination';
import { hasPermission } from '@/utils/authorization';
import { toast } from '@/components/custom-toast';
import {
  Activity, AlertTriangle, CalendarCheck2, CheckCircle2, Clock, Eye, Fingerprint,
  History, Lock, Moon, RefreshCw, RotateCcw, Search, ShieldAlert, XCircle,
} from 'lucide-react';
import { formatDateTime, RunStatusBadge, visitCallbacks } from '../biometric-devices/components/shared';
import { AuditTarget, RawLogsAuditDialog } from './components/raw-logs-audit-dialog';

function PunchTypeBadge({ type, label }: { type: string; label: string }) {
  const cls =
    type === 'in'
      ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
      : type === 'out'
        ? 'bg-blue-50 text-blue-700 border-blue-200'
        : 'bg-gray-100 text-gray-600 border-gray-200';
  return <Badge variant="outline" className={cls}>{label}</Badge>;
}

function ProcessingBadge({ status, label }: { status: string; label: string }) {
  const cls: Record<string, string> = {
    processed: 'bg-green-50 text-green-700 border-green-200',
    duplicate_suppressed: 'bg-slate-100 text-slate-600 border-slate-200',
    unmatched_employee: 'bg-amber-50 text-amber-700 border-amber-200',
    conflict_skipped: 'bg-purple-50 text-purple-700 border-purple-200',
    failed: 'bg-red-50 text-red-700 border-red-200',
    pending: 'bg-blue-50 text-blue-700 border-blue-200',
  };
  return <Badge variant="outline" className={cls[status] ?? ''}>{label}</Badge>;
}

function AttendanceStatusBadge({ status }: { status: string }) {
  const cls: Record<string, string> = {
    present: 'bg-green-50 text-green-700 border-green-200',
    half_day: 'bg-amber-50 text-amber-700 border-amber-200',
    absent: 'bg-red-50 text-red-700 border-red-200',
    on_leave: 'bg-blue-50 text-blue-700 border-blue-200',
    holiday: 'bg-purple-50 text-purple-700 border-purple-200',
  };
  return (
    <Badge variant="outline" className={`capitalize ${cls[status] ?? ''}`}>
      {status.replace('_', ' ')}
    </Badge>
  );
}

const PAIRING_LABELS: Record<string, string> = {
  first_last: 'First/Last',
  in_out_pairs: 'In/Out Pairs',
  device_state: 'Device State',
};

export default function BiometricSyncIndex() {
  const { t } = useTranslation();
  const { auth, processed, punches, failures, history, stats, options, filters = {} } = usePage().props as any;
  const permissions: string[] = auth?.permissions || [];
  const canReprocess = hasPermission(permissions, 'reprocess-biometric-punches') || hasPermission(permissions, 'sync-biometric-attendance');

  const [tab, setTab] = useState<string>(filters.tab || 'processed');
  const [search, setSearch] = useState<string>(filters.search || '');
  const [deviceId, setDeviceId] = useState<string>(filters.device_id || '_empty_');
  const [status, setStatus] = useState<string>(filters.status || '_empty_');
  const [source, setSource] = useState<string>(filters.source || '_empty_');
  const [date, setDate] = useState<string>(filters.date || '');
  const [failureStatus, setFailureStatus] = useState<string>(filters.failure_status || '_empty_');
  const [missingOnly, setMissingOnly] = useState<boolean>(filters.missing_only === '1');
  const [auditTarget, setAuditTarget] = useState<AuditTarget | null>(null);

  const apply = (extra: Record<string, any> = {}) => {
    router.get(route('hr.biometric-sync.index'), {
      tab,
      search: search || undefined,
      device_id: deviceId !== '_empty_' ? deviceId : undefined,
      status: status !== '_empty_' ? status : undefined,
      source: source !== '_empty_' ? source : undefined,
      date: date || undefined,
      failure_status: failureStatus !== '_empty_' ? failureStatus : undefined,
      missing_only: missingOnly ? '1' : undefined,
      ...extra,
    }, { preserveState: true, preserveScroll: true });
  };

  const first = useRef(true);
  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    apply();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [deviceId, status, source, date, failureStatus, missingOnly]);

  const reprocess = (id: number) => {
    toast.loading(t('Reprocessing attendance event...'));
    router.post(route('hr.biometric-sync.reprocess-punch', id), {}, visitCallbacks(t));
  };

  const recalculateRecord = (id: number) => {
    toast.loading(t('Recalculating daily attendance from raw biometric punches...'));
    router.post(route('hr.biometric-sync.recalculate', id), {}, visitCallbacks(t));
  };

  const detectMissingClockOuts = () => {
    toast.loading(t('Scanning open attendance records for missing clock-outs...'));
    router.post(route('hr.biometric-sync.detect-missing'), {}, visitCallbacks(t));
  };

  const retryFailure = (id: number) => {
    toast.loading(t('Retrying failed sync entry...'));
    router.post(route('hr.biometric-sync.retry-failure', id), {}, visitCallbacks(t));
  };

  const retryAll = () => {
    toast.loading(t('Retrying all pending failures...'));
    router.post(route('hr.biometric-sync.retry-all'), {}, visitCallbacks(t));
  };

  const dismissFailure = (id: number) => {
    router.post(route('hr.biometric-sync.dismiss-failure', id), {}, visitCallbacks(t));
  };

  const pageActions: any[] = [];
  if (canReprocess) {
    pageActions.push({
      label: t('Detect Missing Clock-Outs'),
      icon: <Clock className="h-4 w-4 mr-2" />,
      variant: 'outline' as const,
      onClick: detectMissingClockOuts,
    });
    if ((stats?.pending_failures ?? 0) > 0) {
      pageActions.push({
        label: t('Retry All Failed'),
        icon: <RotateCcw className="h-4 w-4 mr-2" />,
        variant: 'default' as const,
        onClick: retryAll,
      });
    }
  }

  return (
    <PageTemplate
      title={t('Attendance Synchronization & Processing')}
      description={t('Review processed daily attendance separately from raw biometric logs, monitor missing clock-outs and manage sync retries')}
      url="/hr/biometric-sync"
      actions={pageActions}
      breadcrumbs={[{ title: t('Dashboard'), href: route('dashboard') }, { title: t('Attendance') }, { title: t('Attendance Sync') }]}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        {[
          {
            label: t("Today's Synced Punches"),
            value: stats?.today_punches ?? 0,
            icon: Activity,
            cls: 'bg-blue-100 text-blue-600',
            onClick: () => setTab('punches'),
          },
          {
            label: t('Processed Events'),
            value: stats?.processed ?? 0,
            icon: CheckCircle2,
            cls: 'bg-green-100 text-green-600',
            onClick: () => setTab('processed'),
          },
          {
            label: t('Missing Clock-Outs'),
            value: stats?.missing_clock_outs ?? 0,
            icon: Clock,
            cls: 'bg-amber-100 text-amber-600',
            onClick: () => {
              setTab('processed');
              setMissingOnly(true);
            },
          },
          {
            label: t('Pending Sync Failures'),
            value: stats?.pending_failures ?? 0,
            icon: ShieldAlert,
            cls: 'bg-red-100 text-red-600',
            onClick: () => setTab('failures'),
          },
        ].map((c) => (
          <button
            key={c.label}
            type="button"
            onClick={c.onClick}
            className="text-left bg-white dark:bg-gray-800 rounded-xl border p-5 shadow-sm flex items-start justify-between hover:border-primary/50 transition-colors"
          >
            <div>
              <p className="text-sm font-medium text-muted-foreground mb-1">{c.label}</p>
              <p className="text-2xl font-bold">{c.value}</p>
            </div>
            <div className={`p-3 rounded-xl ${c.cls}`}><c.icon className="h-6 w-6" /></div>
          </button>
        ))}
      </div>

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList className="flex-wrap h-auto">
          <TabsTrigger value="processed">
            <CalendarCheck2 className="h-4 w-4 mr-1.5" /> {t('Processed Daily Attendance')}
            {(stats?.missing_clock_outs ?? 0) > 0 && (
              <span className="ml-1.5 rounded-full bg-amber-600 px-1.5 text-[10px] text-white">{stats.missing_clock_outs}</span>
            )}
          </TabsTrigger>
          <TabsTrigger value="punches">
            <Fingerprint className="h-4 w-4 mr-1.5" /> {t('Raw Biometric Logs')}
          </TabsTrigger>
          <TabsTrigger value="failures">
            <AlertTriangle className="h-4 w-4 mr-1.5" /> {t('Failed Sync Logs')}
            {(stats?.pending_failures ?? 0) > 0 && (
              <span className="ml-1.5 rounded-full bg-red-600 px-1.5 text-[10px] text-white">{stats.pending_failures}</span>
            )}
          </TabsTrigger>
          <TabsTrigger value="history">
            <History className="h-4 w-4 mr-1.5" /> {t('Device Sync History')}
          </TabsTrigger>
        </TabsList>

        {/* Tab 1: Processed Daily Attendance (separate from raw biometric logs) */}
        <TabsContent value="processed" className="space-y-4">
          <Card>
            <CardContent className="pt-4">
              <div className="flex flex-wrap items-center gap-3">
                <form onSubmit={(e) => { e.preventDefault(); apply({ tab: 'processed' }); }} className="flex items-center gap-2">
                  <div className="relative">
                    <Search className="h-4 w-4 absolute left-2.5 top-2.5 text-muted-foreground" />
                    <Input
                      className="pl-8 w-56 h-9"
                      value={search}
                      onChange={(e) => setSearch(e.target.value)}
                      placeholder={t('Employee name or email...')}
                    />
                  </div>
                  <Button type="submit" size="sm" variant="outline">{t('Search')}</Button>
                </form>
                <Input type="date" className="w-40 h-9" value={date} onChange={(e) => setDate(e.target.value)} />
                <div className="flex items-center gap-2 px-2">
                  <Checkbox
                    id="missing_only"
                    checked={missingOnly}
                    onCheckedChange={(v) => setMissingOnly(Boolean(v))}
                  />
                  <Label htmlFor="missing_only" className="text-sm font-normal cursor-pointer">
                    {t('Missing Clock-Out Only')}
                  </Label>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-0 overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('Employee')}</TableHead>
                    <TableHead>{t('Attendance Date & Shift')}</TableHead>
                    <TableHead>{t('Clock In / Out')}</TableHead>
                    <TableHead>{t('Hours / Break / OT')}</TableHead>
                    <TableHead>{t('Late / Early')}</TableHead>
                    <TableHead>{t('Status & Source')}</TableHead>
                    <TableHead className="text-right">{t('Actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {processed?.data?.map((r: any) => (
                    <TableRow key={r.id}>
                      <TableCell>
                        {r.employee_profile_id ? (
                          <Link href={route('hr.employees.show', r.employee_profile_id)} className="font-medium text-primary hover:underline">
                            {r.employee_name}
                          </Link>
                        ) : (
                          <span className="font-medium">{r.employee_name || '—'}</span>
                        )}
                        {r.employee_code && <div className="text-xs text-muted-foreground">{r.employee_code}</div>}
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        <div className="font-mono text-sm font-medium">{r.date}</div>
                        <div className="flex items-center gap-1.5 mt-0.5 text-xs text-muted-foreground">
                          <span>{r.shift_name || t('Default Shift')}</span>
                          {r.is_night_shift && (
                            <Badge variant="outline" className="text-[10px] px-1.5 py-0 bg-indigo-50 text-indigo-700 border-indigo-200">
                              <Moon className="h-2.5 w-2.5 mr-0.5" /> {t('Night')}
                            </Badge>
                          )}
                          <span className="text-[10px] px-1.5 py-0 rounded bg-muted">
                            {t(PAIRING_LABELS[r.pairing_mode] ?? r.pairing_mode)}
                          </span>
                        </div>
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        <div className="font-mono text-sm">
                          {r.clock_in || '—'} → {r.clock_out || '—'}
                        </div>
                        <div className="flex items-center gap-1.5 mt-0.5">
                          {r.is_missing_clock_out && (
                            <Badge variant="outline" className="text-[10px] bg-red-50 text-red-700 border-red-200">
                              <AlertTriangle className="h-2.5 w-2.5 mr-1" /> {t('Missing Clock-Out')}
                            </Badge>
                          )}
                          {r.punch_count > 0 && (
                            <span className="text-[11px] text-muted-foreground">
                              {r.punch_count} {r.punch_count === 1 ? t('punch') : t('punches')}
                            </span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="text-xs whitespace-nowrap">
                        <div className="font-medium">{Number(r.total_hours).toFixed(2)}h {t('work')}</div>
                        <div className="text-muted-foreground">
                          {t('Break')}: {Number(r.break_hours).toFixed(2)}h
                          {r.overtime_hours > 0 && (
                            <span className="text-emerald-700 font-medium ml-1.5">
                              +{Number(r.overtime_hours).toFixed(2)}h {t('OT')}
                            </span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="text-xs whitespace-nowrap">
                        {r.is_late ? (
                          <div className="text-amber-600 font-medium">{t('Late')}: {r.late_minutes}m</div>
                        ) : (
                          <div className="text-muted-foreground">{t('On time')}</div>
                        )}
                        {r.is_early_departure && (
                          <div className="text-orange-600 font-medium">{t('Early')}: {r.early_departure_minutes}m</div>
                        )}
                      </TableCell>
                      <TableCell>
                        <div className="flex flex-wrap items-center gap-1">
                          <AttendanceStatusBadge status={r.status} />
                          <Badge variant="secondary" className="text-[10px] capitalize">{t(r.source)}</Badge>
                          {r.is_manual_override && (
                            <Badge variant="outline" className="text-[10px] bg-purple-50 text-purple-700 border-purple-200" title={t('Protected from automatic biometric overwrites')}>
                              <Lock className="h-2.5 w-2.5 mr-0.5" /> {t('Override')}
                            </Badge>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="text-right whitespace-nowrap space-x-1">
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => setAuditTarget({ userId: r.user_id, date: r.date, employeeName: r.employee_name })}
                        >
                          <Eye className="h-3.5 w-3.5 mr-1" /> {t('Raw Logs')}
                        </Button>
                        {canReprocess && !r.is_manual_override && (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => recalculateRecord(r.id)}
                            title={t('Recalculate from raw biometric punches')}
                          >
                            <RefreshCw className="h-4 w-4 text-blue-600" />
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {processed?.data?.length === 0 && (
                <p className="p-8 text-center text-sm text-muted-foreground">
                  {t('No processed daily attendance records match the current filters.')}
                </p>
              )}
              {processed?.total > 0 && (
                <Pagination
                  from={processed.from}
                  to={processed.to}
                  total={processed.total}
                  links={processed.links}
                  entityName={t('records')}
                  onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })}
                  currentPerPage="15"
                  onPerPageChange={() => {}}
                  hidePerPage
                />
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Tab 2: Raw Biometric Logs (immutable hardware punches) */}
        <TabsContent value="punches" className="space-y-4">
          <Card>
            <CardContent className="pt-4">
              <div className="flex flex-wrap items-center gap-3">
                <form onSubmit={(e) => { e.preventDefault(); apply({ tab: 'punches' }); }} className="flex items-center gap-2">
                  <div className="relative">
                    <Search className="h-4 w-4 absolute left-2.5 top-2.5 text-muted-foreground" />
                    <Input className="pl-8 w-56 h-9" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Employee, PIN, log ID...')} />
                  </div>
                  <Button type="submit" size="sm" variant="outline">{t('Search')}</Button>
                </form>
                <Select value={deviceId} onValueChange={setDeviceId}>
                  <SelectTrigger className="w-48 h-9"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="_empty_">{t('All Devices')}</SelectItem>
                    {(options?.devices ?? []).map((d: any) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                  </SelectContent>
                </Select>
                <Select value={status} onValueChange={setStatus}>
                  <SelectTrigger className="w-48 h-9"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="_empty_">{t('All Statuses')}</SelectItem>
                    {(options?.processing_statuses ?? []).map((s: any) => <SelectItem key={s.value} value={s.value}>{t(s.label)}</SelectItem>)}
                  </SelectContent>
                </Select>
                <Select value={source} onValueChange={setSource}>
                  <SelectTrigger className="w-44 h-9"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="_empty_">{t('All Sources')}</SelectItem>
                    {(options?.sources ?? []).map((s: any) => <SelectItem key={s.value} value={s.value}>{t(s.label)}</SelectItem>)}
                  </SelectContent>
                </Select>
                <Input type="date" className="w-40 h-9" value={date} onChange={(e) => setDate(e.target.value)} />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-0 overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('Employee')}</TableHead>
                    <TableHead>{t('Device / User ID')}</TableHead>
                    <TableHead>{t('Punch Timestamp')}</TableHead>
                    <TableHead>{t('Type / Verify')}</TableHead>
                    <TableHead>{t('Source / Orig Log ID')}</TableHead>
                    <TableHead>{t('Attendance Date')}</TableHead>
                    <TableHead>{t('Status')}</TableHead>
                    <TableHead className="text-right">{t('Actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {punches?.data?.map((p: any) => (
                    <TableRow key={p.id}>
                      <TableCell>
                        {p.employee_id ? (
                          <Link href={route('hr.employees.show', p.employee_id)} className="font-medium text-primary hover:underline">
                            {p.employee_name || p.employee_code}
                          </Link>
                        ) : <span className="text-xs text-amber-600 font-medium">{t('Unmatched')}</span>}
                        {p.employee_code && <div className="text-xs text-muted-foreground">{p.employee_code}</div>}
                      </TableCell>
                      <TableCell>
                        <Link href={route('hr.biometric-devices.show', p.device_id)} className="text-sm font-medium hover:underline">{p.device_name}</Link>
                        <div className="text-xs font-mono text-muted-foreground">PIN: {p.device_user_id}</div>
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        <div className="text-sm font-mono">{p.punched_at_local}</div>
                        <div className="text-[11px] text-muted-foreground">{p.device_timezone} · {t('Sync')}: {formatDateTime(p.synced_at)}</div>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-1.5">
                          <PunchTypeBadge type={p.punch_type} label={t(p.punch_type_label)} />
                          <span className="text-xs text-muted-foreground">{t(p.verification_method_label)}</span>
                        </div>
                      </TableCell>
                      <TableCell className="text-xs">
                        <div>{t(p.source_label)}</div>
                        <div className="font-mono text-muted-foreground">{p.original_device_log_id || '—'}</div>
                      </TableCell>
                      <TableCell className="text-xs font-mono">{p.attendance_date || '—'}</TableCell>
                      <TableCell>
                        <ProcessingBadge status={p.processing_status} label={t(p.processing_status_label)} />
                        {p.processing_note && <div className="text-[11px] text-muted-foreground mt-0.5 max-w-xs">{p.processing_note}</div>}
                      </TableCell>
                      <TableCell className="text-right whitespace-nowrap space-x-1">
                        {p.user_id && p.attendance_date && (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setAuditTarget({ userId: p.user_id, date: p.attendance_date, employeeName: p.employee_name })}
                            title={t('Compare raw logs vs processed daily attendance')}
                          >
                            <Eye className="h-4 w-4 text-slate-600" />
                          </Button>
                        )}
                        {canReprocess && (
                          <Button size="sm" variant="ghost" onClick={() => reprocess(p.id)} title={t('Reprocess event')}>
                            <RefreshCw className="h-4 w-4 text-blue-600" />
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {punches?.data?.length === 0 && <p className="p-8 text-center text-sm text-muted-foreground">{t('No synchronized biometric attendance events found.')}</p>}
              {punches?.total > 0 && (
                <Pagination
                  from={punches.from} to={punches.to} total={punches.total} links={punches.links} entityName={t('events')}
                  onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })}
                  currentPerPage="20" onPerPageChange={() => {}} hidePerPage
                />
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Tab 3: Failed Sync Logs */}
        <TabsContent value="failures">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
              <CardTitle className="text-base">{t('Failed Synchronization Logs')}</CardTitle>
              <Select value={failureStatus} onValueChange={setFailureStatus}>
                <SelectTrigger className="w-44 h-8"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="_empty_">{t('All Statuses')}</SelectItem>
                  {(options?.failure_statuses ?? []).map((s: any) => <SelectItem key={s.value} value={s.value}>{t(s.label)}</SelectItem>)}
                </SelectContent>
              </Select>
            </CardHeader>
            <CardContent className="p-0 overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('Device / Source')}</TableHead>
                    <TableHead>{t('Device User ID / Time')}</TableHead>
                    <TableHead>{t('Error')}</TableHead>
                    <TableHead>{t('Retries / Next Retry')}</TableHead>
                    <TableHead>{t('Status')}</TableHead>
                    <TableHead className="text-right">{t('Actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {failures?.data?.map((f: any) => (
                    <TableRow key={f.id}>
                      <TableCell>
                        <div className="font-medium text-sm">{f.device_name || t('Unknown device')}</div>
                        <div className="text-xs text-muted-foreground">{t(f.source)}{f.agent_name ? ` · ${f.agent_name}` : ''}</div>
                      </TableCell>
                      <TableCell className="font-mono text-xs">
                        <div>PIN: {f.device_user_id || '—'}</div>
                        <div className="text-muted-foreground">{f.punched_at_raw || '—'}</div>
                      </TableCell>
                      <TableCell className="max-w-md">
                        <Badge variant="outline" className="text-[10px] font-mono mb-1">{f.error_code}</Badge>
                        <div className="text-xs text-red-600">{f.error_message}</div>
                      </TableCell>
                      <TableCell className="text-xs whitespace-nowrap">
                        <div>{t('Attempts')}: {f.retry_count}</div>
                        <div className="text-muted-foreground">{f.next_retry_at ? formatDateTime(f.next_retry_at) : '—'}</div>
                      </TableCell>
                      <TableCell>
                        <Badge variant="outline" className={f.status === 'resolved' ? 'bg-green-50 text-green-700' : f.status === 'pending_retry' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-500'}>
                          {t(f.status_label)}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right whitespace-nowrap space-x-1">
                        {canReprocess && f.status !== 'resolved' && (
                          <>
                            <Button size="sm" variant="outline" onClick={() => retryFailure(f.id)}>
                              <RotateCcw className="h-3.5 w-3.5 mr-1" /> {t('Retry')}
                            </Button>
                            {f.status === 'pending_retry' && (
                              <Button size="sm" variant="ghost" onClick={() => dismissFailure(f.id)} title={t('Dismiss')}>
                                <XCircle className="h-4 w-4 text-gray-500" />
                              </Button>
                            )}
                          </>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {failures?.data?.length === 0 && <p className="p-8 text-center text-sm text-muted-foreground">{t('No failed synchronization logs.')}</p>}
              {failures?.total > 0 && (
                <Pagination
                  from={failures.from} to={failures.to} total={failures.total} links={failures.links} entityName={t('failures')}
                  onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })}
                  currentPerPage="15" onPerPageChange={() => {}} hidePerPage
                />
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Tab 4: Device-Wise Sync History */}
        <TabsContent value="history">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">{t('Device-Wise Attendance Synchronization History')}</CardTitle>
            </CardHeader>
            <CardContent className="p-0 overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('Device')}</TableHead>
                    <TableHead>{t('Action / Trigger')}</TableHead>
                    <TableHead>{t('Status')}</TableHead>
                    <TableHead>{t('Summary')}</TableHead>
                    <TableHead>{t('Started')}</TableHead>
                    <TableHead>{t('Finished')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {history?.data?.map((r: any) => (
                    <TableRow key={r.id}>
                      <TableCell>
                        <Link href={route('hr.biometric-devices.show', r.device_id)} className="font-medium text-primary hover:underline">{r.device_name}</Link>
                        <div className="text-xs font-mono text-muted-foreground">{r.device_serial}</div>
                      </TableCell>
                      <TableCell className="text-sm">
                        <div>{t(r.action_label)}</div>
                        <div className="text-xs text-muted-foreground">{r.trigger}</div>
                      </TableCell>
                      <TableCell><RunStatusBadge status={r.status} /></TableCell>
                      <TableCell className="text-xs max-w-md">{r.message || '—'}</TableCell>
                      <TableCell className="text-xs whitespace-nowrap">{formatDateTime(r.started_at)}</TableCell>
                      <TableCell className="text-xs whitespace-nowrap">{formatDateTime(r.finished_at)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {history?.data?.length === 0 && <p className="p-8 text-center text-sm text-muted-foreground">{t('No device synchronization history recorded yet.')}</p>}
              {history?.total > 0 && (
                <Pagination
                  from={history.from} to={history.to} total={history.total} links={history.links} entityName={t('runs')}
                  onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })}
                  currentPerPage="15" onPerPageChange={() => {}} hidePerPage
                />
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <RawLogsAuditDialog
        target={auditTarget}
        canReprocess={canReprocess}
        onClose={() => setAuditTarget(null)}
      />
    </PageTemplate>
  );
}
