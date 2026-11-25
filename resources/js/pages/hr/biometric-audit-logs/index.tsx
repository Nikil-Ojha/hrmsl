// pages/hr/biometric-audit-logs/index.tsx
import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageTemplate } from '@/components/page-template';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Pagination } from '@/components/ui/pagination';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
  Activity, CalendarCheck, Cpu, Eye, FileText, Search, ShieldCheck, X,
} from 'lucide-react';
import { formatDateTime } from '../biometric-devices/components/shared';

interface AuditRow {
  id: number;
  actor_name: string;
  actor_email: string | null;
  actor_type: string;
  category: string;
  action: string;
  target_type: string | null;
  target_id: number | null;
  target_label: string | null;
  device_id: number | null;
  device_name: string | null;
  employee_id: number | null;
  employee_name: string | null;
  description: string;
  before_state: Record<string, any> | null;
  after_state: Record<string, any> | null;
  metadata: Record<string, any> | null;
  ip_address: string | null;
  occurred_at: string | null;
}

const CATEGORY_STYLES: Record<string, { label: string; cls: string }> = {
  device: { label: 'Device', cls: 'bg-blue-50 text-blue-700 border-blue-200' },
  enrollment: { label: 'Enrollment', cls: 'bg-indigo-50 text-indigo-700 border-indigo-200' },
  sync: { label: 'Sync', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  command: { label: 'Command', cls: 'bg-amber-50 text-amber-700 border-amber-200' },
  attendance_correction: { label: 'Manual Correction', cls: 'bg-purple-50 text-purple-700 border-purple-200' },
  settings: { label: 'Settings', cls: 'bg-rose-50 text-rose-700 border-rose-200' },
  agent: { label: 'Local Agent', cls: 'bg-cyan-50 text-cyan-700 border-cyan-200' },
};

export default function BiometricAuditLogsIndex() {
  const { t } = useTranslation();
  const { logs, stats, options, filters = {} } = usePage().props as any;

  const [search, setSearch] = useState<string>(filters.search || '');
  const [category, setCategory] = useState<string>(filters.category || '_empty_');
  const [deviceId, setDeviceId] = useState<string>(filters.device_id || '_empty_');
  const [dateFrom, setDateFrom] = useState<string>(filters.date_from || '');
  const [dateTo, setDateTo] = useState<string>(filters.date_to || '');
  const [selected, setSelected] = useState<AuditRow | null>(null);

  const apply = (extra: Record<string, any> = {}) => {
    router.get(route('hr.biometric-audit-logs.index'), {
      search: search || undefined,
      category: category !== '_empty_' ? category : undefined,
      device_id: deviceId !== '_empty_' ? deviceId : undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
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
  }, [category, deviceId, dateFrom, dateTo]);

  const reset = () => {
    setSearch('');
    setCategory('_empty_');
    setDeviceId('_empty_');
    setDateFrom('');
    setDateTo('');
    router.get(route('hr.biometric-audit-logs.index'), {}, { preserveState: true, preserveScroll: true });
  };

  const hasFilters = Boolean(search) || category !== '_empty_' || deviceId !== '_empty_' || Boolean(dateFrom) || Boolean(dateTo);

  return (
    <PageTemplate
      title={t('Biometric Audit Logs')}
      description={t('Immutable audit trail for biometric enrollments, device changes, synchronizations, executed commands and manual attendance corrections')}
      url="/hr/biometric-audit-logs"
      breadcrumbs={[
        { title: t('Dashboard'), href: route('dashboard') },
        { title: t('Attendance') },
        { title: t('Biometric Audit Logs') },
      ]}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        {[
          { label: t('Total Audit Entries'), value: stats?.total ?? 0, icon: ShieldCheck, cls: 'bg-blue-100 text-blue-600' },
          { label: t("Today's Logged Actions"), value: stats?.today ?? 0, icon: Activity, cls: 'bg-emerald-100 text-emerald-600' },
          { label: t('Manual Corrections'), value: stats?.corrections ?? 0, icon: CalendarCheck, cls: 'bg-purple-100 text-purple-600' },
          { label: t('Device & Command Actions'), value: stats?.commands_and_devices ?? 0, icon: Cpu, cls: 'bg-amber-100 text-amber-600' },
        ].map((c) => (
          <div key={c.label} className="bg-white dark:bg-gray-800 rounded-xl border p-5 shadow-sm flex items-start justify-between">
            <div>
              <p className="text-sm font-medium text-muted-foreground mb-1">{c.label}</p>
              <p className="text-2xl font-bold">{c.value}</p>
            </div>
            <div className={`p-3 rounded-xl ${c.cls}`}><c.icon className="h-5 w-5" /></div>
          </div>
        ))}
      </div>

      <Card>
        <CardContent className="pt-4">
          <div className="flex flex-wrap items-center gap-3">
            <form onSubmit={(e) => { e.preventDefault(); apply(); }} className="flex items-center gap-2">
              <div className="relative">
                <Search className="h-4 w-4 absolute left-2.5 top-2.5 text-muted-foreground" />
                <Input
                  className="pl-8 w-60 h-9"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder={t('Actor, action, target, IP...')}
                />
              </div>
              <Button type="submit" size="sm" variant="outline">{t('Search')}</Button>
            </form>

            <Select value={category} onValueChange={setCategory}>
              <SelectTrigger className="w-52 h-9"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="_empty_">{t('All Categories')}</SelectItem>
                {(options?.categories ?? []).map((c: { value: string; label: string }) => (
                  <SelectItem key={c.value} value={c.value}>{t(c.label)}</SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Select value={deviceId} onValueChange={setDeviceId}>
              <SelectTrigger className="w-48 h-9"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="_empty_">{t('All Devices')}</SelectItem>
                {(options?.devices ?? []).map((d: any) => (
                  <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Input type="date" className="w-36 h-9" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
            <Input type="date" className="w-36 h-9" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />

            {hasFilters && (
              <Button size="sm" variant="ghost" onClick={reset}>
                <X className="h-3.5 w-3.5 mr-1" /> {t('Reset')}
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="p-0 overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t('Timestamp')}</TableHead>
                <TableHead>{t('Actor')}</TableHead>
                <TableHead>{t('Category / Action')}</TableHead>
                <TableHead>{t('Target / Device')}</TableHead>
                <TableHead>{t('Description')}</TableHead>
                <TableHead>{t('IP Address')}</TableHead>
                <TableHead className="text-right">{t('Details')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {(logs?.data ?? []).map((l: AuditRow) => {
                const style = CATEGORY_STYLES[l.category] ?? { label: l.category, cls: '' };
                const hasDiff = Boolean(l.before_state || l.after_state || l.metadata);

                return (
                  <TableRow key={l.id}>
                    <TableCell className="text-xs whitespace-nowrap">{formatDateTime(l.occurred_at)}</TableCell>
                    <TableCell>
                      <div className="text-sm font-medium">{l.actor_name}</div>
                      {l.actor_email && <div className="text-xs text-muted-foreground">{l.actor_email}</div>}
                    </TableCell>
                    <TableCell>
                      <Badge variant="outline" className={style.cls}>{t(style.label)}</Badge>
                      <div className="text-[11px] font-mono text-muted-foreground mt-0.5">{l.action}</div>
                    </TableCell>
                    <TableCell className="text-xs">
                      {l.device_id ? (
                        <Link href={route('hr.biometric-devices.show', l.device_id)} className="font-medium text-primary hover:underline">
                          {l.device_name || l.target_label}
                        </Link>
                      ) : l.employee_id ? (
                        <Link href={route('hr.employees.show', l.employee_id)} className="font-medium text-primary hover:underline">
                          {l.target_label || l.employee_name}
                        </Link>
                      ) : (
                        <span className="font-medium">{l.target_label || '—'}</span>
                      )}
                      {l.target_type && <div className="text-[11px] text-muted-foreground">{l.target_type}{l.target_id ? ` #${l.target_id}` : ''}</div>}
                    </TableCell>
                    <TableCell className="text-xs max-w-md">{l.description}</TableCell>
                    <TableCell className="text-xs font-mono whitespace-nowrap">{l.ip_address || '—'}</TableCell>
                    <TableCell className="text-right">
                      {hasDiff && (
                        <Button size="sm" variant="ghost" onClick={() => setSelected(l)} title={t('Inspect state diff')}>
                          <Eye className="h-4 w-4 text-slate-600" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>

          {logs?.data?.length === 0 && (
            <p className="p-8 text-center text-sm text-muted-foreground">{t('No biometric audit log entries found.')}</p>
          )}

          {logs?.total > 0 && (
            <Pagination
              from={logs.from}
              to={logs.to}
              total={logs.total}
              links={logs.links}
              entityName={t('entries')}
              onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })}
              currentPerPage="20"
              onPerPageChange={() => {}}
              hidePerPage
            />
          )}
        </CardContent>
      </Card>

      <Dialog open={!!selected} onOpenChange={(open) => !open && setSelected(null)}>
        <DialogContent className="max-w-3xl max-h-[85vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <FileText className="h-4 w-4 text-primary" />
              {selected?.action}
            </DialogTitle>
            <DialogDescription>
              {selected ? `${selected.actor_name} · ${formatDateTime(selected.occurred_at)} · ${selected.ip_address ?? ''}` : ''}
            </DialogDescription>
          </DialogHeader>

          {selected && (
            <div className="space-y-4 text-xs">
              <p className="text-sm">{selected.description}</p>

              {(selected.before_state || selected.after_state) && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <div>
                    <div className="font-semibold text-muted-foreground mb-1">{t('Before')}</div>
                    <pre className="rounded-md border bg-muted/40 p-3 overflow-x-auto font-mono text-[11px]">
                      {selected.before_state ? JSON.stringify(selected.before_state, null, 2) : '—'}
                    </pre>
                  </div>
                  <div>
                    <div className="font-semibold text-muted-foreground mb-1">{t('After')}</div>
                    <pre className="rounded-md border bg-muted/40 p-3 overflow-x-auto font-mono text-[11px]">
                      {selected.after_state ? JSON.stringify(selected.after_state, null, 2) : '—'}
                    </pre>
                  </div>
                </div>
              )}

              {selected.metadata && (
                <div>
                  <div className="font-semibold text-muted-foreground mb-1">{t('Metadata')}</div>
                  <pre className="rounded-md border bg-muted/40 p-3 overflow-x-auto font-mono text-[11px]">
                    {JSON.stringify(selected.metadata, null, 2)}
                  </pre>
                </div>
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>
    </PageTemplate>
  );
}
