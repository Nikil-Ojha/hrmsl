import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SettingsSection } from '@/components/settings-section';
import { Card, CardContent } from '@/components/ui/card';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CalendarDays, Save } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { toast } from '@/components/custom-toast';

const months = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

export default function LeaveYearSettings({ settings = {} }: { settings?: Record<string, any> }) {
  const { t } = useTranslation();
  const { globalSettings } = usePage().props as any;
  const [type, setType] = useState(settings.leave_year_type || 'calendar');
  const [startMonth, setStartMonth] = useState(String(settings.leave_year_start_month || '1'));

  useEffect(() => {
    setType(settings.leave_year_type || 'calendar');
    setStartMonth(String(settings.leave_year_start_month || '1'));
  }, [settings.leave_year_type, settings.leave_year_start_month]);

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    if (!globalSettings?.is_demo) toast.loading(t('Saving leave year settings...'));
    router.post(route('settings.leave-year.update'), {
      leave_year_type: type,
      leave_year_start_month: type === 'custom' ? startMonth : type === 'financial' ? '4' : '1',
    }, {
      preserveScroll: true,
      onSuccess: (page) => {
        if (!globalSettings?.is_demo) toast.dismiss();
        const flash = page.props.flash as any;
        if (flash?.success) toast.success(flash.success);
        else if (flash?.error) toast.error(flash.error);
      },
      onError: (errors) => {
        if (!globalSettings?.is_demo) toast.dismiss();
        toast.error(Object.values(errors).join(', ') || t('Failed to save leave year settings'));
      },
    });
  };

  return (
    <SettingsSection
      title={t('Leave Year Settings')}
      description={t('Choose the annual cycle used for your organization’s leave management.')}
      action={<Button type="submit" form="leave-year-form" size="sm"><Save className="h-4 w-4 mr-2" />{t('Save Changes')}</Button>}
    >
      <Card><CardContent className="pt-6">
        <form id="leave-year-form" onSubmit={submit} className="grid gap-5 md:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="leave-year-type">{t('Leave Year')}</Label>
            <Select value={type} onValueChange={setType}>
              <SelectTrigger id="leave-year-type"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="calendar">{t('Calendar Year')} (1 January – 31 December)</SelectItem>
                <SelectItem value="financial">{t('Financial Year')} (1 April – 31 March)</SelectItem>
                <SelectItem value="custom">{t('Custom')}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          {type === 'custom' && <div className="space-y-2">
            <Label htmlFor="leave-year-start-month">{t('Starting Month')}</Label>
            <Select value={startMonth} onValueChange={setStartMonth}>
              <SelectTrigger id="leave-year-start-month"><SelectValue /></SelectTrigger>
              <SelectContent>{months.map((month, index) => <SelectItem key={month} value={String(index + 1)}>{t(month)}</SelectItem>)}</SelectContent>
            </Select>
          </div>}
          <div className="flex items-center gap-2 text-sm text-muted-foreground md:col-span-2">
            <CalendarDays className="h-4 w-4" />
            {type === 'custom'
              ? `${t(months[Number(startMonth) - 1])} – ${t(months[(Number(startMonth) + 10) % 12])}`
              : type === 'financial' ? t('April – March') : t('January – December')}
          </div>
        </form>
      </CardContent></Card>
    </SettingsSection>
  );
}
