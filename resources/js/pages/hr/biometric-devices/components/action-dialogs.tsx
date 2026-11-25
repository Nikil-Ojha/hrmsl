// pages/hr/biometric-devices/components/action-dialogs.tsx
import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { toast } from '@/components/custom-toast';
import { AlertTriangle, Copy, KeyRound, Loader2, ShieldAlert } from 'lucide-react';
import { visitCallbacks } from './shared';

/* ------------------------------------------------------------------ */
/* Delete with dependency check                                        */
/* ------------------------------------------------------------------ */

interface Dependencies {
  attendance_logs: number;
  enrolled_employees: number;
  pending_commands: number;
  blockers: { key: string; count: number; message: string }[];
  can_delete: boolean;
}

export function DeleteDeviceDialog({ device, onClose, onDeactivate }: { device: { id: number; name: string; is_active: boolean } | null; onClose: () => void; onDeactivate?: () => void }) {
  const { t } = useTranslation();
  const [deps, setDeps] = useState<Dependencies | null>(null);
  const [loading, setLoading] = useState(false);
  const [deleting, setDeleting] = useState(false);

  useEffect(() => {
    if (!device) return;
    setDeps(null);
    setLoading(true);
    axios
      .get(route('hr.biometric-devices.dependencies', device.id))
      .then((r) => setDeps(r.data))
      .catch(() => toast.error(t('Could not check device dependencies.')))
      .finally(() => setLoading(false));
  }, [device, t]);

  const confirm = () => {
    if (!device) return;
    setDeleting(true);
    router.delete(route('hr.biometric-devices.destroy', device.id), {
      ...visitCallbacks(t, onClose),
      onFinish: () => setDeleting(false),
    });
  };

  return (
    <Dialog open={!!device} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t('Delete Device')}</DialogTitle>
          <DialogDescription>{device?.name}</DialogDescription>
        </DialogHeader>

        {loading && (
          <div className="flex items-center gap-2 text-sm text-muted-foreground py-4">
            <Loader2 className="h-4 w-4 animate-spin" /> {t('Checking dependencies...')}
          </div>
        )}

        {deps && !deps.can_delete && (
          <Alert variant="destructive">
            <ShieldAlert className="h-4 w-4" />
            <AlertTitle>{t('This device cannot be deleted')}</AlertTitle>
            <AlertDescription>
              <ul className="list-disc pl-4 space-y-1 mt-1">
                {deps.blockers.map((b) => <li key={b.key}>{t(b.message)}</li>)}
              </ul>
              <p className="mt-2">{t('Deactivate the device instead to keep attendance history traceable.')}</p>
            </AlertDescription>
          </Alert>
        )}

        {deps && deps.can_delete && (
          <div className="space-y-2 text-sm">
            <p>{t('Are you sure you want to delete this device? Its synchronization history and event log will also be removed. This action cannot be undone.')}</p>
            {deps.pending_commands > 0 && (
              <p className="flex items-center gap-1 text-amber-600">
                <AlertTriangle className="h-4 w-4" /> {deps.pending_commands} {t('pending command(s) will be discarded.')}
              </p>
            )}
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>{t('Cancel')}</Button>
          {deps && !deps.can_delete && device?.is_active && onDeactivate && (
            <Button variant="secondary" onClick={onDeactivate}>{t('Deactivate Instead')}</Button>
          )}
          <Button variant="destructive" disabled={!deps?.can_delete || deleting} onClick={confirm}>
            {t('Delete')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/* ------------------------------------------------------------------ */
/* Synchronize employees                                               */
/* ------------------------------------------------------------------ */

interface PreviewEmployee {
  id: number;
  name: string;
  employee_id: string;
  biometric_code: string | null;
  on_this_device: boolean;
}

export function SyncEmployeesDialog({ deviceId, open, onClose }: { deviceId: number; open: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const [loading, setLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [summary, setSummary] = useState<any>(null);
  const [employees, setEmployees] = useState<PreviewEmployee[]>([]);
  const [mode, setMode] = useState<'all' | 'selected'>('all');
  const [selected, setSelected] = useState<number[]>([]);
  const [search, setSearch] = useState('');

  useEffect(() => {
    if (!open) return;
    setLoading(true);
    setMode('all');
    setSelected([]);
    axios
      .get(route('hr.biometric-devices.employee-preview', deviceId))
      .then((r) => {
        setSummary(r.data.summary);
        setEmployees(r.data.employees);
      })
      .catch(() => toast.error(t('Could not load employees.')))
      .finally(() => setLoading(false));
  }, [open, deviceId, t]);

  const filtered = useMemo(() => {
    const q = search.toLowerCase();
    return employees.filter((e) => !q || e.name?.toLowerCase().includes(q) || e.employee_id?.toLowerCase().includes(q) || e.biometric_code?.includes(q));
  }, [employees, search]);

  const submit = () => {
    setSubmitting(true);
    toast.loading(t('Queuing employees...'));
    router.post(
      route('hr.biometric-devices.sync-employees', deviceId),
      mode === 'selected' ? { employee_ids: selected } : {},
      { ...visitCallbacks(t, onClose), onFinish: () => setSubmitting(false) },
    );
  };

  const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{t('Synchronize Employees')}</DialogTitle>
          <DialogDescription>{t('Send employee records to the device. Fingerprint / face enrollment is done on the device afterwards.')}</DialogDescription>
        </DialogHeader>

        {loading ? (
          <div className="flex items-center gap-2 text-sm text-muted-foreground py-6">
            <Loader2 className="h-4 w-4 animate-spin" /> {t('Loading...')}
          </div>
        ) : (
          summary && (
            <div className="space-y-4">
              <div className="grid grid-cols-3 gap-3 text-center">
                <div className="rounded-md border p-3">
                  <div className="text-2xl font-semibold">{summary.in_scope}</div>
                  <div className="text-xs text-muted-foreground">{t('Employees in scope')} ({t(summary.scope)})</div>
                </div>
                <div className="rounded-md border p-3">
                  <div className="text-2xl font-semibold text-green-600">{summary.eligible}</div>
                  <div className="text-xs text-muted-foreground">{t('Ready to sync')}</div>
                </div>
                <div className="rounded-md border p-3">
                  <div className="text-2xl font-semibold text-amber-600">{summary.missing_code}</div>
                  <div className="text-xs text-muted-foreground">{t('Missing biometric code')}</div>
                </div>
              </div>

              <div className="flex gap-4 text-sm">
                <label className="flex items-center gap-2 cursor-pointer">
                  <input type="radio" checked={mode === 'all'} onChange={() => setMode('all')} /> {t('All eligible employees')}
                </label>
                <label className="flex items-center gap-2 cursor-pointer">
                  <input type="radio" checked={mode === 'selected'} onChange={() => setMode('selected')} /> {t('Selected employees')}
                </label>
              </div>

              {mode === 'selected' && (
                <div className="space-y-2">
                  <Input placeholder={t('Search employees...')} value={search} onChange={(e) => setSearch(e.target.value)} />
                  <div className="max-h-64 overflow-y-auto rounded-md border divide-y">
                    {filtered.map((e) => (
                      <label key={e.id} className={`flex items-center justify-between gap-3 px-3 py-2 text-sm ${e.biometric_code ? 'cursor-pointer' : 'opacity-50'}`}>
                        <span className="flex items-center gap-2">
                          <Checkbox disabled={!e.biometric_code} checked={selected.includes(e.id)} onCheckedChange={() => toggle(e.id)} />
                          {e.name} <span className="text-xs text-muted-foreground">{e.employee_id}</span>
                        </span>
                        <span className="text-xs text-muted-foreground">
                          {e.biometric_code ? `#${e.biometric_code}` : t('No code')}
                          {e.on_this_device && <span className="ml-2 text-green-600">{t('on device')}</span>}
                        </span>
                      </label>
                    ))}
                    {filtered.length === 0 && <p className="p-3 text-sm text-muted-foreground">{t('No employees found')}</p>}
                  </div>
                </div>
              )}
            </div>
          )
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>{t('Cancel')}</Button>
          <Button onClick={submit} disabled={submitting || loading || !summary?.eligible || (mode === 'selected' && selected.length === 0)}>
            {mode === 'selected' ? t('Sync :count employee(s)').replace(':count', String(selected.length)) : t('Sync All')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/* ------------------------------------------------------------------ */
/* Fetch attendance logs                                               */
/* ------------------------------------------------------------------ */

const toLocalInput = (d: Date) => {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

export function FetchLogsDialog({ deviceId, open, onClose, lastFetchTo }: { deviceId: number; open: boolean; onClose: () => void; lastFetchTo?: string | null }) {
  const { t } = useTranslation();
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) return;
    const now = new Date();
    const start = lastFetchTo ? new Date(lastFetchTo) : new Date(now.getTime() - 7 * 86400000);
    setFrom(toLocalInput(start));
    setTo(toLocalInput(now));
  }, [open, lastFetchTo]);

  const submit = () => {
    setSubmitting(true);
    toast.loading(t('Queuing log fetch...'));
    router.post(
      route('hr.biometric-devices.fetch-logs', deviceId),
      { from: from ? new Date(from).toISOString() : null, to: to ? new Date(to).toISOString() : null },
      { ...visitCallbacks(t, onClose), onFinish: () => setSubmitting(false) },
    );
  };

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t('Fetch Attendance Logs')}</DialogTitle>
          <DialogDescription>{t('Ask the device to re-upload punches recorded in this period (max 92 days). Duplicates are ignored.')}</DialogDescription>
        </DialogHeader>
        <div className="grid grid-cols-1 gap-4">
          <div className="space-y-1.5">
            <Label>{t('From')}</Label>
            <Input type="datetime-local" value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label>{t('To')}</Label>
            <Input type="datetime-local" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>{t('Cancel')}</Button>
          <Button onClick={submit} disabled={submitting || !from || !to}>{t('Fetch Logs')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/* ------------------------------------------------------------------ */
/* One-time API key display                                            */
/* ------------------------------------------------------------------ */

export function ApiKeyDialog({ apiKey, onClose }: { apiKey: string | null; onClose: () => void }) {
  const { t } = useTranslation();
  const copy = () => {
    if (!apiKey) return;
    navigator.clipboard?.writeText(apiKey).then(() => toast.success(t('Copied to clipboard')));
  };

  return (
    <Dialog open={!!apiKey} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2"><KeyRound className="h-5 w-5" /> {t('Device API Key')}</DialogTitle>
          <DialogDescription>{t('Copy this key into the device configuration now. For security it will not be shown again.')}</DialogDescription>
        </DialogHeader>
        <div className="flex items-center gap-2">
          <code className="flex-1 break-all rounded-md border bg-muted px-3 py-2 text-xs font-mono">{apiKey}</code>
          <Button size="icon" variant="outline" onClick={copy} title={t('Copy')}><Copy className="h-4 w-4" /></Button>
        </div>
        <DialogFooter>
          <Button onClick={onClose}>{t('I have saved the key')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
