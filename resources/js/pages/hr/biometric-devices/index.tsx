// pages/hr/biometric-devices/index.tsx
import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageTemplate } from '@/components/page-template';
import { SearchAndFilterBar } from '@/components/ui/search-and-filter-bar';
import { Pagination } from '@/components/ui/pagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { hasPermission } from '@/utils/authorization';
import { toast } from '@/components/custom-toast';
import {
  Plus, Fingerprint, Wifi, WifiOff, Power, LayoutGrid, CheckCircle2, XCircle, Eye, Edit, Trash2, Lock,
  ChevronUp, ChevronDown, ChevronsUpDown, AlertTriangle, ServerCrash,
} from 'lucide-react';
import { DeviceFormDialog } from './components/device-form-dialog';
import { ApiKeyDialog, DeleteDeviceDialog } from './components/action-dialogs';
import { ActiveBadge, ConnectionBadge, DeviceListItem, DeviceOptions, formatDateTime, timeAgo, visitCallbacks } from './components/shared';

export default function BiometricDevices() {
  const { t } = useTranslation();
  const { auth, devices, stats, filters: pageFilters = {}, options, issuedApiKey } = usePage().props as any;
  const permissions: string[] = auth?.permissions || [];
  const opts = options as DeviceOptions;

  const [searchTerm, setSearchTerm] = useState(pageFilters.search || '');
  const [status, setStatus] = useState(pageFilters.status || '_empty_');
  const [branch, setBranch] = useState(pageFilters.branch_id || '_empty_');
  const [protocol, setProtocol] = useState(pageFilters.protocol || '_empty_');
  const [deviceType, setDeviceType] = useState(pageFilters.device_type || '_empty_');
  const [connection, setConnection] = useState(pageFilters.connection_status || '_empty_');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<DeviceListItem | null>(null);
  const [deleting, setDeleting] = useState<DeviceListItem | null>(null);
  const [apiKey, setApiKey] = useState<string | null>(null);

  // Device API keys are flashed once after create/update.
  useEffect(() => {
    if (issuedApiKey) setApiKey(issuedApiKey);
  }, [issuedApiKey]);

  const params = (extra: Record<string, any> = {}) => ({
    search: searchTerm || undefined,
    status: status !== '_empty_' ? status : undefined,
    branch_id: branch !== '_empty_' ? branch : undefined,
    protocol: protocol !== '_empty_' ? protocol : undefined,
    device_type: deviceType !== '_empty_' ? deviceType : undefined,
    connection_status: connection !== '_empty_' ? connection : undefined,
    per_page: pageFilters.per_page,
    sort_field: pageFilters.sort_field,
    sort_direction: pageFilters.sort_direction,
    ...extra,
  });

  const apply = (extra: Record<string, any> = {}) =>
    router.get(route('hr.biometric-devices.index'), params({ page: 1, ...extra }), { preserveState: true, preserveScroll: true });

  const firstRender = useRef(true);
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }
    apply();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, branch, protocol, deviceType, connection]);

  const resetFilters = () => {
    setSearchTerm('');
    setStatus('_empty_');
    setBranch('_empty_');
    setProtocol('_empty_');
    setDeviceType('_empty_');
    setConnection('_empty_');
    router.get(route('hr.biometric-devices.index'), { page: 1, per_page: pageFilters.per_page }, { preserveState: true, preserveScroll: true });
  };

  const activeFilterCount = () =>
    [searchTerm, branch !== '_empty_', protocol !== '_empty_', deviceType !== '_empty_', connection !== '_empty_'].filter(Boolean).length;

  const sort = (field: string) => {
    const direction = pageFilters.sort_field === field && pageFilters.sort_direction === 'asc' ? 'desc' : 'asc';
    router.get(route('hr.biometric-devices.index'), params({ sort_field: field, sort_direction: direction }), { preserveState: true, preserveScroll: true });
  };

  const sortIcon = (field: string) => {
    if (pageFilters.sort_field !== field) return <ChevronsUpDown className="ml-1 h-3 w-3 inline opacity-50" />;
    return pageFilters.sort_direction === 'asc' ? <ChevronUp className="ml-1 h-3 w-3 inline" /> : <ChevronDown className="ml-1 h-3 w-3 inline" />;
  };

  const toggleStatus = (device: DeviceListItem) => {
    toast.loading(device.is_active ? t('Deactivating device...') : t('Activating device...'));
    router.put(route('hr.biometric-devices.toggle-status', device.id), {}, visitCallbacks(t));
  };

  const pageActions = hasPermission(permissions, 'create-biometric-devices')
    ? [{ label: t('Add Device'), icon: <Plus className="h-4 w-4 mr-2" />, variant: 'default' as const, onClick: () => { setEditing(null); setFormOpen(true); } }]
    : [];

  const statCards = [
    { label: t('Total Devices'), value: stats?.total ?? 0, icon: Fingerprint, tone: 'blue', hint: t('Registered terminals') },
    { label: t('Online'), value: stats?.online ?? 0, icon: Wifi, tone: 'green', hint: t('Heartbeat within threshold') },
    { label: t('Offline / Unreachable'), value: stats?.offline ?? 0, icon: WifiOff, tone: 'red', hint: t('Active devices not responding') },
    { label: t('Inactive'), value: stats?.inactive ?? 0, icon: Power, tone: 'slate', hint: t('Deactivated by admin') },
  ];
  const toneCls: Record<string, string> = {
    blue: 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400',
    green: 'bg-green-100 text-green-600 dark:bg-green-900/40 dark:text-green-400',
    red: 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400',
    slate: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
  };

  const statusTabs = [
    { value: '_empty_', label: t('All'), icon: <LayoutGrid className="h-4 w-4" />, count: stats?.total ?? 0 },
    { value: 'active', label: t('Active'), icon: <CheckCircle2 className="h-4 w-4" />, count: stats?.active ?? 0 },
    { value: 'inactive', label: t('Inactive'), icon: <XCircle className="h-4 w-4" />, count: stats?.inactive ?? 0 },
  ];

  const withAll = (label: string, list: { value: string; label: string }[]) => [{ value: '_empty_', label }, ...list];

  return (
    <PageTemplate
      title={t('Biometric Devices')}
      description={t('Register, monitor and synchronize biometric attendance terminals')}
      url="/hr/biometric-devices"
      actions={pageActions}
      breadcrumbs={[{ title: t('Dashboard'), href: route('dashboard') }, { title: t('Attendance') }, { title: t('Biometric Devices') }]}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-2">
        {statCards.map((c) => (
          <div key={c.label} className="relative bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">{c.label}</p>
                <p className="text-2xl font-bold text-gray-900 dark:text-white">{c.value}</p>
                <p className="text-xs text-muted-foreground mt-2">{c.hint}</p>
              </div>
              <div className={`p-3 rounded-xl ${toneCls[c.tone]}`}>
                <c.icon className="h-6 w-6" />
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-lg shadow mb-4 border">
        <SearchAndFilterBar
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          onSearch={(e) => { e.preventDefault(); apply(); }}
          searchPlaceholder={t('Search name, serial, identifier, IP...')}
          filters={[
            { name: 'connection_status', label: t('Connection'), type: 'select', value: connection, onChange: setConnection, options: withAll(t('All'), opts.connection_statuses.map((o) => ({ value: o.value, label: t(o.label) }))) },
            { name: 'branch_id', label: t('Branch'), type: 'select', value: branch, onChange: setBranch, options: withAll(t('All Branches'), opts.branches.map((b) => ({ value: String(b.id), label: b.name }))) },
            { name: 'protocol', label: t('Protocol'), type: 'select', value: protocol, onChange: setProtocol, options: withAll(t('All Protocols'), opts.drivers.map((d) => ({ value: d.value, label: d.label }))) },
            { name: 'device_type', label: t('Device Type'), type: 'select', value: deviceType, onChange: setDeviceType, options: withAll(t('All Types'), opts.device_types.map((o) => ({ value: o.value, label: t(o.label) }))) },
          ]}
          statusTabs={statusTabs}
          activeStatusTab={status}
          onStatusTabChange={setStatus}
          hasActiveFilters={() => activeFilterCount() > 0 || status !== '_empty_'}
          activeFilterCount={activeFilterCount}
          onResetFilters={resetFilters}
        />
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-lg shadow border overflow-hidden">
        <TooltipProvider>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="cursor-pointer" onClick={() => sort('name')}>{t('Device')}{sortIcon('name')}</TableHead>
                <TableHead className="cursor-pointer" onClick={() => sort('serial_number')}>{t('Serial Number')}{sortIcon('serial_number')}</TableHead>
                <TableHead>{t('Type / Model')}</TableHead>
                <TableHead>{t('Location')}</TableHead>
                <TableHead>{t('Protocol / Network')}</TableHead>
                <TableHead>{t('Connection')}</TableHead>
                <TableHead className="cursor-pointer" onClick={() => sort('last_successful_sync_at')}>{t('Last Successful Sync')}{sortIcon('last_successful_sync_at')}</TableHead>
                <TableHead>{t('Status')}</TableHead>
                <TableHead className="text-right">{t('Actions')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {devices?.data?.map((d: DeviceListItem) => (
                <TableRow key={d.id}>
                  <TableCell>
                    <div className="flex items-center gap-3">
                      <div className="p-2 rounded-lg bg-gray-100 dark:bg-gray-800"><Fingerprint className="h-4 w-4 text-gray-600 dark:text-gray-300" /></div>
                      <div>
                        {hasPermission(permissions, 'view-biometric-devices') ? (
                          <Link href={route('hr.biometric-devices.show', d.id)} className="font-medium text-primary hover:underline">{d.name}</Link>
                        ) : (
                          <span className="font-medium">{d.name}</span>
                        )}
                        <div className="text-xs text-muted-foreground">{d.device_identifier}</div>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="font-mono text-xs">{d.serial_number}</TableCell>
                  <TableCell>
                    <div className="text-sm">{t(d.device_type_label)}</div>
                    <div className="text-xs text-muted-foreground">{[d.manufacturer, d.model].filter(Boolean).join(' · ') || '—'}</div>
                  </TableCell>
                  <TableCell>
                    <div className="text-sm">{d.branch ?? t('All branches')}</div>
                    {d.department && <div className="text-xs text-muted-foreground">{d.department}</div>}
                  </TableCell>
                  <TableCell>
                    <div className="text-sm">{d.protocol_label}</div>
                    <div className="text-xs text-muted-foreground">
                      {t(d.connection_type_label)}{d.ip_address ? ` · ${d.ip_address}${d.port ? ':' + d.port : ''}` : ''}
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-1">
                      <ConnectionBadge status={d.connection_status} />
                      {d.last_error && (
                        <Tooltip>
                          <TooltipTrigger><AlertTriangle className="h-4 w-4 text-amber-500" /></TooltipTrigger>
                          <TooltipContent className="max-w-xs">{d.last_error}</TooltipContent>
                        </Tooltip>
                      )}
                    </div>
                    {d.last_seen_at && <div className="text-xs text-muted-foreground mt-1">{t('Seen')} {timeAgo(d.last_seen_at)}</div>}
                  </TableCell>
                  <TableCell className="text-sm">{formatDateTime(d.last_successful_sync_at)}</TableCell>
                  <TableCell><ActiveBadge active={d.is_active} /></TableCell>
                  <TableCell className="text-right whitespace-nowrap">
                    {hasPermission(permissions, 'view-biometric-devices') && (
                      <Tooltip><TooltipTrigger asChild>
                        <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-blue-500" onClick={() => router.get(route('hr.biometric-devices.show', d.id))}><Eye className="h-4 w-4" /></Button>
                      </TooltipTrigger><TooltipContent>{t('View')}</TooltipContent></Tooltip>
                    )}
                    {hasPermission(permissions, 'edit-biometric-devices') && (
                      <>
                        <Tooltip><TooltipTrigger asChild>
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-amber-500" onClick={() => { setEditing(d); setFormOpen(true); }}><Edit className="h-4 w-4" /></Button>
                        </TooltipTrigger><TooltipContent>{t('Edit')}</TooltipContent></Tooltip>
                        <Tooltip><TooltipTrigger asChild>
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-amber-500" onClick={() => toggleStatus(d)}><Lock className="h-4 w-4" /></Button>
                        </TooltipTrigger><TooltipContent>{d.is_active ? t('Deactivate') : t('Activate')}</TooltipContent></Tooltip>
                      </>
                    )}
                    {hasPermission(permissions, 'delete-biometric-devices') && (
                      <Tooltip><TooltipTrigger asChild>
                        <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-red-500" onClick={() => setDeleting(d)}><Trash2 className="h-4 w-4" /></Button>
                      </TooltipTrigger><TooltipContent>{t('Delete')}</TooltipContent></Tooltip>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TooltipProvider>

        {devices?.data?.length === 0 && (
          <div className="flex flex-col items-center justify-center py-16">
            <div className="p-4 rounded-full bg-gray-100 dark:bg-gray-800 mb-4"><ServerCrash className="h-8 w-8 text-gray-400" /></div>
            <p className="text-sm font-semibold">{t('No biometric devices found')}</p>
            <p className="text-xs text-muted-foreground mt-1">{t('Add your first device or adjust the filters.')}</p>
          </div>
        )}

        <Pagination
          from={devices?.from || 0}
          to={devices?.to || 0}
          total={devices?.total || 0}
          links={devices?.links}
          entityName={t('devices')}
          onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })}
          perPageOptions={[10, 25, 50, 100]}
          currentPerPage={String(pageFilters.per_page || 10)}
          onPerPageChange={(value) => apply({ per_page: value })}
        />
      </div>

      <DeviceFormDialog
        open={formOpen}
        onClose={() => setFormOpen(false)}
        mode={editing ? 'edit' : 'create'}
        deviceId={editing?.id}
        initial={editing?.form ?? null}
        options={opts}
      />
      <DeleteDeviceDialog
        device={deleting}
        onClose={() => setDeleting(null)}
        onDeactivate={deleting ? () => { const d = deleting; setDeleting(null); toggleStatus(d); } : undefined}
      />
      <ApiKeyDialog apiKey={apiKey} onClose={() => setApiKey(null)} />
    </PageTemplate>
  );
}
