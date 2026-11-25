// pages/hr/biometric-devices/show.tsx
import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageTemplate } from '@/components/page-template';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Pagination } from '@/components/ui/pagination';
import { Input } from '@/components/ui/input';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { hasPermission } from '@/utils/authorization';
import { toast } from '@/components/custom-toast';
import {
  Activity, AlertCircle, ArrowLeft, Clock, Copy, Download, Edit, Eraser, Fingerprint, Info, KeyRound, Lock, MoreHorizontal, Power, RefreshCw, Trash2, UserMinus, Users, Wifi, XCircle, CheckCircle2, ShieldQuestion, MinusCircle,
} from 'lucide-react';
import { DeviceFormDialog } from './components/device-form-dialog';
import { ApiKeyDialog, DeleteDeviceDialog, FetchLogsDialog, SyncEmployeesDialog } from './components/action-dialogs';
import {
  ActiveBadge, CapabilityBadges, CommandStatusBadge, ConnectionBadge, DeviceOptions, DriverMeta, EventLevelIcon, RunStatusBadge, formatDateTime, timeAgo, visitCallbacks,
} from './components/shared';

type ActionKey = 'connectivity' | 'time_sync' | 'employee_sync' | 'log_fetch' | 'device_info' | 'user_fetch' | 'user_delete' | 'log_clear' | 'restart';

function Detail({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={`text-sm mt-0.5 break-words ${mono ? 'font-mono' : ''}`}>{value || value === 0 ? value : '—'}</dd>
    </div>
  );
}

export default function BiometricDeviceShow() {
  const { t } = useTranslation();
  const {
    auth, device, driver, setupHints, availableActions, lastSuccess, syncRuns, events, errorCount, pendingCommands, recentCommands,
    employeeSummary, issuedApiKey, filters = {}, options, deviceInfo, deviceUsers,
  } = usePage().props as any;
  const permissions: string[] = auth?.permissions || [];
  const opts = options as DeviceOptions;
  const drv = driver as DriverMeta | undefined;

  const [tab, setTab] = useState<string>(() => (filters.run_action || filters.run_status ? 'history' : filters.event_level ? 'events' : filters.user_search ? 'users' : 'overview'));
  const [userSearch, setUserSearch] = useState(filters.user_search || '');
  const [editOpen, setEditOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [syncEmployeesOpen, setSyncEmployeesOpen] = useState(false);
  const [fetchLogsOpen, setFetchLogsOpen] = useState(false);
  const [apiKey, setApiKey] = useState<string | null>(null);
  const [busy, setBusy] = useState<ActionKey | null>(null);

  useEffect(() => {
    if (issuedApiKey) setApiKey(issuedApiKey);
  }, [issuedApiKey]);

  // While commands are in flight, refresh the live parts of the page.
  const hasRunning = (syncRuns?.data ?? []).some((r: any) => ['queued', 'running'].includes(r.status));
  useEffect(() => {
    if (!hasRunning && !pendingCommands) return;
    const id = window.setInterval(() => {
      router.reload({ only: ['device', 'syncRuns', 'events', 'lastSuccess', 'pendingCommands', 'recentCommands', 'errorCount', 'availableActions', 'deviceInfo', 'deviceUsers', 'deviceStatus'] });
    }, 10000);
    return () => window.clearInterval(id);
  }, [hasRunning, pendingCommands]);

  const canSync = hasPermission(permissions, 'sync-biometric-devices');
  const canEdit = hasPermission(permissions, 'edit-biometric-devices');
  const canControl = hasPermission(permissions, 'execute-biometric-commands') || hasPermission(permissions, 'control-biometric-devices');

  const run = (key: ActionKey, routeName: string, loading: string, data: Record<string, any> = {}) => {
    setBusy(key);
    toast.loading(loading);
    router.post(route(routeName, device.id), data, { ...visitCallbacks(t), onFinish: () => setBusy(null) });
  };

  const confirmRun = (key: ActionKey, routeName: string, question: string, loading: string, data: Record<string, any> = {}) => {
    if (window.confirm(question)) run(key, routeName, loading, data);
  };

  const enabled = (key: ActionKey) => availableActions?.[key]?.enabled !== false;
  const reason = (key: ActionKey) => availableActions?.[key]?.reason as string | undefined;

  const toggleStatus = () => {
    toast.loading(device.is_active ? t('Deactivating device...') : t('Activating device...'));
    router.put(route('hr.biometric-devices.toggle-status', device.id), {}, visitCallbacks(t));
  };

  const regenerateKey = () => {
    if (!window.confirm(t('Generate a new API key? The current key will stop working immediately.'))) return;
    router.post(route('hr.biometric-devices.api-key', device.id), {}, visitCallbacks(t));
  };

  const cancelCommands = () => {
    if (!window.confirm(t('Cancel all pending commands for this device?'))) return;
    router.post(route('hr.biometric-devices.cancel-commands', device.id), {}, visitCallbacks(t));
  };

  const filterShow = (extra: Record<string, any>) =>
    router.get(route('hr.biometric-devices.show', device.id), { ...filters, ...extra }, { preserveState: true, preserveScroll: true, only: ['syncRuns', 'events', 'filters'] });

  const actionButtons: { key: ActionKey; label: string; icon: any; onClick: () => void }[] = [
    { key: 'connectivity', label: t('Check Connectivity'), icon: Wifi, onClick: () => run('connectivity', 'hr.biometric-devices.connectivity', t('Checking connectivity...')) },
    { key: 'time_sync', label: t('Sync Time'), icon: Clock, onClick: () => run('time_sync', 'hr.biometric-devices.sync-time', t('Queuing time sync...')) },
    { key: 'employee_sync', label: t('Sync Employees'), icon: Users, onClick: () => setSyncEmployeesOpen(true) },
    { key: 'log_fetch', label: t('Fetch Logs'), icon: Download, onClick: () => setFetchLogsOpen(true) },
    { key: 'device_info', label: t('Refresh Info'), icon: Info, onClick: () => run('device_info', 'hr.biometric-devices.refresh-info', t('Requesting device information...')) },
  ];

  const moreActions: { key: ActionKey; label: string; icon: any; danger?: boolean; show: boolean; onClick: () => void }[] = [
    { key: 'user_fetch', label: t('Fetch Users From Device'), icon: Users, show: canSync, onClick: () => run('user_fetch', 'hr.biometric-devices.fetch-users', t('Requesting user list...')) },
    { key: 'log_clear', label: t('Clear Device Logs'), icon: Eraser, danger: true, show: canControl, onClick: () => confirmRun('log_clear', 'hr.biometric-devices.clear-logs', t('Delete ALL attendance logs stored on the device? Make sure they were fetched first. This cannot be undone.'), t('Clearing logs...')) },
    { key: 'restart', label: t('Restart Device'), icon: Power, danger: true, show: canControl, onClick: () => confirmRun('restart', 'hr.biometric-devices.restart', t('Restart the device? It will be unavailable for a few minutes.'), t('Sending restart...')) },
  ];

  const pageActions = [
    { label: t('Back'), icon: <ArrowLeft className="h-4 w-4 mr-2" />, variant: 'outline' as const, onClick: () => router.get(route('hr.biometric-devices.index')) },
    ...(canEdit ? [
      { label: t('Edit'), icon: <Edit className="h-4 w-4 mr-2" />, variant: 'outline' as const, onClick: () => setEditOpen(true) },
      { label: device.is_active ? t('Deactivate') : t('Activate'), icon: <Lock className="h-4 w-4 mr-2" />, variant: 'outline' as const, onClick: toggleStatus },
    ] : []),
    ...(hasPermission(permissions, 'delete-biometric-devices')
      ? [{ label: t('Delete'), icon: <Trash2 className="h-4 w-4 mr-2" />, variant: 'destructive' as const, onClick: () => setDeleteOpen(true) }]
      : []),
  ];

  const lastLogFetchTo = syncRuns?.data?.find((r: any) => r.action === 'log_fetch' && ['success', 'partial'].includes(r.status))?.parameters?.to ?? null;

  const lastCards: { key: ActionKey; label: string; icon: any }[] = [
    { key: 'connectivity', label: t('Connectivity Check'), icon: Wifi },
    { key: 'time_sync', label: t('Time Sync'), icon: Clock },
    { key: 'employee_sync', label: t('Employee Sync'), icon: Users },
    { key: 'log_fetch', label: t('Log Fetch'), icon: Download },
  ];

  return (
    <PageTemplate
      title={device.name}
      description={`${device.serial_number} · ${device.device_identifier}`}
      url={`/hr/biometric-devices/${device.id}`}
      actions={pageActions}
      breadcrumbs={[
        { title: t('Dashboard'), href: route('dashboard') },
        { title: t('Biometric Devices'), href: route('hr.biometric-devices.index') },
        { title: device.name },
      ]}
    >
      <TooltipProvider>
        {/* Status + actions */}
        <Card>
          <CardContent className="pt-6">
            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
              <div className="flex items-center gap-4">
                <div className="p-3 rounded-xl bg-primary/10 text-primary"><Fingerprint className="h-7 w-7" /></div>
                <div className="space-y-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <ConnectionBadge status={device.connection_status} />
                    <ActiveBadge active={device.is_active} />
                    <span className="text-xs text-muted-foreground">{drv?.label}</span>
                  </div>
                  <div className="text-xs text-muted-foreground">
                    {device.last_seen_at ? `${t('Last contact')} ${timeAgo(device.last_seen_at)} ${device.last_seen_ip ? `${t('from')} ${device.last_seen_ip}` : ''}` : t('Never connected')}
                    {' · '}
                    {t('Last successful sync')}: {formatDateTime(device.last_successful_sync_at)}
                  </div>
                </div>
              </div>
              {canSync && (
                <div className="flex flex-wrap gap-2">
                  {actionButtons.map((a) => {
                    const state = availableActions?.[a.key] ?? { enabled: true };
                    const btn = (
                      <Button key={a.key} variant="outline" size="sm" disabled={!state.enabled || busy !== null} onClick={a.onClick}>
                        {busy === a.key ? <RefreshCw className="h-4 w-4 mr-2 animate-spin" /> : <a.icon className="h-4 w-4 mr-2" />}
                        {a.label}
                      </Button>
                    );
                    return state.enabled ? btn : (
                      <Tooltip key={a.key}>
                        <TooltipTrigger asChild><span>{btn}</span></TooltipTrigger>
                        <TooltipContent className="max-w-xs">{state.reason}</TooltipContent>
                      </Tooltip>
                    );
                  })}
                  {moreActions.some((m) => m.show) && (
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="outline" size="sm" disabled={busy !== null}><MoreHorizontal className="h-4 w-4" /></Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end" className="w-60">
                        {moreActions.filter((m) => m.show).map((m, i) => (
                          <div key={m.key}>
                            {m.danger && i > 0 && !moreActions[i - 1]?.danger && <DropdownMenuSeparator />}
                            <DropdownMenuItem disabled={!enabled(m.key)} onClick={m.onClick} className={m.danger ? 'text-red-600' : ''}>
                              <m.icon className="h-4 w-4 mr-2" />
                              <div>
                                <div>{m.label}</div>
                                {!enabled(m.key) && <div className="text-[10px] text-muted-foreground max-w-[200px] whitespace-normal">{reason(m.key)}</div>}
                              </div>
                            </DropdownMenuItem>
                          </div>
                        ))}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  )}
                </div>
              )}
            </div>

            {(pendingCommands > 0 || device.last_error) && (
              <div className="mt-4 grid gap-3 md:grid-cols-2">
                {pendingCommands > 0 && (
                  <Alert>
                    <RefreshCw className="h-4 w-4" />
                    <AlertTitle>{pendingCommands} {t('command(s) waiting for the device')}</AlertTitle>
                    <AlertDescription className="flex items-center justify-between gap-2">
                      <span>{t('They are delivered the next time the device polls the server.')}</span>
                      {canSync && <Button size="sm" variant="ghost" onClick={cancelCommands}><XCircle className="h-4 w-4 mr-1" />{t('Cancel')}</Button>}
                    </AlertDescription>
                  </Alert>
                )}
                {device.last_error && (
                  <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>{t('Last error')} · {formatDateTime(device.last_error_at)}</AlertTitle>
                    <AlertDescription>{device.last_error}</AlertDescription>
                  </Alert>
                )}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Last successful synchronization per action */}
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
          {lastCards.map((c) => {
            const last = lastSuccess?.[c.key];
            return (
              <div key={c.key} className="bg-white dark:bg-gray-800 rounded-xl border p-4 shadow-sm">
                <div className="flex items-center gap-2 text-sm font-medium text-gray-500"><c.icon className="h-4 w-4" />{c.label}</div>
                <div className="mt-2 text-sm font-semibold">{last ? formatDateTime(last.finished_at) : t('Never succeeded')}</div>
                {last?.message && <div className="text-xs text-muted-foreground mt-1 line-clamp-2">{last.message}</div>}
              </div>
            );
          })}
        </div>

        <Tabs value={tab} onValueChange={setTab}>
          <TabsList>
            <TabsTrigger value="overview">{t('Overview')}</TabsTrigger>
            <TabsTrigger value="history">{t('Sync History')}</TabsTrigger>
            <TabsTrigger value="events">
              {t('Errors & Events')}
              {errorCount > 0 && <span className="ml-1.5 rounded-full bg-red-500 px-1.5 text-[10px] text-white">{errorCount}</span>}
            </TabsTrigger>
            <TabsTrigger value="users">{t('Device Users')} <span className="ml-1 text-xs text-muted-foreground">{deviceUsers?.total ?? 0}</span></TabsTrigger>
            <TabsTrigger value="commands">{t('Commands')}</TabsTrigger>
            <TabsTrigger value="setup">{t('Setup')}</TabsTrigger>
          </TabsList>

          {/* Overview */}
          <TabsContent value="overview" className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader><CardTitle className="text-base">{t('Device Identity')}</CardTitle></CardHeader>
              <CardContent>
                <dl className="grid grid-cols-2 gap-4">
                  <Detail label={t('Device Name')} value={device.name} />
                  <Detail label={t('Device Type')} value={t(device.device_type_label)} />
                  <Detail label={t('Serial Number')} value={device.serial_number} mono />
                  <Detail label={t('Device Identifier')} value={device.device_identifier} mono />
                  <Detail label={t('Manufacturer')} value={device.manufacturer} />
                  <Detail label={t('Model')} value={device.model} />
                  <Detail label={t('Firmware')} value={device.firmware_version} />
                  <Detail label={t('Registered')} value={`${formatDateTime(device.created_at)}${device.creator ? ' · ' + device.creator : ''}`} />
                </dl>
              </CardContent>
            </Card>
            <Card>
              <CardHeader><CardTitle className="text-base">{t('Location & Communication')}</CardTitle></CardHeader>
              <CardContent>
                <dl className="grid grid-cols-2 gap-4">
                  <Detail label={t('Branch / Location')} value={device.branch ?? t('All branches')} />
                  <Detail label={t('Department')} value={device.department ?? t('All departments')} />
                  <Detail label={t('Communication Protocol')} value={drv?.label ?? device.protocol} />
                  <Detail label={t('Connection Type')} value={t(device.connection_type_label)} />
                  <Detail label={t('IP Address')} value={device.ip_address} mono />
                  <Detail label={t('Port')} value={device.port} mono />
                  <Detail label={t('Timezone')} value={device.timezone_effective} />
                  <Detail label={t('Authentication')} value={t(device.auth_type_label)} />
                  <Detail
                    label={t('Local Agent')}
                    value={device.agent_id
                      ? <Link href={route('hr.biometric-agents.show', device.agent_id)} className="text-primary hover:underline">{device.agent_name} ({device.agent_identifier})</Link>
                      : t('Direct (none)')}
                  />
                </dl>
                <div className="mt-4"><CapabilityBadges driver={drv} /></div>
              </CardContent>
            </Card>
            <Card className="lg:col-span-2">
              <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <CardTitle className="text-base">{t('Reported Device Information')}</CardTitle>
                <span className="text-xs text-muted-foreground">{deviceInfo?.reported_at ? `${t('Reported')} ${formatDateTime(deviceInfo.reported_at)}` : t('Not reported yet — use “Refresh Info”.')}</span>
              </CardHeader>
              <CardContent>
                <dl className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <Detail label={t('Model')} value={deviceInfo?.model} />
                  <Detail label={t('Firmware')} value={deviceInfo?.firmware_version} />
                  <Detail label={t('Users on Device')} value={deviceInfo?.user_count} />
                  <Detail label={t('Logs on Device')} value={deviceInfo?.log_count} />
                  <Detail label={t('Fingerprint Templates')} value={deviceInfo?.fingerprint_count} />
                  <Detail label={t('Face Templates')} value={deviceInfo?.face_count} />
                  <Detail label={t('Manufacturer')} value={deviceInfo?.manufacturer} />
                  <Detail label={t('Serial (reported)')} value={deviceInfo?.serial_number} mono />
                </dl>
              </CardContent>
            </Card>
            <Card className="lg:col-span-2">
              <CardHeader><CardTitle className="text-base">{t('Runtime')}</CardTitle></CardHeader>
              <CardContent>
                <dl className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  <Detail label={t('Connection Status')} value={<ConnectionBadge status={device.connection_status} />} />
                  <Detail label={t('Last Contact')} value={formatDateTime(device.last_seen_at)} />
                  <Detail label={t('Last Log Received')} value={formatDateTime(device.last_log_received_at)} />
                  <Detail label={t('Last Connectivity Check')} value={formatDateTime(device.last_connectivity_check_at)} />
                  <Detail label={t('Last Synchronization')} value={formatDateTime(device.last_sync_at)} />
                  <Detail label={t('Last Successful Synchronization')} value={formatDateTime(device.last_successful_sync_at)} />
                  <Detail label={t('Employees in Scope')} value={`${employeeSummary?.eligible ?? 0} / ${employeeSummary?.in_scope ?? 0}`} />
                  <Detail label={t('Pending Commands')} value={pendingCommands} />
                </dl>
                {device.notes && <p className="mt-4 text-sm text-muted-foreground whitespace-pre-line">{device.notes}</p>}
              </CardContent>
            </Card>
          </TabsContent>

          {/* Sync history */}
          <TabsContent value="history">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
                <CardTitle className="text-base">{t('Synchronization History')}</CardTitle>
                <div className="flex gap-2">
                  <Select value={filters.run_action || '_empty_'} onValueChange={(v) => filterShow({ run_action: v, runs_page: 1 })}>
                    <SelectTrigger className="w-44 h-8"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="_empty_">{t('All actions')}</SelectItem>
                      {opts.sync_actions.map((o) => <SelectItem key={o.value} value={o.value}>{t(o.label)}</SelectItem>)}
                    </SelectContent>
                  </Select>
                  <Select value={filters.run_status || '_empty_'} onValueChange={(v) => filterShow({ run_status: v, runs_page: 1 })}>
                    <SelectTrigger className="w-36 h-8"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="_empty_">{t('All statuses')}</SelectItem>
                      {opts.sync_statuses.map((o) => <SelectItem key={o.value} value={o.value}>{t(o.label)}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
              </CardHeader>
              <CardContent className="p-0">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>{t('Action')}</TableHead>
                      <TableHead>{t('Status')}</TableHead>
                      <TableHead>{t('Progress')}</TableHead>
                      <TableHead>{t('Message')}</TableHead>
                      <TableHead>{t('Started')}</TableHead>
                      <TableHead>{t('Finished')}</TableHead>
                      <TableHead>{t('By')}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {syncRuns?.data?.map((r: any) => (
                      <TableRow key={r.id}>
                        <TableCell className="font-medium">{t(r.action_label)}</TableCell>
                        <TableCell><RunStatusBadge status={r.status} /></TableCell>
                        <TableCell className="text-sm">
                          {r.action === 'connectivity' ? '—' : (
                            <span>
                              <span className="text-green-600">{r.success_items}</span>
                              {r.failed_items > 0 && <span className="text-red-600"> / {r.failed_items} {t('failed')}</span>}
                              <span className="text-muted-foreground"> {t('of')} {r.total_items}</span>
                            </span>
                          )}
                        </TableCell>
                        <TableCell className="max-w-md">
                          <div className="text-sm">{r.message}</div>
                          {r.error && r.error !== r.message && <div className="text-xs text-red-600">{r.error}</div>}
                          {r.action === 'connectivity' && r.result?.checks && (
                            <ul className="mt-1 space-y-0.5">
                              {r.result.checks.map((c: any) => (
                                <li key={c.name} className="text-xs text-muted-foreground">
                                  {c.passed === true ? '✓' : c.passed === false ? '✗' : '–'} {t(c.label)}: {c.detail}
                                </li>
                              ))}
                            </ul>
                          )}
                        </TableCell>
                        <TableCell className="text-xs whitespace-nowrap">{formatDateTime(r.started_at)}</TableCell>
                        <TableCell className="text-xs whitespace-nowrap">{r.finished_at ? formatDateTime(r.finished_at) : '—'}</TableCell>
                        <TableCell className="text-xs">{r.initiated_by ?? t(r.trigger)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                {syncRuns?.data?.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">{t('No synchronization history yet.')}</p>}
                {syncRuns?.total > 0 && (
                  <Pagination
                    from={syncRuns.from} to={syncRuns.to} total={syncRuns.total} links={syncRuns.links} entityName={t('runs')}
                    onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true, only: ['syncRuns'] })}
                    currentPerPage="10" onPerPageChange={() => {}} hidePerPage
                  />
                )}
              </CardContent>
            </Card>
          </TabsContent>

          {/* Events */}
          <TabsContent value="events">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
                <CardTitle className="text-base">{t('Device Errors & Events')}</CardTitle>
                <Select value={filters.event_level || '_empty_'} onValueChange={(v) => filterShow({ event_level: v, events_page: 1 })}>
                  <SelectTrigger className="w-36 h-8"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="_empty_">{t('All levels')}</SelectItem>
                    {opts.event_levels.map((o) => <SelectItem key={o.value} value={o.value}>{t(o.label)}</SelectItem>)}
                  </SelectContent>
                </Select>
              </CardHeader>
              <CardContent className="p-0">
                <ul className="divide-y">
                  {events?.data?.map((e: any) => (
                    <li key={e.id} className="flex items-start gap-3 px-6 py-3">
                      <EventLevelIcon level={e.level} />
                      <div className="flex-1">
                        <div className="text-sm">{e.message}</div>
                        <div className="text-xs text-muted-foreground">
                          <span className="font-mono">{e.type}</span> · {formatDateTime(e.occurred_at)}
                        </div>
                      </div>
                    </li>
                  ))}
                </ul>
                {events?.data?.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">{t('No events recorded.')}</p>}
                {events?.total > 0 && (
                  <Pagination
                    from={events.from} to={events.to} total={events.total} links={events.links} entityName={t('events')}
                    onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true, only: ['events'] })}
                    currentPerPage="10" onPerPageChange={() => {}} hidePerPage
                  />
                )}
              </CardContent>
            </Card>
          </TabsContent>

          {/* Device users */}
          <TabsContent value="users">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
                <div>
                  <CardTitle className="text-base">{t('Users Stored on Device')}</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">{t('Mirror built from device reports and acknowledged enrollments. Use “Fetch Users From Device” to refresh.')}</p>
                </div>
                <form onSubmit={(e) => { e.preventDefault(); router.get(route('hr.biometric-devices.show', device.id), { ...filters, user_search: userSearch || undefined, users_page: 1 }, { preserveState: true, preserveScroll: true, only: ['deviceUsers', 'filters'] }); }}>
                  <Input className="h-8 w-56" placeholder={t('Search PIN or name...')} value={userSearch} onChange={(e) => setUserSearch(e.target.value)} />
                </form>
              </CardHeader>
              <CardContent className="p-0">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>{t('PIN / Code')}</TableHead>
                      <TableHead>{t('Name on Device')}</TableHead>
                      <TableHead>{t('Linked Employee')}</TableHead>
                      <TableHead>{t('Card')}</TableHead>
                      <TableHead>{t('Role')}</TableHead>
                      <TableHead>{t('Source')}</TableHead>
                      <TableHead>{t('Last Reported')}</TableHead>
                      <TableHead className="text-right">{t('Actions')}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {deviceUsers?.data?.map((u: any) => (
                      <TableRow key={u.id}>
                        <TableCell className="font-mono text-sm">{u.pin}</TableCell>
                        <TableCell className="text-sm">{u.name || '—'}</TableCell>
                        <TableCell className="text-sm">
                          {u.employee ? <span>{u.employee} <span className="text-xs text-muted-foreground">{u.employee_code}</span></span> : <span className="text-xs text-amber-600">{t('Not linked')}</span>}
                        </TableCell>
                        <TableCell className="text-xs font-mono">{u.card || '—'}</TableCell>
                        <TableCell className="text-xs">{u.privilege > 0 ? t('Admin') : t('User')}</TableCell>
                        <TableCell className="text-xs">{u.source === 'sync' ? t('Synced from HRMS') : t('Reported by device')}</TableCell>
                        <TableCell className="text-xs">{formatDateTime(u.last_reported_at)}</TableCell>
                        <TableCell className="text-right">
                          {canControl && (
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <span>
                                  <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-red-500" disabled={!enabled('user_delete') || busy !== null}
                                    onClick={() => confirmRun('user_delete', 'hr.biometric-devices.remove-user', t('Remove user :pin from the device? Their fingerprints/face stored on the device will be deleted.').replace(':pin', u.pin), t('Removing user...'), { pin: u.pin })}>
                                    <UserMinus className="h-4 w-4" />
                                  </Button>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent className="max-w-xs">{enabled('user_delete') ? t('Remove from device') : reason('user_delete')}</TooltipContent>
                            </Tooltip>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                {deviceUsers?.data?.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">{t('No users known for this device yet.')}</p>}
                {deviceUsers?.total > 0 && (
                  <Pagination
                    from={deviceUsers.from} to={deviceUsers.to} total={deviceUsers.total} links={deviceUsers.links} entityName={t('users')}
                    onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true, only: ['deviceUsers'] })}
                    currentPerPage="15" onPerPageChange={() => {}} hidePerPage
                  />
                )}
              </CardContent>
            </Card>
          </TabsContent>

          {/* Commands */}
          <TabsContent value="commands">
            <Card>
              <CardHeader><CardTitle className="text-base">{t('Recent Commands')}</CardTitle></CardHeader>
              <CardContent className="p-0">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>#</TableHead>
                      <TableHead>{t('Command')}</TableHead>
                      <TableHead>{t('Employee')}</TableHead>
                      <TableHead>{t('Status')}</TableHead>
                      <TableHead>{t('Queued')}</TableHead>
                      <TableHead>{t('Sent')}</TableHead>
                      <TableHead>{t('Acknowledged')}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {recentCommands?.map((c: any) => (
                      <TableRow key={c.id}>
                        <TableCell className="text-xs text-muted-foreground">{c.id}</TableCell>
                        <TableCell className="text-sm">{t(c.type_label)}</TableCell>
                        <TableCell className="text-sm">{c.employee ?? '—'}</TableCell>
                        <TableCell>
                          <CommandStatusBadge status={c.status} />
                          {c.error && <div className="text-xs text-red-600 mt-1">{c.error}</div>}
                        </TableCell>
                        <TableCell className="text-xs">{formatDateTime(c.created_at)}</TableCell>
                        <TableCell className="text-xs">{formatDateTime(c.sent_at)}</TableCell>
                        <TableCell className="text-xs">{formatDateTime(c.acknowledged_at)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                {recentCommands?.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">{t('No commands sent yet.')}</p>}
              </CardContent>
            </Card>
          </TabsContent>

          {/* Setup */}
          <TabsContent value="setup" className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader><CardTitle className="text-base">{t('Device Configuration')}</CardTitle></CardHeader>
              <CardContent className="space-y-3">
                <p className="text-sm text-muted-foreground">{t('Enter these values in the device communication / cloud server settings.')}</p>
                {setupHints?.map((h: any) => (
                  <div key={h.label} className="flex items-center justify-between gap-2 rounded-md border px-3 py-2">
                    <div>
                      <div className="text-xs text-muted-foreground">{t(h.label)}</div>
                      <div className="text-sm font-mono break-all">{h.value}</div>
                    </div>
                    <Button size="icon" variant="ghost" onClick={() => navigator.clipboard?.writeText(h.value).then(() => toast.success(t('Copied to clipboard')))}>
                      <Copy className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
              </CardContent>
            </Card>
            <Card>
              <CardHeader><CardTitle className="text-base">{t('Device Authentication')}</CardTitle></CardHeader>
              <CardContent className="space-y-3">
                <Detail label={t('Method')} value={t(device.auth_type_label)} />
                {['api_key', 'hmac'].includes(device.auth_type) && (
                  <>
                    <Detail label={t('API Key')} value={device.has_api_key ? `bd_${device.api_key_prefix}_••••••••` : t('Not issued')} mono />
                    {canEdit && (
                      <Button variant="outline" size="sm" onClick={regenerateKey}><KeyRound className="h-4 w-4 mr-2" />{t('Regenerate API Key')}</Button>
                    )}
                  </>
                )}
                {device.form?.secrets_set && Object.entries(device.form.secrets_set).map(([k, v]) => (
                  <Detail key={k} label={k} value={v ? t('Configured') : t('Not set')} />
                ))}
                <div className="flex items-center gap-2 text-xs text-muted-foreground pt-2"><Activity className="h-3 w-3" />{t('Secrets are stored encrypted and never displayed.')}</div>
              </CardContent>
            </Card>
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="text-base">{t('Provider Capabilities')}</CardTitle>
                <p className="text-xs text-muted-foreground">{drv?.label} — {drv?.description}</p>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                  {opts.operations?.map((op: any) => {
                    const cap = (drv as any)?.operations?.[op.value];
                    const level = cap?.level ?? 'unsupported';
                    const icon = level === 'supported'
                      ? <CheckCircle2 className="h-4 w-4 text-green-600" />
                      : level === 'unverified'
                        ? <ShieldQuestion className="h-4 w-4 text-amber-500" />
                        : <MinusCircle className="h-4 w-4 text-gray-400" />;
                    return (
                      <div key={op.value} className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                        <span className="flex items-center gap-2">{icon}{t(op.label)}</span>
                        <span className="text-xs text-muted-foreground">
                          {level === 'supported' ? t('Supported') : level === 'unverified' ? (cap?.available ? t('Enabled (unverified)') : t('Needs hardware verification')) : t('Not supported')}
                        </span>
                      </div>
                    );
                  })}
                </div>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </TooltipProvider>

      <DeviceFormDialog open={editOpen} onClose={() => setEditOpen(false)} mode="edit" deviceId={device.id} initial={device.form} options={opts} hasApiKey={device.has_api_key} />
      <DeleteDeviceDialog device={deleteOpen ? device : null} onClose={() => setDeleteOpen(false)} onDeactivate={() => { setDeleteOpen(false); toggleStatus(); }} />
      <SyncEmployeesDialog deviceId={device.id} open={syncEmployeesOpen} onClose={() => setSyncEmployeesOpen(false)} />
      <FetchLogsDialog deviceId={device.id} open={fetchLogsOpen} onClose={() => setFetchLogsOpen(false)} lastFetchTo={lastLogFetchTo} />
      <ApiKeyDialog apiKey={apiKey} onClose={() => setApiKey(null)} />
    </PageTemplate>
  );
}
