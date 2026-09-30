import { PageTemplate } from '@/components/page-template';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { router } from '@inertiajs/react';
import { Cake, Calendar, Clock, Globe, LockKeyhole, Sparkles, TentTree, Users } from 'lucide-react';
import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface CalendarEvent {
    id: number | string;
    title: string;
    start: string | Date;
    end: string | Date;
    type: 'meeting' | 'holiday' | 'leave' | 'birthday' | 'custom';
    allDay?: boolean;
    color: string;
    status?: string;
    avatar?: string;
    description?: string | null;
    visibility?: 'public' | 'private';
}

interface CalendarProps {
  events: CalendarEvent[];
  canManage: boolean;
  canCreateEvents: boolean;
}

export default function CalendarIndex({ events, canManage, canCreateEvents }: CalendarProps) {
    const { t } = useTranslation();
    const [selectedEvent, setSelectedEvent] = useState<CalendarEvent | null>(null);
    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [viewDate, setViewDate] = useState(new Date());
    const [createDialogOpen, setCreateDialogOpen] = useState(false);
    const [eventForm, setEventForm] = useState({
        title: '',
        description: '',
        start_date: '',
        end_date: '',
        visibility: 'public' as 'public' | 'private',
    });

    const breadcrumbs = [{ title: t('Dashboard'), href: route('dashboard') }, { title: t('Calendar') }];

    const handleEventClick = (clickInfo: any) => {
        const event = events.find((e) => String(e.id) === String(clickInfo.event.id));
        if (event) {
            setSelectedEvent(event);
            setIsDialogOpen(true);
            // close the +more popover
            clickInfo.jsEvent?.target?.closest('.fc-popover')?.querySelector<HTMLElement>('.fc-popover-close')?.click();
        }
    };

    const currentMonth = viewDate.getMonth();
    const currentYear = viewDate.getFullYear();
    const monthEvents = events.filter((e) => {
        const d = new Date(e.start);
        return d.getMonth() === currentMonth && d.getFullYear() === currentYear;
    });

    const upcomingEvents = [...events]
        .filter((e) => new Date(e.start) >= new Date())
        .sort((a, b) => new Date(a.start).getTime() - new Date(b.start).getTime());

    const typeIcon = (type: string) => {
        if (type === 'meeting') return <Users className="h-4 w-4" />;
        if (type === 'holiday') return <TentTree className="h-4 w-4" />;
        if (type === 'birthday') return <Cake className="h-4 w-4" />;
        if (type === 'custom') return <Sparkles className="h-4 w-4" />;
        return <Clock className="h-4 w-4" />;
    };

    const typeIconBoxClass = (type: string) => {
        if (type === 'meeting') return 'bg-blue-200 text-blue-700';
        if (type === 'holiday') return 'bg-green-200 text-green-700';
        if (type === 'birthday') return 'bg-pink-200 text-pink-700';
        if (type === 'custom') return 'bg-violet-200 text-violet-700';
        return 'bg-yellow-100  text-yellow-700';
    };

    const handleDateClick = (info: any) => {
    if (!canCreateEvents) return;
        const date = info.dateStr.split('T')[0];
        setEventForm({ title: '', description: '', start_date: date, end_date: date, visibility: 'public' });
        setCreateDialogOpen(true);
    };

    const submitEvent = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        router.post(route('calendar.events.store'), eventForm, {
            onSuccess: () => {
                setCreateDialogOpen(false);
                setEventForm({ title: '', description: '', start_date: '', end_date: '', visibility: 'public' });
            },
        });
    };

    return (
        <PageTemplate title={t('Calendar')} description={t('View your scheduled events and activities.')} url="/calendar" breadcrumbs={breadcrumbs}>
            <style>
                {`
            .fc .fc-event-title {
                font-size: 0.75rem;
            }
            .fc .fc-daygrid-event-harness {
                margin-bottom: 3px !important;
            }
            .fc .fc-popover-body {
                max-height: 130px;
                overflow-y: auto;
            }
            `}
            </style>
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-4">
                {/* Main Calendar */}
                <div className="lg:col-span-3">
                    <div className="rounded-xl border border-gray-200 bg-white shadow dark:border-gray-700 dark:bg-gray-900">
                        {/* Legend */}
                        <div className="flex items-center gap-4 border-b border-gray-100 bg-gray-50 px-5 py-3 dark:border-gray-800 dark:bg-gray-800/50">
                            <span className="text-xs font-medium text-gray-500 dark:text-gray-400">{t('Legend')}:</span>
                            <div className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                <div className="h-2.5 w-2.5 rounded-full bg-blue-700" />
                                {t('Meetings')}
                            </div>
                            <div className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                <div className="h-2.5 w-2.5 rounded-full bg-green-700" />
                                {t('Holidays')}
                            </div>
                            <div className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                <div className="h-2.5 w-2.5 rounded-full bg-yellow-700" />
                                {t('Leaves')}
                            </div>
                            <div className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                <div className="h-2.5 w-2.5 rounded-full bg-pink-600" />
                                {t('Birthdays')}
                            </div>
                            <div className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                <div className="h-2.5 w-2.5 rounded-full bg-violet-700" />
                                {t('Custom Events')}
                            </div>
              {canCreateEvents && <span className="ml-auto text-xs text-gray-500">{t('Click a date to create an event')}</span>}
                        </div>

                        <div className="p-4" style={{ height: '800px' }}>
                            <FullCalendar
                                plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
                                initialView="dayGridMonth"
                                headerToolbar={{
                                    left: 'prev,next today',
                                    center: 'title',
                                    right: 'dayGridMonth,timeGridWeek,timeGridDay',
                                }}
                                events={events}
                                height="100%"
                                editable={canManage}
                                selectable={canManage}
                                selectMirror={true}
                                dayMaxEvents={true}
                                weekends={true}
                                eventDisplay="block"
                                eventBackgroundColor=""
                                eventBorderColor=""
                                eventClick={handleEventClick}
                                dateClick={handleDateClick}
                                datesSet={(info) => setViewDate(info.view.currentStart)}
                                displayEventTime={false}
                            />
                        </div>
                    </div>
                </div>

                {/* Sidebar */}
                <div className="space-y-4">
                    {/* Upcoming Events */}
                    <Card className="border border-gray-200 shadow-sm dark:border-gray-700">
                        <CardHeader className="border-b border-gray-100 px-4 pt-4 pb-3 dark:border-gray-800">
                            <CardTitle className="flex items-center gap-2 text-sm font-semibold">
                                <Calendar className="text-primary h-4 w-4" />
                                {t('Upcoming Events')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="max-h-80 overflow-x-auto p-0">
                            {upcomingEvents.length === 0 ? (
                                <p className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{t('No upcoming events')}</p>
                            ) : (
                                <div className="divide-y divide-gray-100 dark:divide-gray-800">
                                    {upcomingEvents.map((event) => (
                                        <div
                                            key={event.id}
                                            onClick={() => {
                                                setSelectedEvent(event);
                                                setIsDialogOpen(true);
                                            }}
                                            className="flex cursor-pointer items-start gap-3 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/60"
                                        >
                                            <div className="mt-1.5 h-2.5 w-2.5 flex-shrink-0 rounded-full" />
                                            {(event.type === 'leave' || event.type === 'birthday') && event.avatar ? (
                                                <img
                                                    src={event.avatar}
                                                    alt={event.title}
                                                    className="h-10 w-10 flex-shrink-0 rounded-lg object-cover"
                                                />
                                            ) : (
                                                <div
                                                    className={`flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg ${typeIconBoxClass(event.type)}`}
                                                >
                                                    {typeIcon(event.type)}
                                                </div>
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{event.title}</p>
                                                <p className="mt-0.5 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                    <Calendar className="h-3 w-3" />
                                                    {window.appSettings?.formatDateTimeSimple(String(event.start), false)}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Summary */}
                    <Card className="border border-gray-200 shadow-sm dark:border-gray-700">
                        <CardHeader className="border-b border-gray-100 px-4 pt-4 pb-3 dark:border-gray-800">
                            <CardTitle className="text-sm font-semibold">{t('This Month')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 px-4 py-3">
                            {[
                                {
                                    label: t('Meetings'),
                                    count: monthEvents.filter((e) => e.type === 'meeting').length,
                                    color: 'text-blue-600 dark:text-blue-400',
                                },
                                {
                                    label: t('Holidays'),
                                    count: monthEvents.filter((e) => e.type === 'holiday').length,
                                    color: 'text-green-600 dark:text-green-400',
                                },
                                {
                                    label: t('Leaves'),
                                    count: monthEvents.filter((e) => e.type === 'leave').length,
                                    color: 'text-yellow-600 dark:text-yellow-400',
                                },
                                {
                                    label: t('Birthdays'),
                                    count: monthEvents.filter((e) => e.type === 'birthday').length,
                                    color: 'text-pink-600 dark:text-pink-400',
                                },
                                {
                                    label: t('Custom Events'),
                                    count: monthEvents.filter((e) => e.type === 'custom').length,
                                    color: 'text-violet-600 dark:text-violet-400',
                                },
                                { label: t('Total Events'), count: monthEvents.length, color: 'text-gray-700 dark:text-gray-300' },
                            ].map(({ label, count, color }) => (
                                <div key={label} className="flex items-center justify-between text-sm">
                                    <span className="text-gray-500 dark:text-gray-400">{label}</span>
                                    <span className={`font-semibold ${color}`}>{count}</span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{selectedEvent?.title}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="flex items-center gap-2">
                            <Badge
                                variant="outline"
                                className={` ${selectedEvent?.type === 'meeting' ? 'border-blue-200 bg-blue-50 text-blue-700' : ''} ${selectedEvent?.type === 'holiday' ? 'border-green-200 bg-green-50 text-green-700' : ''} ${selectedEvent?.type === 'leave' ? 'border-yellow-200 bg-yellow-50 text-yellow-700' : ''} ${selectedEvent?.type === 'birthday' ? 'border-pink-200 bg-pink-50 text-pink-700' : ''} ${selectedEvent?.type === 'custom' ? 'border-violet-200 bg-violet-50 text-violet-700' : ''} `}
                            >
                                {selectedEvent?.type === 'meeting' && t('Meeting')}
                                {selectedEvent?.type === 'holiday' && t('Holiday')}
                                {selectedEvent?.type === 'leave' && t('Leave')}
                                {selectedEvent?.type === 'birthday' && t('Birthday')}
                                {selectedEvent?.type === 'custom' && t('Custom Event')}
                            </Badge>
                            {selectedEvent?.type === 'custom' && selectedEvent.visibility && (
                                <Badge variant="outline">
                                    {selectedEvent.visibility === 'private' ? (
                                        <>
                                            <LockKeyhole className="mr-1 h-3 w-3" />
                                            {t('Private')}
                                        </>
                                    ) : (
                                        <>
                                            <Globe className="mr-1 h-3 w-3" />
                                            {t('Public')}
                                        </>
                                    )}
                                </Badge>
                            )}
                            {selectedEvent?.status && <Badge variant="outline">{selectedEvent.status}</Badge>}
                        </div>
                        {!selectedEvent?.allDay && (
                            <>
                                <div>
                                    <p className="text-muted-foreground text-sm">{t('Start Date')}</p>
                                    <p className="font-medium">
                                        <div className="flex items-center gap-2 overflow-hidden text-ellipsis whitespace-nowrap text-gray-500">
                                            {selectedEvent?.start && <Calendar className="h-4 w-4" />}
                                            <span>{selectedEvent?.start ? window.appSettings.formatDateTime(selectedEvent?.start) : ''}</span>
                                        </div>
                                    </p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground text-sm">{t('End Date')}</p>
                                    <p className="font-medium">
                                        <div className="flex items-center gap-2 overflow-hidden text-ellipsis whitespace-nowrap text-gray-500">
                                            {selectedEvent?.end && <Calendar className="h-4 w-4" />}
                                            <span>{selectedEvent?.end ? window.appSettings.formatDateTime(selectedEvent?.end) : ''}</span>
                                        </div>
                                    </p>
                                </div>
                            </>
                        )}
                        {selectedEvent?.allDay && (
                            <div>
                                <Badge variant="outline">{t('All Day Event')}</Badge>
                            </div>
                        )}
                        {selectedEvent?.description && (
                            <p className="text-muted-foreground text-sm whitespace-pre-wrap">{selectedEvent.description}</p>
                        )}
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog open={createDialogOpen} onOpenChange={setCreateDialogOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('Create Calendar Event')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitEvent} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="calendar-event-title" required>
                                {t('Title')}
                            </Label>
                            <Input
                                id="calendar-event-title"
                                value={eventForm.title}
                                maxLength={255}
                                required
                                autoFocus
                                onChange={(e) => setEventForm({ ...eventForm, title: e.target.value })}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-2">
                                <Label htmlFor="calendar-event-start" required>
                                    {t('Start Date')}
                                </Label>
                                <Input
                                    id="calendar-event-start"
                                    type="date"
                                    value={eventForm.start_date}
                                    required
                                    onChange={(e) =>
                                        setEventForm({
                                            ...eventForm,
                                            start_date: e.target.value,
                                            end_date: eventForm.end_date < e.target.value ? e.target.value : eventForm.end_date,
                                        })
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="calendar-event-end" required>
                                    {t('End Date')}
                                </Label>
                                <Input
                                    id="calendar-event-end"
                                    type="date"
                                    min={eventForm.start_date}
                                    value={eventForm.end_date}
                                    required
                                    onChange={(e) => setEventForm({ ...eventForm, end_date: e.target.value })}
                                />
                            </div>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="calendar-event-description">{t('Description')}</Label>
                            <Textarea
                                id="calendar-event-description"
                                value={eventForm.description}
                                maxLength={5000}
                                onChange={(e) => setEventForm({ ...eventForm, description: e.target.value })}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="calendar-event-visibility">{t('Visibility')}</Label>
                            <select
                                id="calendar-event-visibility"
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                value={eventForm.visibility}
                                onChange={(e) => setEventForm({ ...eventForm, visibility: e.target.value as 'public' | 'private' })}
                            >
                                <option value="public">{t('Public — visible to everyone in this company')}</option>
                                <option value="private">{t('Private — visible only to you')}</option>
                            </select>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setCreateDialogOpen(false)}>
                                {t('Cancel')}
                            </Button>
                            <Button type="submit">{t('Create Event')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </PageTemplate>
    );
}
