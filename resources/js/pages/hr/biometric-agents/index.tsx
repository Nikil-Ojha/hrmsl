// pages/hr/biometric-agents/index.tsx
import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageTemplate } from '@/components/page-template';
import { SearchAndFilterBar } from '@/components/ui/search-and-filter-bar';
import { Pagination } from '@/components/ui/pagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { hasPermission } from '@/utils/authorization';
import { toast } from '@/components/custom-toast';
import { Plus, Server, Wifi, Clock, ShieldOff, Database, Eye, Edit, Trash2, AlertTriangle, LayoutGrid, CheckCircle2, KeyRound } from 'lucide-react';
import { ConnectionBadge, formatDateTime, timeAgo, visitCallbacks } from '../biometric-devices/components/shared';
import { AgentFormDialog, ProvisioningBundle, ProvisioningDialog } from './components/agent-dialogs';

function AgentStatusBadge({ status }: { status: string }) {
  const { t } = useTranslation();
  const styles: Record<string, string> = {
    active: 'bg-green-50 text-green-700 border-green-200',
    pending: 'bg-amber-50 text-amber-700 border-amber-200',
    revoked: 'bg-red-50 text-red-700 border-red-200',
  };
  const labels: Record<string, string> = {
    active: t('Active'),
    pending: t('Pending Setup'),
    revoked: t('Revoked'),
  };
  return <Badge variant="outline" className={styles[status] ?? ''}>{labels[status] ?? status}</Badge>;
}

export default function BiometricAgents() {
  const { t } = useTranslation();
  const { auth, agents, stats, filters: pageFilters = {}, options, provisioningBundle } = usePage().props as any;
  const permissions: string[] = auth?.permissions || [];

  const [searchTerm, setSearchTerm] = useState(pageFilters.search || '');
  const [status, setStatus] = useState(pageFilters.status || '_empty_');
  const [branch, setBranch] = useState(pageFilters.branch_id || '_empty_');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<any>(null);
  const [bundle, setBundle] = useState<ProvisioningBundle | null>(null);

  useEffect(() => {
    if (provisioningBundle) setBundle(provisioningBundle);
  }, [provisioningBundle]);

  const apply = (extra: Record<string, any> = {}) => {
    router.get(route('hr.biometric-agents.index'), {
      search: searchTerm || undefined,
      status: status !== '_empty_' ? status : undefined,
      branch_id: branch !== '_empty_' ? branch : undefined,
      per_page: pageFilters.per_page,
      page: 1,
      ...extra,
    }, { preserveState: true, preserveScroll: true });
  };

  const firstRender = useRef(true);
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false;
      return;
    }
    apply();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, branch]);

  const resetFilters = () => {
    setSearchTerm('');
    setStatus('_empty_');
    setBranch('_empty_');
    router.get(route('hr.biometric-agents.index'), { page: 1 }, { preserveState: true, preserveScroll: true });
  };

  const remove = (agent: any) => {
    if (!window.confirm(t('Delete local agent ":name"?').replace(':name', agent.name))) return;
    toast.loading(t('Deleting agent...'));
    router.delete(route('hr.biometric-agents.destroy', agent.id), visitCallbacks(t));
  };

  const pageActions = hasPermission(permissions, 'create-biometric-agents')
    ? [{ label: t('Register Agent'), icon: <Plus className="h-4 w-4 mr-2" />, variant: 'default' as const, onClick: () => { setEditing(null); setFormOpen(true); } }]
    : [];

  const statCards = [
    { label: t('Total Installations'), value: stats?.total ?? 0, icon: Server, cls: 'bg-blue-100 text-blue-600' },
    { label: t('Online Agents'), value: stats?.online ?? 0, icon: Wifi, cls: 'bg-green-100 text-green-600' },
    { label: t('Pending Provisioning'), value: stats?.pending ?? 0, icon: Clock, cls: 'bg-amber-100 text-amber-600' },
    { label: t('Queued Local Punches'), value: stats?.queued_logs ?? 0, icon: Database, cls: 'bg-slate-100 text-slate-600' },
  ];

  const statusTabs = [
    { value: '_empty_', label: t('All'), icon: <LayoutGrid className="h-4 w-4" />, count: stats?.total ?? 0 },
    { value: 'active', label: t('Active'), icon: <CheckCircle2 className="h-4 w-4" />, count: (stats?.total ?? 0) - (stats?.pending ?? 0) - (stats?.revoked ?? 0) },
    { value: 'pending', label: t('Pending'), icon: <KeyRound className="h-4 w-4" />, count: stats?.pending ?? 0 },
    { value: 'revoked', label: t('Revoked'), icon: <ShieldOff className="h-4 w-4" />, count: stats?.revoked ?? 0 },
  ];

  return (
    <PageTemplate
      title={t('Local Biometric Agents')}
      description={t('Manage on-site Windows / Linux agents that connect local LAN and USB biometric devices securely to HRMS over HTTPS')}
      url="/hr/biometric-agents"
      actions={pageActions}
      breadcrumbs={[{ title: t('Dashboard'), href: route('dashboard') }, { title: t('Attendance') }, { title: t('Biometric Agents') }]}
    >
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-2">
        {statCards.map((c) => (
          <div key={c.label} className="bg-white dark:bg-gray-800 rounded-xl border p-5 shadow-sm flex items-start justify-between">
            <div>
              <p className="text-sm font-medium text-muted-foreground mb-1">{c.label}</p>
              <p className="text-2xl font-bold">{c.value}</p>
            </div>
            <div className={`p-3 rounded-xl ${c.cls}`}><c.icon className="h-6 w-6" /></div>
          </div>
        ))}
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-lg shadow mb-4 border">
        <SearchAndFilterBar
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          onSearch={(e) => { e.preventDefault(); apply(); }}
          searchPlaceholder={t('Search name, identifier, hostname, IP...')}
          filters={[
            {
              name: 'branch_id',
              label: t('Branch'),
              type: 'select',
              value: branch,
              onChange: setBranch,
              options: [{ value: '_empty_', label: t('All Branches') }, ...(options?.branches ?? []).map((b: any) => ({ value: String(b.id), label: b.name }))],
            },
          ]}
          statusTabs={statusTabs}
          activeStatusTab={status}
          onStatusTabChange={setStatus}
          hasActiveFilters={() => Boolean(searchTerm || branch !== '_empty_' || status !== '_empty_')}
          activeFilterCount={() => [searchTerm, branch !== '_empty_'].filter(Boolean).length}
          onResetFilters={resetFilters}
        />
      </div>

      <div className="bg-white dark:bg-gray-900 rounded-lg shadow border overflow-hidden">
        <TooltipProvider>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t('Agent Installation')}</TableHead>
                <TableHead>{t('Site / Host')}</TableHead>
                <TableHead>{t('Status')}</TableHead>
                <TableHead>{t('Connection')}</TableHead>
                <TableHead>{t('Devices')}</TableHead>
                <TableHead>{t('Local Queue')}</TableHead>
                <TableHead>{t('Last Heartbeat')}</TableHead>
                <TableHead className="text-right">{t('Actions')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {agents?.data?.map((a: any) => (
                <TableRow key={a.id}>
                  <TableCell>
                    <div className="flex items-center gap-3">
                      <div className="p-2 rounded-lg bg-gray-100 dark:bg-gray-800"><Server className="h-4 w-4" /></div>
                      <div>
                        {hasPermission(permissions, 'view-biometric-agents') ? (
                          <Link href={route('hr.biometric-agents.show', a.id)} className="font-medium text-primary hover:underline">{a.name}</Link>
                        ) : <span className="font-medium">{a.name}</span>}
                        <div className="text-xs font-mono text-muted-foreground">{a.agent_identifier}</div>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="text-sm">{a.branch ?? t('Company-wide')}</div>
                    <div className="text-xs text-muted-foreground">{[a.hostname, a.os_info, a.agent_version ? `v${a.agent_version}` : null].filter(Boolean).join(' · ') || '—'}</div>
                  </TableCell>
                  <TableCell><AgentStatusBadge status={a.status} /></TableCell>
                  <TableCell>
                    <div className="flex items-center gap-1">
                      <ConnectionBadge status={a.connection_status} />
                      {a.last_error && (
                        <Tooltip>
                          <TooltipTrigger><AlertTriangle className="h-4 w-4 text-amber-500" /></TooltipTrigger>
                          <TooltipContent className="max-w-xs">{a.last_error}</TooltipContent>
                        </Tooltip>
                      )}
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="text-sm font-medium">{a.devices_count}</div>
                    {a.pending_discoveries_count > 0 && (
                      <div className="text-xs text-blue-600">{a.pending_discoveries_count} {t('discovered')}</div>
                    )}
                  </TableCell>
                  <TableCell className="text-sm">{a.local_queue_depth}</TableCell>
                  <TableCell className="text-xs">
                    {a.last_seen_at ? (
                      <>
                        <div>{timeAgo(a.last_seen_at)}</div>
                        <div className="text-muted-foreground">{formatDateTime(a.last_seen_at)}</div>
                      </>
                    ) : '—'}
                  </TableCell>
                  <TableCell className="text-right whitespace-nowrap">
                    {hasPermission(permissions, 'view-biometric-agents') && (
                      <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-blue-500" onClick={() => router.get(route('hr.biometric-agents.show', a.id))}><Eye className="h-4 w-4" /></Button>
                    )}
                    {hasPermission(permissions, 'edit-biometric-agents') && (
                      <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-amber-500" onClick={() => { setEditing(a); setFormOpen(true); }}><Edit className="h-4 w-4" /></Button>
                    )}
                    {hasPermission(permissions, 'delete-biometric-agents') && (
                      <Button variant="ghost" size="sm" className="h-8 w-8 p-0 text-red-500" onClick={() => remove(a)}><Trash2 className="h-4 w-4" /></Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TooltipProvider>

        {agents?.data?.length === 0 && (
          <div className="flex flex-col items-center justify-center py-14 text-center">
            <Server className="h-8 w-8 text-muted-foreground mb-3" />
            <p className="text-sm font-semibold">{t('No local agents registered yet')}</p>
            <p className="text-xs text-muted-foreground mt-1 max-w-md">{t('Register an agent installation to securely connect biometric devices inside your office network without opening firewall ports.')}</p>
          </div>
        )}

        <Pagination
          from={agents?.from || 0}
          to={agents?.to || 0}
          total={agents?.total || 0}
          links={agents?.links}
          entityName={t('agents')}
          onPageChange={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })}
          perPageOptions={[10, 25, 50, 100]}
          currentPerPage={String(pageFilters.per_page || 10)}
          onPerPageChange={(value) => apply({ per_page: value })}
        />
      </div>

      <AgentFormDialog
        open={formOpen}
        onClose={() => setFormOpen(false)}
        mode={editing ? 'edit' : 'create'}
        agentId={editing?.id}
        initial={editing?.form ?? null}
        branches={options?.branches ?? []}
      />
      <ProvisioningDialog bundle={bundle} onClose={() => setBundle(null)} />
    </PageTemplate>
  );
}
