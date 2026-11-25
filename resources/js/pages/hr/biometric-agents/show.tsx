// pages/hr/biometric-agents/show.tsx
import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { PageTemplate } from '@/components/page-template';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { hasPermission } from '@/utils/authorization';
import { toast } from '@/components/custom-toast';
import { AlertCircle, ArrowLeft, Edit, KeyRound, Link2, Radar, Server, ShieldCheck, ShieldOff, Trash2, Unlink } from 'lucide-react';
import { ActiveBadge, ConnectionBadge, EventLevelIcon, formatDateTime, timeAgo, visitCallbacks } from '../biometric-devices/components/shared';
import { AgentFormDialog, ProvisioningBundle, ProvisioningDialog } from './components/agent-dialogs';

function Detail({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={`text-sm mt-0.5 break-words ${mono ? 'font-mono' : ''}`}>{value || value === 0 ? value : '—'}</dd>
    </div>
  );
}

export default function BiometricAgentShow() {
  const { t } = useTranslation();
  const { auth, agent, assignedDevices = [], unassignedDevices = [], discoveries = [], recentEvents = [], apiAuditLogs = [], provisioningBundle, options } = usePage().props as any;
  const permissions: string[] = auth?.permissions || [];

  const [editOpen, setEditOpen] = useState(false);
  const [bundle, setBundle] = useState<ProvisioningBundle | null>(null);
  const [selectedDevice, setSelectedDevice] = useState<string>('');

  useEffect(() => {
    if (provisioningBundle) setBundle(provisioningBundle);
  }, [provisioningBundle]);

  const canEdit = hasPermission(permissions, 'edit-biometric-agents');
  const canDelete = hasPermission(permissions, 'delete-biometric-agents');

  const reissue = () => {
    if (!window.confirm(t('Issue a new one-time provisioning token? Existing credentials on the local agent will immediately stop working.'))) return;
    toast.loading(t('Issuing provisioning token...'));
    router.post(route('hr.biometric-agents.reissue-token', agent.id), {}, visitCallbacks(t));
  };

  const revoke = () => {
    if (!window.confirm(t('Revoke this agent? The local installation will be blocked from accessing HRMS immediately.'))) return;
    toast.loading(t('Revoking agent...'));
    router.post(route('hr.biometric-agents.revoke', agent.id), {}, visitCallbacks(t));
  };

  const assignDevice = () => {
    if (!selectedDevice) return;
    router.post(route('hr.biometric-agents.assign-device', agent.id), { device_id: selectedDevice }, visitCallbacks(t, () => setSelectedDevice('')));
  };

  const unassign = (deviceId: number) => {
    router.delete(route('hr.biometric-agents.unassign-device', [agent.id, deviceId]), visitCallbacks(t));
  };

  const ignoreDiscovery = (discoveryId: number) => {
    router.post(route('hr.biometric-agents.ignore-discovery', [agent.id, discoveryId]), {}, visitCallbacks(t));
  };

  const remove = () => {
    if (!window.confirm(t('Delete this biometric agent installation?'))) return;
    router.delete(route('hr.biometric-agents.destroy', agent.id), visitCallbacks(t));
  };

  const pageActions = [
    { label: t('Back'), icon: <ArrowLeft className="h-4 w-4 mr-2" />, variant: 'outline' as const, onClick: () => router.get(route('hr.biometric-agents.index')) },
    ...(canEdit ? [{ label: t('Edit'), icon: <Edit className="h-4 w-4 mr-2" />, variant: 'outline' as const, onClick: () => setEditOpen(true) }] : []),
    ...(canDelete ? [{ label: t('Delete'), icon: <Trash2 className="h-4 w-4 mr-2" />, variant: 'destructive' as const, onClick: remove }] : []),
  ];

  const pendingDiscoveries = discoveries.filter((d: any) => d.status === 'discovered').length;

  return (
    <PageTemplate
      title={agent.name}
      description={`${agent.agent_identifier} · ${agent.branch ?? t('Company-wide')}`}
      url={`/hr/biometric-agents/${agent.id}`}
      actions={pageActions}
      breadcrumbs={[
        { title: t('Dashboard'), href: route('dashboard') },
        { title: t('Biometric Agents'), href: route('hr.biometric-agents.index') },
        { title: agent.name },
      ]}
    >
      <Card>
        <CardContent className="pt-6 space-y-4">
          <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div className="flex items-center gap-4">
              <div className="p-3 rounded-xl bg-primary/10 text-primary"><Server className="h-7 w-7" /></div>
              <div className="space-y-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <ConnectionBadge status={agent.connection_status} />
                  <Badge variant="outline">{t(agent.status_label)}</Badge>
                  {agent.agent_version && <Badge variant="secondary">v{agent.agent_version}</Badge>}
                </div>
                <div className="text-xs text-muted-foreground">
                  {agent.last_seen_at ? `${t('Last heartbeat')} ${timeAgo(agent.last_seen_at)} (${agent.last_seen_ip || '—'})` : t('Not connected yet')}
                  {' · '}
                  {t('Local unsynced queue')}: <strong>{agent.local_queue_depth}</strong>
                </div>
              </div>
            </div>

            {canEdit && (
              <div className="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" onClick={reissue}>
                  <KeyRound className="h-4 w-4 mr-2" /> {t('Issue New Provisioning Token')}
                </Button>
                {agent.status !== 'revoked' && (
                  <Button variant="destructive" size="sm" onClick={revoke}>
                    <ShieldOff className="h-4 w-4 mr-2" /> {t('Revoke Credentials')}
                  </Button>
                )}
              </div>
            )}
          </div>

          {agent.last_error && (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>{t('Last Reported Error')} · {formatDateTime(agent.last_error_at)}</AlertTitle>
              <AlertDescription>{agent.last_error}</AlertDescription>
            </Alert>
          )}
        </CardContent>
      </Card>

      <Tabs defaultValue="devices">
        <TabsList>
          <TabsTrigger value="devices">{t('Assigned Devices')} ({assignedDevices.length})</TabsTrigger>
          <TabsTrigger value="discoveries">
            <Radar className="h-3.5 w-3.5 mr-1.5" /> {t('Discovered Devices')}
            {pendingDiscoveries > 0 && <span className="ml-1.5 rounded-full bg-blue-600 px-1.5 text-[10px] text-white">{pendingDiscoveries}</span>}
          </TabsTrigger>
          <TabsTrigger value="overview">{t('Overview & Security')}</TabsTrigger>
          <TabsTrigger value="events">{t('Device Errors & Activity')}</TabsTrigger>
          <TabsTrigger value="audit">
            <ShieldCheck className="h-3.5 w-3.5 mr-1.5" /> {t('API Audit Logs')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="devices">
          <Card>
            <CardHeader className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 space-y-0">
              <CardTitle className="text-base">{t('Devices Managed by This Agent')}</CardTitle>
              {canEdit && unassignedDevices.length > 0 && (
                <div className="flex items-center gap-2">
                  <Select value={selectedDevice} onValueChange={setSelectedDevice}>
                    <SelectTrigger className="w-64 h-8"><SelectValue placeholder={t('Assign an existing device...')} /></SelectTrigger>
                    <SelectContent>
                      {unassignedDevices.map((d: any) => (
                        <SelectItem key={d.id} value={String(d.id)}>{d.name} ({d.serial_number})</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <Button size="sm" disabled={!selectedDevice} onClick={assignDevice}><Link2 className="h-4 w-4 mr-1" />{t('Assign')}</Button>
                </div>
              )}
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('Device')}</TableHead>
                    <TableHead>{t('Protocol / Interface')}</TableHead>
                    <TableHead>{t('Local Address')}</TableHead>
                    <TableHead>{t('Health')}</TableHead>
                    <TableHead>{t('Status')}</TableHead>
                    <TableHead className="text-right">{t('Actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {assignedDevices.map((d: any) => (
                    <TableRow key={d.id}>
                      <TableCell>
                        <Link href={route('hr.biometric-devices.show', d.id)} className="font-medium text-primary hover:underline">{d.name}</Link>
                        <div className="text-xs font-mono text-muted-foreground">{d.serial_number}</div>
                      </TableCell>
                      <TableCell className="text-sm">{d.protocol} · {d.connection_type?.toUpperCase()}</TableCell>
                      <TableCell className="text-xs font-mono">{d.ip_address ? `${d.ip_address}${d.port ? ':' + d.port : ''}` : 'USB / Local'}</TableCell>
                      <TableCell><ConnectionBadge status={d.connection_status} /></TableCell>
                      <TableCell><ActiveBadge active={d.is_active} /></TableCell>
                      <TableCell className="text-right">
                        {canEdit && (
                          <Button variant="ghost" size="sm" onClick={() => unassign(d.id)} title={t('Detach from agent')}>
                            <Unlink className="h-4 w-4 text-amber-600" />
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {assignedDevices.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">{t('No biometric devices assigned to this agent yet.')}</p>}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="discoveries">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">{t('Devices Discovered on the Local Network / USB')}</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('Interface')}</TableHead>
                    <TableHead>{t('Address / Path')}</TableHead>
                    <TableHead>{t('Serial / Hint')}</TableHead>
                    <TableHead>{t('Status')}</TableHead>
                    <TableHead>{t('Last Seen')}</TableHead>
                    <TableHead className="text-right">{t('Actions')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {discoveries.map((d: any) => (
                    <TableRow key={d.id}>
                      <TableCell className="uppercase text-xs font-medium">{d.connection_type}</TableCell>
                      <TableCell className="font-mono text-xs">{d.ip_address ? `${d.ip_address}${d.port ? ':' + d.port : ''}` : (d.usb_path || d.fingerprint)}</TableCell>
                      <TableCell className="text-xs">{[d.serial_number, d.manufacturer_hint, d.model_hint, d.protocol_hint].filter(Boolean).join(' · ') || '—'}</TableCell>
                      <TableCell>
                        {d.status === 'registered' && d.registered_device_id ? (
                          <Link href={route('hr.biometric-devices.show', d.registered_device_id)} className="text-xs text-green-700 underline">{d.registered_device_name || t('Registered')}</Link>
                        ) : (
                          <Badge variant="outline">{t(d.status)}</Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-xs">{formatDateTime(d.last_seen_at)}</TableCell>
                      <TableCell className="text-right space-x-2">
                        {d.status === 'discovered' && (
                          <>
                            <Button size="sm" variant="outline" onClick={() => router.get(route('hr.biometric-devices.index'))}>{t('Register in Devices')}</Button>
                            {canEdit && <Button size="sm" variant="ghost" onClick={() => ignoreDiscovery(d.id)}>{t('Dismiss')}</Button>}
                          </>
                        )}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {discoveries.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">{t('No devices discovered yet. Enable discovery in agent settings or run `hrms_agent discover` on the host.')}</p>}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="overview" className="grid gap-4 lg:grid-cols-2">
          <Card>
            <CardHeader><CardTitle className="text-base">{t('Host & Telemetry')}</CardTitle></CardHeader>
            <CardContent>
              <dl className="grid grid-cols-2 gap-4">
                <Detail label={t('Agent Identifier')} value={agent.agent_identifier} mono />
                <Detail label={t('Branch / Site')} value={agent.branch ?? t('Company-wide')} />
                <Detail label={t('Hostname')} value={agent.hostname} mono />
                <Detail label={t('Operating System')} value={agent.os_info} />
                <Detail label={t('Agent Version')} value={agent.agent_version} mono />
                <Detail label={t('Last Seen IP')} value={agent.last_seen_ip} mono />
                <Detail label={t('Last Log Sync')} value={formatDateTime(agent.last_sync_at)} />
                <Detail label={t('Local Queue / Errors')} value={`${agent.local_queue_depth} queued / ${agent.local_error_count} errors`} />
              </dl>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle className="text-base">{t('Security & Polling Configuration')}</CardTitle></CardHeader>
            <CardContent>
              <dl className="grid grid-cols-2 gap-4">
                <Detail label={t('Credential Prefix')} value={agent.access_token_prefix ? `agt_${agent.access_token_prefix}_••••` : t('Not provisioned')} mono />
                <Detail label={t('Provisioned At')} value={formatDateTime(agent.provisioned_at)} />
                <Detail label={t('Heartbeat / Commands / Logs')} value={`${agent.settings?.heartbeat_interval_sec}s / ${agent.settings?.command_poll_interval_sec}s / ${agent.settings?.log_poll_interval_sec}s`} />
                <Detail label={t('Log Batch Size')} value={agent.settings?.log_batch_size} />
                <Detail label={t('Discovery')} value={agent.settings?.discovery_enabled ? (agent.settings?.discovery_cidrs?.join(', ') || t('Enabled')) : t('Disabled')} />
                <Detail label={t('Drivers Reported')} value={(agent.capabilities?.drivers ?? []).join(', ') || '—'} mono />
              </dl>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="events">
          <Card>
            <CardHeader><CardTitle className="text-base">{t('Recent Activity & Errors from Assigned Devices')}</CardTitle></CardHeader>
            <CardContent className="p-0">
              <ul className="divide-y">
                {recentEvents.map((e: any) => (
                  <li key={e.id} className="flex items-start gap-3 px-6 py-3">
                    <EventLevelIcon level={e.level} />
                    <div className="flex-1">
                      <div className="text-sm"><strong>{e.device_name}</strong> — {e.message}</div>
                      <div className="text-xs text-muted-foreground"><span className="font-mono">{e.type}</span> · {formatDateTime(e.occurred_at)}</div>
                    </div>
                  </li>
                ))}
              </ul>
              {recentEvents.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">{t('No device events recorded yet.')}</p>}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="audit">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">{t('Agent API Security & Audit Log')}</CardTitle>
            </CardHeader>
            <CardContent className="p-0 overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t('Endpoint')}</TableHead>
                    <TableHead>{t('Outcome / Status')}</TableHead>
                    <TableHead>{t('Device')}</TableHead>
                    <TableHead>{t('IP / Latency')}</TableHead>
                    <TableHead>{t('Summary')}</TableHead>
                    <TableHead>{t('Timestamp')}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {apiAuditLogs.map((l: any) => (
                    <TableRow key={l.id}>
                      <TableCell className="font-mono text-xs whitespace-nowrap">
                        <Badge variant="outline" className="mr-1.5 text-[10px]">{l.method}</Badge>
                        {l.path}
                      </TableCell>
                      <TableCell className="whitespace-nowrap">
                        <Badge
                          variant="outline"
                          className={
                            l.outcome === 'success'
                              ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                              : l.outcome === 'replay_blocked' || l.outcome === 'forbidden_device' || l.outcome === 'auth_failed'
                                ? 'bg-red-50 text-red-700 border-red-200'
                                : 'bg-amber-50 text-amber-700 border-amber-200'
                          }
                        >
                          {l.status_code} · {l.outcome}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-xs">{l.device_name || '—'}</TableCell>
                      <TableCell className="font-mono text-xs whitespace-nowrap">
                        {l.ip_address || '—'} · {l.latency_ms}ms
                      </TableCell>
                      <TableCell className="text-xs max-w-xs truncate">{l.summary || '—'}</TableCell>
                      <TableCell className="text-xs whitespace-nowrap">{formatDateTime(l.created_at)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              {apiAuditLogs.length === 0 && (
                <p className="p-6 text-center text-sm text-muted-foreground">
                  {t('No API audit log entries recorded for this agent yet.')}
                </p>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <AgentFormDialog
        open={editOpen}
        onClose={() => setEditOpen(false)}
        mode="edit"
        agentId={agent.id}
        initial={agent.form}
        branches={options?.branches ?? []}
      />
      <ProvisioningDialog bundle={bundle} onClose={() => setBundle(null)} />
    </PageTemplate>
  );
}
