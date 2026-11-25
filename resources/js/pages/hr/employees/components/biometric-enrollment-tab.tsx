// pages/hr/employees/components/biometric-enrollment-tab.tsx
import { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { hasPermission } from '@/utils/authorization';
import { toast } from '@/components/custom-toast';
import {
  CheckCircle2, Clock, Fingerprint, Loader2, Plus, RefreshCw, ScanFace, ShieldCheck, Trash2, AlertTriangle, RotateCw, Share2,
} from 'lucide-react';
import { ConnectionBadge, formatDateTime, visitCallbacks } from '../../biometric-devices/components/shared';

export interface EnrollmentDeviceOption {
  id: number;
  name: string;
  serial_number: string;
  device_type: string;
  device_type_label: string;
  protocol: string;
  connection_status: string;
  branch: string | null;
  agent_name: string | null;
  supported_modalities: { value: string; label: string }[];
}

export interface EnrollmentCredentialItem {
  id: number;
  modality: string;
  modality_label: string;
  slot_index: number;
  slot_label: string;
  status: string;
  quality_score: number | null;
  enrolled_at: string | null;
  last_synced_at: string | null;
}

export interface EnrollmentItem {
  id: number;
  employee_id: number;
  employee_name?: string;
  employee_code?: string;
  device_id: number;
  device_name: string;
  device_serial: string;
  device_type: string;
  device_type_label: string;
  device_connection_status: string;
  device_active: boolean;
  agent_name: string | null;
  device_user_id: string;
  enrollment_reference: string;
  enrollment_status: string;
  enrollment_status_label: string;
  enrolled_modalities: string[];
  fingerprint_count: number;
  face_enrolled: boolean;
  re_enrollment_count: number;
  source_device_name: string | null;
  enrolled_by: string | null;
  enrolled_at: string | null;
  last_synced_at: string | null;
  last_error: string | null;
  supported_modalities: { value: string; label: string }[];
  credentials: EnrollmentCredentialItem[];
}

export interface EmployeeBiometricPayload {
  employee_id: number;
  device_user_id: string | null;
  is_enrolled: boolean;
  status: string;
  storage_mode: 'device_only' | 'encrypted_transfer';
  encrypted_template_count: number;
  enrollments: EnrollmentItem[];
  available_devices: EnrollmentDeviceOption[];
  finger_slots: { value: number; label: string }[];
}

export function EnrollmentStatusBadge({ status, label }: { status: string; label?: string }) {
  const { t } = useTranslation();
  const styles: Record<string, { cls: string; icon: React.ReactNode }> = {
    enrolled: { cls: 'bg-green-50 text-green-700 border-green-200', icon: <CheckCircle2 className="h-3 w-3" /> },
    awaiting_capture: { cls: 'bg-amber-50 text-amber-700 border-amber-200', icon: <Clock className="h-3 w-3" /> },
    pending: { cls: 'bg-blue-50 text-blue-700 border-blue-200', icon: <Loader2 className="h-3 w-3 animate-spin" /> },
    syncing: { cls: 'bg-blue-50 text-blue-700 border-blue-200', icon: <RefreshCw className="h-3 w-3 animate-spin" /> },
    removing: { cls: 'bg-orange-50 text-orange-700 border-orange-200', icon: <Loader2 className="h-3 w-3 animate-spin" /> },
    failed: { cls: 'bg-red-50 text-red-700 border-red-200', icon: <AlertTriangle className="h-3 w-3" /> },
    removed: { cls: 'bg-gray-100 text-gray-500 border-gray-200', icon: <Trash2 className="h-3 w-3" /> },
  };
  const s = styles[status] ?? styles.pending;
  return (
    <Badge variant="outline" className={`gap-1 ${s.cls}`}>
      {s.icon}
      {t(label || status)}
    </Badge>
  );
}

export function BiometricEnrollmentTab({
  data,
  employeeName,
  permissions,
}: {
  data: EmployeeBiometricPayload;
  employeeName: string;
  permissions: string[];
}) {
  const { t } = useTranslation();

  const canEnroll = hasPermission(permissions, 'enroll-biometric-employees');
  const canSync = hasPermission(permissions, 'sync-biometric-enrollments');
  const canRemove = hasPermission(permissions, 'remove-biometric-enrollments');

  // Start enrollment dialog state
  const [enrollOpen, setEnrollOpen] = useState(false);
  const [selectedDeviceId, setSelectedDeviceId] = useState<string>(() => (data.available_devices[0] ? String(data.available_devices[0].id) : ''));
  const [modality, setModality] = useState<string>('fingerprint');
  const [fingerSlots, setFingerSlots] = useState<number[]>([6]); // Right Index default
  const [deviceUserId, setDeviceUserId] = useState<string>(data.device_user_id || '');
  const [submitting, setSubmitting] = useState(false);

  // Re-enroll dialog state
  const [reEnrollTarget, setReEnrollTarget] = useState<EnrollmentItem | null>(null);
  const [reModality, setReModality] = useState<string>('fingerprint');
  const [reSlots, setReSlots] = useState<number[]>([6]);

  // Multi-device sync dialog state
  const [syncOpen, setSyncOpen] = useState(false);
  const [syncDeviceIds, setSyncDeviceIds] = useState<number[]>([]);
  const [sourceDeviceId, setSourceDeviceId] = useState<string>('_auto_');

  const selectedDevice = useMemo(
    () => data.available_devices.find((d) => String(d.id) === selectedDeviceId),
    [data.available_devices, selectedDeviceId],
  );

  const activeEnrollments = useMemo(
    () => data.enrollments.filter((e) => e.enrollment_status !== 'removed'),
    [data.enrollments],
  );

  const onDevicePick = (id: string) => {
    setSelectedDeviceId(id);
    const dev = data.available_devices.find((d) => String(d.id) === id);
    if (dev && !dev.supported_modalities.some((m) => m.value === modality)) {
      setModality(dev.supported_modalities[0]?.value ?? 'fingerprint');
    }
  };

  const toggleSlot = (slot: number, list: number[], setList: (v: number[]) => void) => {
    if (list.includes(slot)) {
      if (list.length > 1) setList(list.filter((x) => x !== slot));
    } else {
      setList([...list, slot].sort((a, b) => a - b));
    }
  };

  const submitStart = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedDeviceId) return;
    setSubmitting(true);
    toast.loading(t('Starting biometric enrollment...'));
    router.post(
      route('hr.biometric-enrollments.start'),
      {
        employee_id: data.employee_id,
        device_id: Number(selectedDeviceId),
        modality,
        finger_slots: modality === 'fingerprint' ? fingerSlots : [0],
        device_user_id: deviceUserId.trim() || undefined,
      },
      {
        ...visitCallbacks(t, () => setEnrollOpen(false)),
        onFinish: () => setSubmitting(false),
      },
    );
  };

  const openReEnroll = (item: EnrollmentItem) => {
    const firstMod = item.credentials[0]?.modality || item.supported_modalities[0]?.value || 'fingerprint';
    const existingFpSlots = item.credentials.filter((c) => c.modality === 'fingerprint').map((c) => c.slot_index);
    setReEnrollTarget(item);
    setReModality(firstMod);
    setReSlots(existingFpSlots.length ? existingFpSlots : [6]);
  };

  const submitReEnroll = (e: React.FormEvent) => {
    e.preventDefault();
    if (!reEnrollTarget) return;
    setSubmitting(true);
    toast.loading(t('Initiating re-enrollment...'));
    router.post(
      route('hr.biometric-enrollments.re-enroll', reEnrollTarget.id),
      {
        modality: reModality,
        finger_slots: reModality === 'fingerprint' ? reSlots : [0],
      },
      {
        ...visitCallbacks(t, () => setReEnrollTarget(null)),
        onFinish: () => setSubmitting(false),
      },
    );
  };

  const submitSync = (e: React.FormEvent) => {
    e.preventDefault();
    if (syncDeviceIds.length === 0) return;
    setSubmitting(true);
    toast.loading(t('Synchronizing across devices...'));
    router.post(
      route('hr.biometric-enrollments.sync-devices', data.employee_id),
      {
        device_ids: syncDeviceIds,
        source_device_id: sourceDeviceId !== '_auto_' ? Number(sourceDeviceId) : undefined,
      },
      {
        ...visitCallbacks(t, () => {
          setSyncOpen(false);
          setSyncDeviceIds([]);
        }),
        onFinish: () => setSubmitting(false),
      },
    );
  };

  const confirmCapture = (item: EnrollmentItem) => {
    toast.loading(t('Confirming enrollment...'));
    router.post(route('hr.biometric-enrollments.confirm', item.id), {}, visitCallbacks(t));
  };

  const removeOne = (item: EnrollmentItem) => {
    if (!window.confirm(t('Remove :name’s biometric registration from :device?').replace(':name', employeeName).replace(':device', item.device_name))) return;
    toast.loading(t('Removing biometric registration...'));
    router.delete(route('hr.biometric-enrollments.remove', item.id), visitCallbacks(t));
  };

  const removeAll = () => {
    if (!window.confirm(t('Remove ALL biometric registrations for :name across every device?').replace(':name', employeeName))) return;
    toast.loading(t('Removing all biometric registrations...'));
    router.delete(route('hr.biometric-enrollments.remove-all', data.employee_id), visitCallbacks(t));
  };

  const totalFingerprints = activeEnrollments.reduce((acc, e) => Math.max(acc, e.fingerprint_count), 0);
  const hasFace = activeEnrollments.some((e) => e.face_enrolled);

  return (
    <div className="space-y-4">
      {/* Security architecture notice */}
      <Alert className="border-emerald-200 bg-emerald-50/50 dark:bg-emerald-950/20">
        <ShieldCheck className="h-4 w-4 text-emerald-600" />
        <AlertTitle className="text-emerald-800 dark:text-emerald-300">
          {data.storage_mode === 'device_only' ? t('Device-Only Biometric Storage Active') : t('Encrypted Template Transfer Mode Active')}
        </AlertTitle>
        <AlertDescription className="text-xs text-emerald-700 dark:text-emerald-400">
          {data.storage_mode === 'device_only'
            ? t('Raw fingerprint and facial images are never stored in HRMS. Biometric templates remain exclusively on authorized hardware devices, where matching is performed locally.')
            : t('Raw fingerprint and facial images are strictly prohibited. Mathematical templates required for multi-device transfer are stored encrypted at rest and never exposed in employee APIs or logs.')}
        </AlertDescription>
      </Alert>

      {/* Summary & Action Bar */}
      <Card>
        <CardHeader className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 space-y-0">
          <div>
            <CardTitle className="text-lg flex items-center gap-2">
              <Fingerprint className="h-5 w-5 text-primary" /> {t('Biometric Enrollment')}
            </CardTitle>
            <CardDescription>
              {t('Manage hardware registrations, multiple fingerprints, facial recognition and multi-device synchronization.')}
            </CardDescription>
          </div>
          <div className="flex flex-wrap gap-2">
            {canEnroll && (
              <Button size="sm" onClick={() => setEnrollOpen(true)} disabled={data.available_devices.length === 0}>
                <Plus className="h-4 w-4 mr-1.5" /> {t('Start Enrollment')}
              </Button>
            )}
            {canSync && (
              <Button size="sm" variant="outline" onClick={() => setSyncOpen(true)} disabled={data.available_devices.length === 0}>
                <Share2 className="h-4 w-4 mr-1.5" /> {t('Sync Across Devices')}
              </Button>
            )}
            {canRemove && activeEnrollments.length > 0 && (
              <Button size="sm" variant="destructive" onClick={removeAll}>
                <Trash2 className="h-4 w-4 mr-1.5" /> {t('Remove All')}
              </Button>
            )}
          </div>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
            <div className="rounded-lg border p-3">
              <div className="text-xs text-muted-foreground">{t('Device User ID (PIN)')}</div>
              <div className="text-base font-mono font-semibold mt-0.5">{data.device_user_id || t('Auto on enroll')}</div>
            </div>
            <div className="rounded-lg border p-3">
              <div className="text-xs text-muted-foreground">{t('Active Devices')}</div>
              <div className="text-base font-semibold mt-0.5">{activeEnrollments.length}</div>
            </div>
            <div className="rounded-lg border p-3">
              <div className="text-xs text-muted-foreground">{t('Enrolled Fingerprints')}</div>
              <div className="text-base font-semibold mt-0.5 flex items-center gap-1.5">
                <Fingerprint className="h-4 w-4 text-primary" /> {totalFingerprints}
              </div>
            </div>
            <div className="rounded-lg border p-3">
              <div className="text-xs text-muted-foreground">{t('Facial Recognition')}</div>
              <div className="text-base font-semibold mt-0.5 flex items-center gap-1.5">
                <ScanFace className="h-4 w-4 text-primary" /> {hasFace ? t('Enrolled') : t('Not enrolled')}
              </div>
            </div>
          </div>

          {data.enrollments.length === 0 ? (
            <div className="rounded-lg border border-dashed py-10 text-center">
              <Fingerprint className="h-8 w-8 text-muted-foreground mx-auto mb-2" />
              <p className="text-sm font-medium">{t('No biometric registrations yet')}</p>
              <p className="text-xs text-muted-foreground mt-1">
                {data.available_devices.length > 0
                  ? t('Click “Start Enrollment” to register fingerprints or facial recognition on an authorized device.')
                  : t('Register an active biometric device first under Attendance → Biometric Devices.')}
              </p>
            </div>
          ) : (
            <div className="rounded-md border overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('Device')}</TableHead>
                    <TableHead>{t('Device User ID')}</TableHead>
                    <TableHead>{t('Enrollment Ref')}</TableHead>
                    <TableHead>{t('Biometrics & Finger Slots')}</TableHead>
                    <TableHead>{t('Status')}</TableHead>
                    <TableHead>{t('Enrolled / Last Sync')}</TableHead>
                    <TableHead className="text-right">{t('Actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.enrollments.map((item) => (
                    <TableRow key={item.id}>
                      <TableCell>
                        <Link href={route('hr.biometric-devices.show', item.device_id)} className="font-medium text-primary hover:underline">
                          {item.device_name}
                        </Link>
                        <div className="text-xs text-muted-foreground flex items-center gap-1.5 mt-0.5">
                          <span className="font-mono">{item.device_serial}</span>
                          <ConnectionBadge status={item.device_connection_status} />
                        </div>
                      </TableCell>
                      <TableCell className="font-mono text-sm">{item.device_user_id}</TableCell>
                      <TableCell>
                        <div className="font-mono text-xs">{item.enrollment_reference}</div>
                        {item.re_enrollment_count > 0 && (
                          <div className="text-[11px] text-muted-foreground">{t('Re-enrolled')} ×{item.re_enrollment_count}</div>
                        )}
                      </TableCell>
                      <TableCell>
                        <div className="flex flex-wrap gap-1">
                          {item.credentials.filter((c) => c.status !== 'removed').map((c) => (
                            <Badge
                              key={c.id}
                              variant="outline"
                              className={`text-[11px] ${c.status === 'enrolled' ? 'border-green-300 bg-green-50 text-green-700' : c.status === 'failed' ? 'border-red-300 text-red-700' : 'border-amber-300 text-amber-700'}`}
                            >
                              {c.slot_label || c.modality_label}
                              {c.status === 'enrolled' ? ' ✓' : ''}
                            </Badge>
                          ))}
                          {item.credentials.filter((c) => c.status !== 'removed').length === 0 && (
                            <span className="text-xs text-muted-foreground">—</span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell>
                        <EnrollmentStatusBadge status={item.enrollment_status} label={item.enrollment_status_label} />
                        {item.last_error && <div className="text-xs text-red-600 mt-1 max-w-xs">{item.last_error}</div>}
                      </TableCell>
                      <TableCell className="text-xs whitespace-nowrap">
                        <div>{t('Enrolled')}: {formatDateTime(item.enrolled_at)}</div>
                        <div className="text-muted-foreground">{t('Synced')}: {formatDateTime(item.last_synced_at)}</div>
                      </TableCell>
                      <TableCell className="text-right whitespace-nowrap space-x-1">
                        {canEnroll && item.enrollment_status === 'awaiting_capture' && (
                          <Button size="sm" variant="outline" onClick={() => confirmCapture(item)} title={t('Mark physical capture completed')}>
                            <CheckCircle2 className="h-3.5 w-3.5 mr-1 text-green-600" /> {t('Confirm')}
                          </Button>
                        )}
                        {canEnroll && item.device_active && (
                          <Button size="sm" variant="ghost" onClick={() => openReEnroll(item)} title={t('Re-enroll on this device')}>
                            <RotateCw className="h-4 w-4 text-amber-600" />
                          </Button>
                        )}
                        {canRemove && item.enrollment_status !== 'removed' && (
                          <Button size="sm" variant="ghost" onClick={() => removeOne(item)} title={t('Remove registration')}>
                            <Trash2 className="h-4 w-4 text-red-500" />
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Start Enrollment Dialog */}
      <Dialog open={enrollOpen} onOpenChange={setEnrollOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t('Start Biometric Enrollment')}</DialogTitle>
            <DialogDescription>{employeeName}</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitStart} className="space-y-4">
            <div className="space-y-1.5">
              <Label>{t('Biometric Device')} <span className="text-red-500">*</span></Label>
              <Select value={selectedDeviceId} onValueChange={onDevicePick}>
                <SelectTrigger><SelectValue placeholder={t('Select device...')} /></SelectTrigger>
                <SelectContent>
                  {data.available_devices.map((d) => (
                    <SelectItem key={d.id} value={String(d.id)}>
                      {d.name} ({d.device_type_label}) {d.branch ? `— ${d.branch}` : ''}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <Label>{t('Biometric Method')} <span className="text-red-500">*</span></Label>
                <Select value={modality} onValueChange={setModality}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {(selectedDevice?.supported_modalities ?? []).map((m) => (
                      <SelectItem key={m.value} value={m.value}>{t(m.label)}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label>{t('Device User ID (PIN)')}</Label>
                <Input value={deviceUserId} onChange={(e) => setDeviceUserId(e.target.value.replace(/\D/g, '').slice(0, 9))} placeholder={t('Auto-generated')} />
              </div>
            </div>

            {modality === 'fingerprint' && (
              <div className="space-y-2">
                <Label>{t('Select Finger Slot(s) — multiple fingerprints supported')}</Label>
                <div className="grid grid-cols-2 gap-2 rounded-md border p-3">
                  {data.finger_slots.map((s) => (
                    <label key={s.value} className="flex items-center gap-2 text-sm cursor-pointer">
                      <Checkbox checked={fingerSlots.includes(s.value)} onCheckedChange={() => toggleSlot(s.value, fingerSlots, setFingerSlots)} />
                      <span>{t(s.label)}</span>
                    </label>
                  ))}
                </div>
              </div>
            )}

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setEnrollOpen(false)}>{t('Cancel')}</Button>
              <Button type="submit" disabled={submitting || !selectedDeviceId}>{t('Start Enrollment')}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Re-enroll Dialog */}
      <Dialog open={!!reEnrollTarget} onOpenChange={(v) => !v && setReEnrollTarget(null)}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t('Re-enroll Employee')}</DialogTitle>
            <DialogDescription>{reEnrollTarget?.device_name} · {reEnrollTarget?.enrollment_reference}</DialogDescription>
          </DialogHeader>
          <form onSubmit={submitReEnroll} className="space-y-4">
            <div className="space-y-1.5">
              <Label>{t('Biometric Method')}</Label>
              <Select value={reModality} onValueChange={setReModality}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {(reEnrollTarget?.supported_modalities ?? []).map((m) => (
                    <SelectItem key={m.value} value={m.value}>{t(m.label)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            {reModality === 'fingerprint' && (
              <div className="space-y-2">
                <Label>{t('Finger Slot(s) to Re-enroll')}</Label>
                <div className="grid grid-cols-2 gap-2 rounded-md border p-3">
                  {data.finger_slots.map((s) => (
                    <label key={s.value} className="flex items-center gap-2 text-sm cursor-pointer">
                      <Checkbox checked={reSlots.includes(s.value)} onCheckedChange={() => toggleSlot(s.value, reSlots, setReSlots)} />
                      <span>{t(s.label)}</span>
                    </label>
                  ))}
                </div>
              </div>
            )}
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setReEnrollTarget(null)}>{t('Cancel')}</Button>
              <Button type="submit" disabled={submitting}>{t('Re-enroll Now')}</Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Multi-device Sync Dialog */}
      <Dialog open={syncOpen} onOpenChange={setSyncOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{t('Synchronize Across Authorized Devices')}</DialogTitle>
            <DialogDescription>
              {t('Select target biometric devices to synchronize :name’s registration.').replace(':name', employeeName)}
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={submitSync} className="space-y-4">
            {activeEnrollments.length > 0 && (
              <div className="space-y-1.5">
                <Label>{t('Source Device (optional)')}</Label>
                <Select value={sourceDeviceId} onValueChange={setSourceDeviceId}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="_auto_">{t('Auto (all enrolled credentials)')}</SelectItem>
                    {activeEnrollments.map((e) => (
                      <SelectItem key={e.device_id} value={String(e.device_id)}>{e.device_name} ({e.device_serial})</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}

            <div className="space-y-2">
              <Label>{t('Target Authorized Devices')}</Label>
              <div className="max-h-60 overflow-y-auto rounded-md border divide-y">
                {data.available_devices.map((d) => (
                  <label key={d.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm cursor-pointer">
                    <span className="flex items-center gap-2">
                      <Checkbox
                        checked={syncDeviceIds.includes(d.id)}
                        onCheckedChange={() => setSyncDeviceIds((prev) => (prev.includes(d.id) ? prev.filter((x) => x !== d.id) : [...prev, d.id]))}
                      />
                      <span>
                        <span className="font-medium">{d.name}</span>
                        <span className="block text-xs text-muted-foreground">{d.device_type_label}{d.branch ? ` · ${d.branch}` : ''}</span>
                      </span>
                    </span>
                    <ConnectionBadge status={d.connection_status} />
                  </label>
                ))}
              </div>
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setSyncOpen(false)}>{t('Cancel')}</Button>
              <Button type="submit" disabled={submitting || syncDeviceIds.length === 0}>
                {t('Sync to :count Device(s)').replace(':count', String(syncDeviceIds.length))}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
