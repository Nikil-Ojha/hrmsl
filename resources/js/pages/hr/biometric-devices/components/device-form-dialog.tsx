// pages/hr/biometric-devices/components/device-form-dialog.tsx
import { useEffect, useMemo } from 'react';
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
import { CapabilityBadges, DeviceFormValues, DeviceOptions } from './shared';

interface Props {
  open: boolean;
  onClose: () => void;
  mode: 'create' | 'edit';
  deviceId?: number;
  initial?: DeviceFormValues | null;
  options: DeviceOptions;
  hasApiKey?: boolean;
}

const NONE = '_none_';

const emptyForm = (options: DeviceOptions): DeviceFormValues => {
  const driver = options.drivers.find((d) => d.value === 'native') ?? options.drivers[0];
  return {
    name: '',
    serial_number: '',
    device_identifier: '',
    manufacturer: '',
    model: '',
    device_type: 'fingerprint',
    protocol: driver?.value ?? '',
    connection_type: 'lan',
    ip_address: '',
    port: driver?.default_port ?? '',
    branch_id: null,
    department_id: null,
    agent_id: null,
    timezone: '',
    auth_type: driver?.auth_types[0]?.value ?? 'none',
    credentials: {},
    is_active: true,
    notes: '',
  };
};

function Field({ label, required, error, help, children }: { label: string; required?: boolean; error?: string; help?: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <Label>
        {label} {required && <span className="text-red-500">*</span>}
      </Label>
      {children}
      {help && !error && <p className="text-xs text-muted-foreground">{help}</p>}
      {error && <p className="text-xs text-red-500">{error}</p>}
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="space-y-3">
      <h4 className="text-sm font-semibold text-gray-900 dark:text-white border-b pb-1">{title}</h4>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">{children}</div>
    </div>
  );
}

export function DeviceFormDialog({ open, onClose, mode, deviceId, initial, options, hasApiKey = false }: Props) {
  const { t } = useTranslation();
  const form = useForm<DeviceFormValues>(initial ?? emptyForm(options));
  const { data, setData, errors, processing } = form;

  useEffect(() => {
    if (open) {
      form.clearErrors();
      form.setData(initial ? { ...initial, credentials: { ...(initial.credentials ?? {}) } } : emptyForm(options));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initial]);

  const driver = useMemo(() => options.drivers.find((d) => d.value === data.protocol), [options.drivers, data.protocol]);

  const departments = useMemo(
    () => options.departments.filter((d) => !data.branch_id || !d.branch_id || String(d.branch_id) === String(data.branch_id)),
    [options.departments, data.branch_id],
  );

  const onProtocolChange = (value: string) => {
    const next = options.drivers.find((d) => d.value === value);
    const authAllowed = next?.auth_types.some((a) => a.value === data.auth_type);
    form.setData((prev) => ({
      ...prev,
      protocol: value,
      port: prev.port && mode === 'edit' ? prev.port : next?.default_port ?? '',
      auth_type: authAllowed ? prev.auth_type : next?.auth_types[0]?.value ?? 'none',
      credentials: {},
    }));
  };

  const onBranchChange = (value: string) => {
    const branchId = value === NONE ? null : value;
    const dept = options.departments.find((d) => String(d.id) === String(data.department_id));
    form.setData((prev) => ({
      ...prev,
      branch_id: branchId,
      department_id: dept && dept.branch_id && branchId && String(dept.branch_id) !== String(branchId) ? null : prev.department_id,
    }));
  };

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

    form.transform((d) => {
      const { secrets_set, ...rest } = d as any;
      return rest;
    });

    if (mode === 'create') {
      form.post(route('hr.biometric-devices.store'), callbacks);
    } else if (deviceId) {
      form.put(route('hr.biometric-devices.update', deviceId), callbacks);
    }
  };

  const err = (key: string) => (errors as Record<string, string>)[key];
  const isUsb = data.connection_type === 'usb';
  const usesIssuedKey = ['api_key', 'hmac'].includes(data.auth_type);

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{mode === 'create' ? t('Add Biometric Device') : t('Edit Biometric Device')}</DialogTitle>
          <DialogDescription>{t('Register a biometric terminal and configure how it communicates with the HRMS.')}</DialogDescription>
        </DialogHeader>

        <form onSubmit={submit} className="space-y-6">
          <Section title={t('Device Identity')}>
            <Field label={t('Device Name')} required error={err('name')}>
              <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder={t('e.g. Main Gate Terminal')} />
            </Field>
            <Field label={t('Device Type')} required error={err('device_type')}>
              <Select value={data.device_type} onValueChange={(v) => setData('device_type', v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {options.device_types.map((o) => <SelectItem key={o.value} value={o.value}>{t(o.label)}</SelectItem>)}
                </SelectContent>
              </Select>
            </Field>
            <Field label={t('Serial Number')} required error={err('serial_number')} help={t('Must match the serial number reported by the device.')}>
              <Input value={data.serial_number} onChange={(e) => setData('serial_number', e.target.value)} placeholder="e.g. RSS20220619704" />
            </Field>
            <Field label={t('Device Identifier')} error={err('device_identifier')} help={t('Your internal reference. Leave blank to generate one.')}>
              <Input value={data.device_identifier} onChange={(e) => setData('device_identifier', e.target.value)} placeholder="e.g. HQ-GATE-01" />
            </Field>
            <Field label={t('Manufacturer')} error={err('manufacturer')}>
              <Input value={data.manufacturer} onChange={(e) => setData('manufacturer', e.target.value)} placeholder={driver?.manufacturers?.[0] ?? ''} />
            </Field>
            <Field label={t('Model')} error={err('model')}>
              <Input value={data.model} onChange={(e) => setData('model', e.target.value)} placeholder="e.g. RS-9W" />
            </Field>
          </Section>

          <Section title={t('Location')}>
            <Field label={t('Branch / Location')} error={err('branch_id')} help={t('Employees of this branch are synchronized to the device.')}>
              <Select value={data.branch_id ? String(data.branch_id) : NONE} onValueChange={onBranchChange}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>{t('All branches')}</SelectItem>
                  {options.branches.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </Field>
            <Field label={t('Department (optional)')} error={err('department_id')}>
              <Select value={data.department_id ? String(data.department_id) : NONE} onValueChange={(v) => setData('department_id', v === NONE ? null : v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>{t('All departments')}</SelectItem>
                  {departments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </Field>
            <Field label={t('Local Communication Agent (optional)')} error={err('agent_id')} help={t('Assign if this device sits on a private office LAN, Wi-Fi or USB port behind a Local Agent.')}>
              <Select value={data.agent_id ? String(data.agent_id) : NONE} onValueChange={(v) => setData('agent_id', v === NONE ? null : v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>{t('Direct to cloud (no local agent)')}</SelectItem>
                  {(options.agents ?? []).map((a) => (
                    <SelectItem key={a.id} value={String(a.id)}>{a.name} ({a.agent_identifier})</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
          </Section>

          <Section title={t('Communication')}>
            <Field label={t('Communication Protocol')} required error={err('protocol')}>
              <Select value={data.protocol} onValueChange={onProtocolChange}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {options.drivers.map((d) => <SelectItem key={d.value} value={d.value}>{d.label}</SelectItem>)}
                </SelectContent>
              </Select>
            </Field>
            <Field label={t('Connection Type')} required error={err('connection_type')}>
              <Select value={data.connection_type} onValueChange={(v) => setData('connection_type', v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {options.connection_types.map((o) => <SelectItem key={o.value} value={o.value}>{t(o.label)}</SelectItem>)}
                </SelectContent>
              </Select>
            </Field>
            {driver && (
              <div className="md:col-span-2 rounded-md border bg-muted/40 p-3 space-y-2">
                <p className="text-xs text-muted-foreground">{driver.description}</p>
                <CapabilityBadges driver={driver} />
              </div>
            )}
            {!isUsb && (
              <>
                <Field label={t('IP Address')} error={err('ip_address')} help={t('Optional for devices behind NAT that push to the server.')}>
                  <Input value={data.ip_address} onChange={(e) => setData('ip_address', e.target.value)} placeholder="192.168.1.201" />
                </Field>
                <Field label={t('Port')} error={err('port')}>
                  <Input type="number" min={1} max={65535} value={data.port ?? ''} onChange={(e) => setData('port', e.target.value)} placeholder={driver?.default_port ? String(driver.default_port) : ''} />
                </Field>
              </>
            )}
            <Field label={t('Device Timezone')} error={err('timezone')} help={t('Leave blank to use the company timezone') + ` (${options.default_timezone}).`}>
              <Select value={data.timezone || NONE} onValueChange={(v) => setData('timezone', v === NONE ? '' : v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent className="max-h-72">
                  <SelectItem value={NONE}>{t('Company default')}</SelectItem>
                  {options.timezones.map((tz) => <SelectItem key={tz} value={tz}>{tz}</SelectItem>)}
                </SelectContent>
              </Select>
            </Field>
          </Section>

          <Section title={t('Authentication')}>
            <Field label={t('Authentication Method')} required error={err('auth_type')}>
              <Select value={data.auth_type} onValueChange={(v) => setData('auth_type', v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {(driver?.auth_types ?? options.auth_types).map((o) => <SelectItem key={o.value} value={o.value}>{t(o.label)}</SelectItem>)}
                </SelectContent>
              </Select>
            </Field>
            {usesIssuedKey && (
              <div className="md:col-span-2 text-xs text-muted-foreground rounded-md border border-dashed p-3">
                {mode === 'create' || !hasApiKey
                  ? t('A unique API key will be generated for this device after saving. It is shown only once.')
                  : t('An API key is already issued. Use “Regenerate API key” on the device page to rotate it.')}
              </div>
            )}
            {(driver?.credential_fields ?? []).map((f) => {
              const isSet = initial?.secrets_set?.[f.name];
              return (
                <Field key={f.name} label={t(f.label)} required={f.required} error={err(`credentials.${f.name}`)} help={f.help ? t(f.help) : f.secret && isSet ? t('A value is stored. Leave blank to keep it.') : undefined}>
                  <Input
                    type={f.secret ? 'password' : 'text'}
                    autoComplete="new-password"
                    value={data.credentials?.[f.name] ?? ''}
                    placeholder={f.secret && isSet ? '••••••••' : ''}
                    onChange={(e) => setData('credentials', { ...(data.credentials ?? {}), [f.name]: e.target.value })}
                  />
                </Field>
              );
            })}
          </Section>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Field label={t('Notes')} error={err('notes')}>
              <Textarea rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
            </Field>
            <div className="flex items-start gap-3 pt-7">
              <Switch id="is_active" checked={!!data.is_active} onCheckedChange={(v) => setData('is_active', v)} />
              <div>
                <Label htmlFor="is_active">{t('Active')}</Label>
                <p className="text-xs text-muted-foreground">{t('Inactive devices receive no commands.')}</p>
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>{t('Cancel')}</Button>
            <Button type="submit" disabled={processing}>{mode === 'create' ? t('Create Device') : t('Save Changes')}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
