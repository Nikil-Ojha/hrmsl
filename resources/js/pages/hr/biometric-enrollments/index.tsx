// pages/hr/biometric-enrollments/index.tsx
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageTemplate } from '@/components/page-template';
import { SearchAndFilterBar } from '@/components/ui/search-and-filter-bar';
import { Pagination } from '@/components/ui/pagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { hasPermission } from '@/utils/authorization';
import { toast } from '@/components/custom-toast';
import { CheckCircle2, Clock, Fingerprint, Plus, RotateCw, ShieldCheck, Trash2, AlertTriangle, User, Share2 } from 'lucide-react';
import { formatDateTime, visitCallbacks } from '../biometric-devices/components/shared';
import { EnrollmentDeviceOption, EnrollmentItem, EnrollmentStatusBadge } from '../employees/components/biometric-enrollment-tab';

export default function BiometricEnrollmentsIndex() {
  const { t } = useTranslation();
  const { auth, enrollments, stats, employees = [], devices = [], fingerSlots = [], statuses = [], storageMode, filters: pageFilters = {} } = usePage().props as any;
  const permissions: string[] = auth?.permissions || [];

  const canEnroll = hasPermission(permissions, 'enroll-biometric-employees');
  const canSync = hasPermission(permissions, 'sync-biometric-enrollments');
  const canRemove = hasPermission(permissions, 'remove-biometric-enrollments');

  const [searchTerm, setSearchTerm] = useState(pageFilters.search || '');
  const [status, setStatus] = useState(pageFilters.status || '_empty_');
  const [deviceFilter, setDeviceFilter] = useState(pageFilters.device_id || '_empty_');

  // Enroll modal
  const [enrollOpen, setEnrollOpen] = useState(false);
  const [empId, setEmpId] = useState<string>('');
  const [devId, setDevId] = useState<string>(devices[0] ? String(devices[0].id) : '');
  const [modality, setModality] = useState<string>('fingerprint');
  const [slots, setSlots] = useState<number[]>([6]);
  const [customPin, setCustomPin] = useState<string>('');
  const [busy, setBusy] = useState(false);

  // Sync modal
  const [syncTarget, setSyncTarget] = useState<{ employee_id: number; employee_name: string } | null>(null);
  const [syncDeviceIds, setSyncDeviceIds] = useState<number[]>([]);

  const selectedDevice: EnrollmentDeviceOption | undefined = useMemo(
    () => devices.find((d: EnrollmentDeviceOption) => String(d.id) === devId),
    [devices, devId],
  );

  const apply = (extra: Record<string, any> = {}) => {
    router.get(route('hr.biometric-enrollments.index'), {
      search: searchTerm || undefined,
      status: status !== '_empty_' ? status : undefined,
      device_id: deviceFilter !== '_empty_' ? deviceFilter : undefined,
      per_page: pageFilters.per_page,
      page: 1,
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
  }, [status, deviceFilter]);

  const onSelectEmployee = (id: string) => {
    setEmpId(id);
    const e = employees.find((x: any) => String(x.id) === id);
    setCustomPin(e?.device_user_id || '');
  };

  const onSelectDevice = (id: string) => {
    setDevId(id);
    const d = devices.find((x: EnrollmentDeviceOption) => String(x.id) === id);
    if (d && !d.supported_modalities.some((m: { value: string; label: string }) => m.value === modality)) {
      setModality(d.supported_modalities[0]?.value ?? 'fingerprint');
    }
  };

  const submitEnroll = (e: React.FormEvent) => {
    e.preventDefault();
    if (!empId || !devId) return;
    setBusy(true);
    toast.loading(t('Starting biometric enrollment...'));
    router.post(
      route('hr.biometric-enrollments.start'),
      {
        employee_id: Number(empId),
        device_id: Number(devId),
        modality,
        finger_slots: modality === 'fingerprint' ? slots : [0],
        device_user_id: customPin.trim() || undefined,
      },
      { ...visitCallbacks(t, () => setEnrollOpen(false)), onFinish: () => setBusy(false) },
    );
  };

  const submitSync = (e: React.FormEvent) => {
    e.preventDefault();
    if (!syncTarget || syncDeviceIds.length === 0) return;
    setBusy(true);
    toast.loading(t('Synchronizing across devices...'));
    router.post(
      route('hr.biometric-enrollments.sync-devices', syncTarget.employee_id),
      { device_ids: syncDeviceIds },
      { ...visitCallbacks(t, () => { setSyncTarget(null); setSyncDeviceIds([]); }), onFinish: () => setBusy(false) },
    );
  };

  const pageActions = canEnroll
    ? [{ label: t('Enroll Employee'), icon: <Plus className="h-4 w-4 mr-2" />, variant: 'default' as const, onClick: () => setEnrollOpen(true) }]
    : [];

  return (
    <PageTemplate
      title={t('Biometric Enrollments')}
      description={t('Enroll employees on biometric hardware, manage multiple fingerprints / facial recognition and synchronize across devices')}
      url="/hr/biometric-enrollments"
      actions={pageActions}
      breadcrumbs={[{ title: t('Dashboard'), href: route('dashboard') }, { title: t('Attendance') }, { title: t('Biometric Enrollments') }]}
    >
      <Alert className="border-emerald-200 bg-emerald-50/50 dark:bg-emerald-950/20">
        <ShieldCheck className="h-4 w-4 text-emerald-600" />
        <AlertDescription className="text-xs text-emerald-800 dark:text-emerald-300">
          {storageMode === 'device_only'
            ? t('Security Mode: Device-only storage. Raw biometric images are never stored; matching happens locally on authorized devices.')
            : t('Security Mode: Encrypted template transfer enabled. Raw images are strictly rejected and templates are never exposed via employee APIs.')}
        </AlertDescription>
      </Alert>

      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        {[
          { label: t('Active Mappings'), value: stats?.total ?? 0, icon: Fingerprint, cls: 'bg-blue-100 text-blue-600' },
          { label: t('Enrolled'), value: stats?.enrolled ?? 0, icon: CheckCircle2, cls: 'bg-green-100 text-green-600' },
          { label: t('Pending / Awaiting Scan'), value: stats?.awaiting ?? 0, icon: Clock, cls: 'bg-amber-100 text-amber-600' },
          { label: t('Failed'), value: stats?.failed ?? 0, icon: AlertTriangle, cls: 'bg-red-100 text-red-600' },
        ].map((c) => (
          <div key={c.label} className="bg-white dark:bg-gray-800 rounded-xl border p-5 shadow-sm flex items-start justify-between">
            <div>
              <p className="text-sm font-medium text-muted-foreground mb-1">{c.label}</p>
              <p className="text-2xl font-bold">{c.value}</p>
            </div>
            <div className={`p-3 rounded-xl ${c.cls}`}><c.icon className="h-6 w-6" /></div>
          </div>
        ))}
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-lg shadow border">
        <SearchAndFilterBar
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          onSearch={(e) => { e.preventDefault(); apply(); }}
          searchPlaceholder={t('Search employee, ref, PIN, device...')}
          filters={[
            {
              name: 'device_id',
              label: t('Device'),
              type: 'select',
              value: deviceFilter,
              onChange: setDeviceFilter,
              options: [{ value: '_empty_', label: t('All Devices') }, ...devices.map((d: EnrollmentDeviceOption) => ({ value: String(d.id), label: d.name }))],
            },
            {
              name: 'status',
              label: t('Status'),
              type: 'select',
              value: status,
              onChange: setStatus,
              options: [{ value: '_empty_', label: t('All Statuses') }, ...statuses.map((s: any) => ({ value: s.value, label: t(s.label) }))],
            },
          ]}
          hasActiveFilters={() => Boolean(searchTerm || status !== '_empty_' || deviceFilter !== '_empty_')}
          activeFilterCount={() => [searchTerm, status !== '_empty_', deviceFilter !== '_empty_'].filter(Boolean).length}
          onResetFilters={() => {
            setSearchTerm('');
            setStatus('_empty_');
            setDeviceFilter('_empty_');
            router.get(route('hr.biometric-enrollments.index'), {}, { preserveState: true, preserveScroll: true });
          }}
        />
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-lg shadow border overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('Employee')}</TableHead>
              <TableHead>{t('Device')}</TableHead>
              <TableHead>{t('Device User ID / Ref')}</TableHead>
              <TableHead>{t('Biometrics')}</TableHead>
              <TableHead>{t('Status')}</TableHead>
              <TableHead>{t('Enrolled / Last Sync')}</TableHead>
              <TableHead className="text-right">{t('Actions')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {enrollments?.data?.map((item: EnrollmentItem) => (
              <TableRow key={item.id}>
                <TableCell>
                  <Link href={`${route('hr.employees.show', item.employee_id)}?tab=biometric`} className="font-medium text-primary hover:underline flex items-center gap-1.5">
                    <User className="h-3.5 w-3.5" /> {item.employee_name || item.employee_code}
                  </Link>
                  <div className="text-xs text-muted-foreground">{item.employee_code}</div>
                </TableCell>
                <TableCell>
                  <Link href={route('hr.biometric-devices.show', item.device_id)} className="text-sm font-medium hover:underline">{item.device_name}</Link>
                  <div className="text-xs font-mono text-muted-foreground">{item.device_serial}</div>
                </TableCell>
                <TableCell>
                  <div className="font-mono text-sm">{item.device_user_id}</div>
                  <div className="font-mono text-xs text-muted-foreground">{item.enrollment_reference}</div>
                </TableCell>
                <TableCell>
                  <div className="flex flex-wrap gap-1">
                    {item.credentials.filter((c) => c.status !== 'removed').map((c) => (
                      <Badge key={c.id} variant="outline" className="text-[11px]">{c.slot_label || c.modality_label}</Badge>
                    ))}
                  </div>
                </TableCell>
                <TableCell><EnrollmentStatusBadge status={item.enrollment_status} label={item.enrollment_status_label} /></TableCell>
                <TableCell className="text-xs whitespace-nowrap">
                  <div>{formatDateTime(item.enrolled_at)}</div>
                  <div className="text-muted-foreground">{t('Sync')}: {formatDateTime(item.last_synced_at)}</div>
                </TableCell>
                <TableCell className="text-right whitespace-nowrap space-x-1">
                  {canEnroll && item.enrollment_status === 'awaiting_capture' && (
                    <Button size="sm" variant="outline" onClick={() => router.post(route('hr.biometric-enrollments.confirm', item.id), {}, visitCallbacks(t))}>
                      {t('Confirm')}
                    </Button>
                  )}
                  {canSync && (
                    <Button size="sm" variant="ghost" onClick={() => setSyncTarget({ employee_id: item.employee_id, employee_name: item.employee_name || '' })} title={t('Sync across devices')}>
                      <Share2 className="h-4 w-4 text-blue-600" />
                    </Button>
                  )}
                  {canEnroll && (
                    <Button size="sm" variant="ghost" onClick={() => router.post(route('hr.biometric-enrollments.re-enroll', item.id), {}, visitCallbacks(t))} title={t('Re-enroll')}>
                      <RotateCw className="h-4 w-4 text-amber-600" />
                    </Button>
                  )}
                  {canRemove && item.enrollment_status !== 'removed' && (
                    <Button size="sm" variant="ghost" onClick={() => window.confirm(t('Remove this biometric registration?')) && router.delete(route('hr.biometric-enrollments.remove', item.id), visitCallbacks(t))} title={t('Remove')}>
                      <Trash2 className="h-4 w-4 text-red-500" />
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {enrollments?.data?.length === 0 && (
          <p className="p-8 text-center text-sm text-muted-foreground">{t('No employee biometric enrollments found.')}</p>
        )}
        <Pagination
          from={enrollments?.from || 0}
          to={enrollments?.to || 0}
          total={enrollments?.total || 0}
          links={enrollments?.links}
          entityName={t('enrollments')}
          onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })}
          perPageOptions={[10, 15, 25, 50]}
          currentPerPage={String(pageFilters.per_page || 15)}
          onPerPageChange={(v) => apply({ per_page: v })}
        />
      </div>

      {/* Enroll Employee Dialog */}
      <Dialog open={enrollOpen} onOpenChange={setEnrollOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t('Enroll Employee on Biometric Device')}</DialogTitle>
            <DialogDescription>{t('Select an employee, a target device, and fingerprint slots or facial recognition.')}</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitEnroll} className="space-y-4">
            <div className="space-y-1.5">
              <Label>{t('Employee')} <span className="text-red-500">*</span></Label>
              <Select value={empId} onValueChange={onSelectEmployee}>
                <SelectTrigger><SelectValue placeholder={t('Select employee...')} /></SelectTrigger>
                <SelectContent className="z-[60000] max-h-64" searchable>
                  {employees.map((e: any) => (
                    <SelectItem key={e.id} value={String(e.id)}>{e.name} ({e.employee_code})</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label>{t('Biometric Device')} <span className="text-red-500">*</span></Label>
              <Select value={devId} onValueChange={onSelectDevice}>
                <SelectTrigger><SelectValue placeholder={t('Select device...')} /></SelectTrigger>
                <SelectContent className="z-[60000]">
                  {devices.map((d: EnrollmentDeviceOption) => (
                    <SelectItem key={d.id} value={String(d.id)}>{d.name} ({d.device_type_label})</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <Label>{t('Method')}</Label>
                <Select value={modality} onValueChange={setModality}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent className="z-[60000]">
                    {(selectedDevice?.supported_modalities ?? []).map((m) => (
                      <SelectItem key={m.value} value={m.value}>{t(m.label)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label>{t('Device User ID (PIN)')}</Label>
                <Input value={customPin} onChange={(e) => setCustomPin(e.target.value.replace(/\D/g, '').slice(0, 9))} placeholder={t('Auto-generated')} />
              </div>
            </div>
            {modality === 'fingerprint' && (
              <div className="grid grid-cols-2 gap-2 rounded-md border p-3">
                {fingerSlots.map((s: any) => (
                  <label key={s.value} className="flex items-center gap-2 text-sm cursor-pointer">
                    <Checkbox
                      checked={slots.includes(s.value)}
                      onCheckedChange={() => setSlots((prev) => (prev.includes(s.value) ? (prev.length > 1 ? prev.filter((x) => x !== s.value) : prev) : [...prev, s.value].sort((a, b) => a - b)))}
                    />
                    <span>{t(s.label)}</span>
                  </label>
                ))}
              </div>
            )}
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setEnrollOpen(false)}>{t('Cancel')}</Button>
              <Button type="submit" disabled={busy || !empId || !devId}>{t('Start Enrollment')}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Multi-device sync modal */}
      <Dialog open={!!syncTarget} onOpenChange={(v) => !v && setSyncTarget(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t('Sync Across Authorized Devices')}</DialogTitle>
            <DialogDescription>{syncTarget?.employee_name}</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitSync} className="space-y-4">
            <div className="max-h-60 overflow-y-auto rounded-md border divide-y">
              {devices.map((d: EnrollmentDeviceOption) => (
                <label key={d.id} className="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer">
                  <Checkbox
                    checked={syncDeviceIds.includes(d.id)}
                    onCheckedChange={() => setSyncDeviceIds((prev) => (prev.includes(d.id) ? prev.filter((x) => x !== d.id) : [...prev, d.id]))}
                  />
                  <span>{d.name} <span className="text-xs text-muted-foreground">({d.device_type_label})</span></span>
                </label>
              ))}
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setSyncTarget(null)}>{t('Cancel')}</Button>
              <Button type="submit" disabled={busy || syncDeviceIds.length === 0}>{t('Sync Selected')}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </PageTemplate>
  );
}
