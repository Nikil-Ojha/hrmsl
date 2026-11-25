// pages/hr/biometric-sync/components/raw-logs-audit-dialog.tsx
import { useEffect, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { toast } from '@/components/custom-toast';
import {
  AlertTriangle, Clock, FileCheck2, Fingerprint, Loader2, Lock, Moon, RefreshCw, ShieldAlert, Unlock,
} from 'lucide-react';
import { formatDateTime, visitCallbacks } from '../../biometric-devices/components/shared';

export interface AuditTarget {
  userId: number;
  date: string;
  employeeName?: string;
}

interface RawLogsPayload {
  employee: {
    id: number;
    user_id: number;
    name: string | null;
    employee_code: string | null;
    biometric_emp_id: string | null;
  };
  attendance_date: string;
  processed_record: {
    id: number;
    clock_in: string | null;
    clock_out: string | null;
    total_hours: number;
    break_hours: number;
    overtime_hours: number;
    overtime_amount: number;
    is_late: boolean;
    late_minutes: number;
    is_early_departure: boolean;
    early_departure_minutes: number;
    is_missing_clock_out: boolean;
    status: string;
    source: string;
    is_manual_override: boolean;
    punch_count: number;
    shift: {
      name: string;
      start_time: string;
      end_time: string;
      is_night_shift: boolean;
      grace_period: number;
    } | null;
    policy: {
      name: string;
      punch_pairing_mode: string;
      late_arrival_grace: number;
      early_departure_grace: number;
      duplicate_punch_window_sec: number;
      missing_clock_out_grace_hours: number;
      auto_detect_shift: boolean;
    } | null;
  } | null;
  regularization: {
    id: number;
    status: string;
    requested_clock_in: string | null;
    requested_clock_out: string | null;
    reason: string | null;
    approved_by: string | null;
    approved_at: string | null;
  } | null;
  raw_punches: Array<{
    id: number;
    device_name: string | null;
    device_serial: string | null;
    device_user_id: string;
    punched_at_local: string | null;
    punched_at_utc: string | null;
    device_timezone: string | null;
    punch_type: string;
    punch_type_label: string;
    verification_method_label: string;
    original_device_log_id: string | null;
    source_label: string;
    synced_at: string | null;
    processing_status: string;
    processing_status_label: string;
    processing_note: string | null;
    raw_metadata: Record<string, any> | null;
  }>;
}

const PAIRING_LABELS: Record<string, string> = {
  first_last: 'First-In / Last-Out',
  in_out_pairs: 'Sequential In/Out Pairs',
  device_state: 'Device Punch Type (IN/OUT)',
};

function PunchTypePill({ type, label }: { type: string; label: string }) {
  const cls =
    type === 'in'
      ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
      : type === 'out'
        ? 'bg-blue-50 text-blue-700 border-blue-200'
        : 'bg-gray-100 text-gray-600 border-gray-200';
  return <Badge variant="outline" className={cls}>{label}</Badge>;
}

function StatusPill({ status, label }: { status: string; label: string }) {
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

export function RawLogsAuditDialog({
  target,
  canReprocess,
  onClose,
}: {
  target: AuditTarget | null;
  canReprocess: boolean;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const [data, setData] = useState<RawLogsPayload | null>(null);
  const [loading, setLoading] = useState(false);
  const [recalculating, setRecalculating] = useState(false);

  const load = (tgt: AuditTarget) => {
    setLoading(true);
    setData(null);
    axios
      .get(route('hr.biometric-sync.raw-logs', { userId: tgt.userId, date: tgt.date }))
      .then((res) => setData(res.data))
      .catch(() => toast.error(t('Could not load raw biometric logs for this attendance date.')))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    if (target) {
      load(target);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [target?.userId, target?.date]);

  const recalculate = (forceReleaseOverride: boolean) => {
    if (!data?.processed_record || !target) return;
    setRecalculating(true);
    toast.loading(t('Recalculating daily attendance...'));
    router.post(
      route('hr.biometric-sync.recalculate', data.processed_record.id),
      { force_release_override: forceReleaseOverride },
      {
        ...visitCallbacks(t, () => load(target)),
        onFinish: () => setRecalculating(false),
      },
    );
  };

  const rec = data?.processed_record ?? null;

  return (
    <Dialog open={!!target} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-5xl max-h-[88vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Fingerprint className="h-5 w-5 text-primary" />
            {t('Raw Biometric Logs vs Processed Daily Attendance')}
          </DialogTitle>
          <DialogDescription>
            {data
              ? `${data.employee.name ?? ''} (${data.employee.employee_code ?? ''}) · ${t('Attendance Date')}: ${data.attendance_date}`
              : `${target?.employeeName ?? ''} · ${target?.date ?? ''}`}
          </DialogDescription>
        </DialogHeader>

        {loading && (
          <div className="flex items-center justify-center py-12 text-muted-foreground">
            <Loader2 className="h-5 w-5 animate-spin mr-2" />
            {t('Loading raw punches and attendance rules...')}
          </div>
        )}

        {!loading && data && (
          <div className="space-y-4">
            {/* Processed Daily Attendance Summary */}
            <div className="rounded-lg border p-4 bg-muted/20 space-y-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="font-semibold text-sm flex items-center gap-2">
                  <Clock className="h-4 w-4 text-primary" />
                  {t('Processed Daily Attendance')}
                </div>
                {rec ? (
                  <div className="flex flex-wrap items-center gap-1.5">
                    <Badge variant="outline" className="capitalize">{t(rec.status.replace('_', ' '))}</Badge>
                    <Badge variant="secondary" className="capitalize">{t(rec.source)}</Badge>
                    {rec.is_manual_override && (
                      <Badge variant="outline" className="bg-purple-50 text-purple-700 border-purple-200">
                        <Lock className="h-3 w-3 mr-1" /> {t('Manual / Regularized Override')}
                      </Badge>
                    )}
                    {rec.is_missing_clock_out && (
                      <Badge variant="outline" className="bg-red-50 text-red-700 border-red-200">
                        <AlertTriangle className="h-3 w-3 mr-1" /> {t('Missing Clock-Out')}
                      </Badge>
                    )}
                    {rec.shift?.is_night_shift && (
                      <Badge variant="outline" className="bg-indigo-50 text-indigo-700 border-indigo-200">
                        <Moon className="h-3 w-3 mr-1" /> {t('Overnight Shift')}
                      </Badge>
                    )}
                  </div>
                ) : (
                  <Badge variant="outline">{t('No Processed Record')}</Badge>
                )}
              </div>

              {rec ? (
                <>
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                    <div className="rounded-md border bg-background p-2.5">
                      <div className="text-[11px] text-muted-foreground">{t('Clock In / Out')}</div>
                      <div className="font-mono font-medium mt-0.5">
                        {rec.clock_in || '—'} → {rec.clock_out || (rec.is_missing_clock_out ? t('Missing') : '—')}
                      </div>
                    </div>
                    <div className="rounded-md border bg-background p-2.5">
                      <div className="text-[11px] text-muted-foreground">{t('Work / Break Hours')}</div>
                      <div className="font-medium mt-0.5">
                        {rec.total_hours.toFixed(2)}h / {rec.break_hours.toFixed(2)}h
                      </div>
                    </div>
                    <div className="rounded-md border bg-background p-2.5">
                      <div className="text-[11px] text-muted-foreground">{t('Overtime')}</div>
                      <div className="font-medium mt-0.5">
                        {rec.overtime_hours > 0
                          ? `${rec.overtime_hours.toFixed(2)}h (${rec.overtime_amount.toFixed(2)})`
                          : '0.00h'}
                      </div>
                    </div>
                    <div className="rounded-md border bg-background p-2.5">
                      <div className="text-[11px] text-muted-foreground">{t('Late / Early Departure')}</div>
                      <div className="font-medium mt-0.5 space-x-1.5">
                        {rec.is_late ? (
                          <span className="text-amber-600">{t('Late')} {rec.late_minutes}m</span>
                        ) : (
                          <span className="text-muted-foreground">{t('On time')}</span>
                        )}
                        <span>·</span>
                        {rec.is_early_departure ? (
                          <span className="text-orange-600">{t('Early')} {rec.early_departure_minutes}m</span>
                        ) : (
                          <span className="text-muted-foreground">0m</span>
                        )}
                      </div>
                    </div>
                  </div>

                  {/* Applied Shift & Policy Rules */}
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs text-muted-foreground pt-1">
                    <div className="rounded-md border bg-background p-2.5">
                      <span className="font-medium text-foreground">{t('Shift')}:</span>{' '}
                      {rec.shift
                        ? `${rec.shift.name} (${rec.shift.start_time} – ${rec.shift.end_time}, ${t('Grace')}: ${rec.shift.grace_period}m)`
                        : t('None assigned')}
                    </div>
                    <div className="rounded-md border bg-background p-2.5">
                      <span className="font-medium text-foreground">{t('Policy')}:</span>{' '}
                      {rec.policy
                        ? `${rec.policy.name} · ${t(PAIRING_LABELS[rec.policy.punch_pairing_mode] ?? rec.policy.punch_pairing_mode)} · ${t('Grace')}: ${rec.policy.late_arrival_grace}m/${rec.policy.early_departure_grace}m · ${t('Dedupe')}: ${rec.policy.duplicate_punch_window_sec}s${rec.policy.auto_detect_shift ? ` · ${t('Auto-Shift')}` : ''}`
                        : t('Default rules (First-In / Last-Out)')}
                    </div>
                  </div>
                </>
              ) : (
                <p className="text-xs text-muted-foreground">
                  {t('No processed daily attendance record exists for this date yet.')}
                </p>
              )}
            </div>

            {/* Attendance Regularization info if present */}
            {data.regularization && (
              <Alert>
                <FileCheck2 className="h-4 w-4" />
                <AlertTitle className="text-sm flex items-center gap-2">
                  {t('Attendance Regularization')}
                  <Badge variant="outline" className="capitalize">{t(data.regularization.status)}</Badge>
                </AlertTitle>
                <AlertDescription className="text-xs space-y-0.5 mt-1">
                  <div>
                    {t('Requested')}: <span className="font-mono">{data.regularization.requested_clock_in || '—'} → {data.regularization.requested_clock_out || '—'}</span>
                    {data.regularization.approved_by && ` · ${t('Approved by')} ${data.regularization.approved_by}`}
                  </div>
                  {data.regularization.reason && <div className="text-muted-foreground">{data.regularization.reason}</div>}
                </AlertDescription>
              </Alert>
            )}

            {/* Manual override protection notice */}
            {rec?.is_manual_override && (
              <Alert className="border-purple-200 bg-purple-50/50 dark:bg-purple-950/20">
                <ShieldAlert className="h-4 w-4 text-purple-600" />
                <AlertTitle className="text-sm text-purple-800 dark:text-purple-200">
                  {t('Manual / Regularized Correction Active')}
                </AlertTitle>
                <AlertDescription className="text-xs text-purple-700 dark:text-purple-300">
                  {t('Incoming biometric punches are preserved in raw logs below, but will not overwrite this manually corrected attendance record unless you explicitly release the override.')}
                </AlertDescription>
              </Alert>
            )}

            {/* Raw Biometric Punches Table */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <h4 className="text-sm font-semibold">
                  {t('Raw Biometric Logs')} ({data.raw_punches.length})
                </h4>
                <span className="text-xs text-muted-foreground">
                  {t('Immutable audit trail captured from biometric hardware')}
                </span>
              </div>
              <div className="rounded-lg border overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>{t('Device / PIN')}</TableHead>
                      <TableHead>{t('Punch Time (Local / UTC)')}</TableHead>
                      <TableHead>{t('Type / Verify')}</TableHead>
                      <TableHead>{t('Source / Orig Log ID')}</TableHead>
                      <TableHead>{t('Processing Status')}</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.raw_punches.map((p) => (
                      <TableRow key={p.id}>
                        <TableCell className="text-xs">
                          <div className="font-medium">{p.device_name || '—'}</div>
                          <div className="font-mono text-muted-foreground">PIN: {p.device_user_id}</div>
                        </TableCell>
                        <TableCell className="text-xs whitespace-nowrap">
                          <div className="font-mono font-medium">{p.punched_at_local}</div>
                          <div className="text-[11px] text-muted-foreground">
                            {p.device_timezone || 'UTC'} · {t('Sync')}: {formatDateTime(p.synced_at)}
                          </div>
                        </TableCell>
                        <TableCell>
                          <div className="flex items-center gap-1.5">
                            <PunchTypePill type={p.punch_type} label={t(p.punch_type_label)} />
                            <span className="text-xs text-muted-foreground">{t(p.verification_method_label)}</span>
                          </div>
                        </TableCell>
                        <TableCell className="text-xs">
                          <div>{t(p.source_label)}</div>
                          <div className="font-mono text-muted-foreground">{p.original_device_log_id || '—'}</div>
                        </TableCell>
                        <TableCell className="text-xs">
                          <StatusPill status={p.processing_status} label={t(p.processing_status_label)} />
                          {p.processing_note && (
                            <div className="text-[11px] text-muted-foreground mt-0.5 max-w-xs">{p.processing_note}</div>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                {data.raw_punches.length === 0 && (
                  <p className="p-6 text-center text-xs text-muted-foreground">
                    {t('No raw biometric punches recorded for this attendance date.')}
                  </p>
                )}
              </div>
            </div>
          </div>
        )}

        <DialogFooter className="gap-2 sm:justify-between">
          <div>
            {canReprocess && rec && (
              <div className="flex flex-wrap gap-2">
                {!rec.is_manual_override ? (
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={recalculating}
                    onClick={() => recalculate(false)}
                  >
                    <RefreshCw className={`h-3.5 w-3.5 mr-1.5 ${recalculating ? 'animate-spin' : ''}`} />
                    {t('Recalculate from Raw Logs')}
                  </Button>
                ) : (
                  <Button
                    size="sm"
                    variant="destructive"
                    disabled={recalculating}
                    onClick={() => recalculate(true)}
                  >
                    <Unlock className="h-3.5 w-3.5 mr-1.5" />
                    {t('Release Override & Recalculate')}
                  </Button>
                )}
              </div>
            )}
          </div>
          <Button size="sm" variant="secondary" onClick={onClose}>
            {t('Close')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
