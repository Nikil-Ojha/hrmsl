// pages/hr/biometric-agents/components/agent-dialogs.tsx
import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from '@/components/custom-toast';
import { Copy, KeyRound, Terminal } from 'lucide-react';

export interface AgentFormValues {
  name: string;
  agent_identifier: string;
  branch_id: number | string | null;
  heartbeat_interval_sec: number | string;
  command_poll_interval_sec: number | string;
  log_poll_interval_sec: number | string;
  log_batch_size: number | string;
  discovery_enabled: boolean;
  discovery_cidrs: string;
  notes: string;
}

export interface ProvisioningBundle {
  agent_identifier: string;
  provisioning_token: string;
  expires_at: string | null;
  server_url: string;
  cli_command: string;
}

const NONE = '_none_';

const emptyAgent = (): AgentFormValues => ({
  name: '',
  agent_identifier: '',
  branch_id: null,
  heartbeat_interval_sec: 60,
  command_poll_interval_sec: 15,
  log_poll_interval_sec: 60,
  log_batch_size: 200,
  discovery_enabled: false,
  discovery_cidrs: '',
  notes: '',
});

export function AgentFormDialog({
  open,
  onClose,
  mode,
  agentId,
  initial,
  branches,
}: {
  open: boolean;
  onClose: () => void;
  mode: 'create' | 'edit';
  agentId?: number;
  initial?: AgentFormValues | null;
  branches: { id: number; name: string }[];
}) {
  const { t } = useTranslation();
  const form = useForm<AgentFormValues>(initial ?? emptyAgent());
  const { data, setData, errors, processing } = form;

  useEffect(() => {
    if (open) {
      form.clearErrors();
      form.setData(initial ? { ...initial } : emptyAgent());
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initial]);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    const callbacks = {
      preserveScroll: true,
      onSuccess: (page: any) => {
        toast.dismiss();
        if (page.props.flash?.error) {
          toast.error(t(page.props.flash.error));
          return;
        }
        if (page.props.flash?.success) toast.success(t(page.props.flash.success));
        onClose();
      },
      onError: () => {
        toast.dismiss();
        toast.error(t('Please correct the highlighted fields.'));
      },
    };

    if (mode === 'create') {
      form.post(route('hr.biometric-agents.store'), callbacks);
    } else if (agentId) {
      form.put(route('hr.biometric-agents.update', agentId), callbacks);
    }
  };

  const err = (k: string) => (errors as Record<string, string>)[k];

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{mode === 'create' ? t('Register Local Biometric Agent') : t('Edit Local Biometric Agent')}</DialogTitle>
          <DialogDescription>
            {t('An agent runs on a Windows or Linux computer inside your office network and bridges local biometric devices to HRMS over HTTPS.')}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-5">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label>{t('Installation Name')} <span className="text-red-500">*</span></Label>
              <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder={t('e.g. HQ Reception PC')} />
              {err('name') && <p className="text-xs text-red-500">{err('name')}</p>}
            </div>
            <div className="space-y-1.5">
              <Label>{t('Agent Identifier')}</Label>
              <Input value={data.agent_identifier} onChange={(e) => setData('agent_identifier', e.target.value)} placeholder={t('Auto-generated if blank')} />
              {err('agent_identifier') && <p className="text-xs text-red-500">{err('agent_identifier')}</p>}
            </div>
            <div className="space-y-1.5">
              <Label>{t('Branch / Site')}</Label>
              <Select value={data.branch_id ? String(data.branch_id) : NONE} onValueChange={(v) => setData('branch_id', v === NONE ? null : v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>{t('All branches / Company-wide')}</SelectItem>
                  {branches.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>)}
                </SelectContent>
              </Select>
              {err('branch_id') && <p className="text-xs text-red-500">{err('branch_id')}</p>}
            </div>
            <div className="space-y-1.5">
              <Label>{t('Log Batch Size')}</Label>
              <Input type="number" min={10} max={500} value={data.log_batch_size} onChange={(e) => setData('log_batch_size', e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label>{t('Heartbeat Interval (sec)')}</Label>
              <Input type="number" min={15} max={600} value={data.heartbeat_interval_sec} onChange={(e) => setData('heartbeat_interval_sec', e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label>{t('Command Poll Interval (sec)')}</Label>
              <Input type="number" min={5} max={300} value={data.command_poll_interval_sec} onChange={(e) => setData('command_poll_interval_sec', e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label>{t('Log Poll Interval (sec)')}</Label>
              <Input type="number" min={15} max={3600} value={data.log_poll_interval_sec} onChange={(e) => setData('log_poll_interval_sec', e.target.value)} />
            </div>
            <div className="flex items-start gap-3 pt-6">
              <Switch id="discovery_enabled" checked={!!data.discovery_enabled} onCheckedChange={(v) => setData('discovery_enabled', v)} />
              <div>
                <Label htmlFor="discovery_enabled">{t('Enable Local Device Discovery')}</Label>
                <p className="text-xs text-muted-foreground">{t('Periodically scan configured LAN subnets and USB ports for devices.')}</p>
              </div>
            </div>
          </div>

          {data.discovery_enabled && (
            <div className="space-y-1.5">
              <Label>{t('Discovery Subnets (CIDR, comma-separated)')}</Label>
              <Input value={data.discovery_cidrs} onChange={(e) => setData('discovery_cidrs', e.target.value)} placeholder="192.168.1.0/24, 10.0.10.0/24" />
              <p className="text-xs text-muted-foreground">{t('Restrict LAN scanning to specific office subnets.')}</p>
              {err('discovery_cidrs') && <p className="text-xs text-red-500">{err('discovery_cidrs')}</p>}
            </div>
          )}

          <div className="space-y-1.5">
            <Label>{t('Notes')}</Label>
            <Textarea rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder={t('e.g. Installed on Windows 11 reception workstation')} />
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>{t('Cancel')}</Button>
            <Button type="submit" disabled={processing}>{mode === 'create' ? t('Register & Generate Token') : t('Save Changes')}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export function ProvisioningDialog({ bundle, onClose }: { bundle: ProvisioningBundle | null; onClose: () => void }) {
  const { t } = useTranslation();
  const copy = (text: string, label: string) => {
    navigator.clipboard?.writeText(text).then(() => toast.success(t(':label copied to clipboard').replace(':label', label)));
  };

  return (
    <Dialog open={!!bundle} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <KeyRound className="h-5 w-5 text-primary" /> {t('One-Time Agent Provisioning Credentials')}
          </DialogTitle>
          <DialogDescription>
            {t('Run this provisioning command on the office machine where the Local Device Communication Agent is installed. This token expires in 24 hours and will not be shown again.')}
          </DialogDescription>
        </DialogHeader>

        {bundle && (
          <div className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
              <div className="rounded-md border p-3">
                <div className="text-muted-foreground">{t('Agent Identifier')}</div>
                <div className="font-mono font-semibold text-sm mt-1">{bundle.agent_identifier}</div>
              </div>
              <div className="rounded-md border p-3">
                <div className="text-muted-foreground">{t('HRMS Server URL')}</div>
                <div className="font-mono text-sm mt-1 break-all">{bundle.server_url}</div>
              </div>
            </div>

            <div className="space-y-1.5">
              <Label>{t('One-Time Provisioning Token')}</Label>
              <div className="flex items-center gap-2">
                <code className="flex-1 break-all rounded-md border bg-muted px-3 py-2 text-xs font-mono">{bundle.provisioning_token}</code>
                <Button size="icon" variant="outline" onClick={() => copy(bundle.provisioning_token, t('Token'))}>
                  <Copy className="h-4 w-4" />
                </Button>
              </div>
            </div>

            <div className="space-y-1.5">
              <Label className="flex items-center gap-1.5"><Terminal className="h-4 w-4" /> {t('Provisioning Command (Windows / Linux)')}</Label>
              <div className="flex items-start gap-2">
                <pre className="flex-1 whitespace-pre-wrap break-all rounded-md border bg-slate-950 text-slate-100 p-3 text-xs font-mono">
                  {bundle.cli_command}
                </pre>
                <Button size="icon" variant="outline" onClick={() => copy(bundle.cli_command, t('Command'))}>
                  <Copy className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </div>
        )}

        <DialogFooter>
          <Button onClick={onClose}>{t('I have copied the provisioning details')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
