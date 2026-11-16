import { useState, useEffect } from 'react';
import { PageTemplate } from '@/components/page-template';
import { usePage, router } from '@inertiajs/react';
import {
  ChevronLeft,
  ChevronRight,
  CheckCircle2,
  XCircle,
  UserPlus,
  Fingerprint,
  Users,
  RefreshCw,
  Search
} from 'lucide-react';
import { hasPermission } from '@/utils/authorization';
import { CrudTable } from '@/components/CrudTable';
import { toast } from '@/components/custom-toast';
import { useTranslation } from 'react-i18next';
import { useInitials } from '@/hooks/use-initials';
import { Pagination } from '@/components/ui/pagination';
import { SearchAndFilterBar } from '@/components/ui/search-and-filter-bar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog } from '@/components/ui/dialog';
import View from './view';

export default function BiometricAttendance() {
  const { t } = useTranslation();
  const {
    auth,
    biometricData,
    filters: pageFilters = {},
    configurationMissing,
    globalSettings,
    weekDays,
    monthLabel,
    selectedDate,
    employees,
    employeeEnrollments = [],
    enrollmentStats = { total: 0, enrolled: 0, not_enrolled: 0, device_sn: 'RSS20220619704' }
  } = usePage().props as any;
  const permissions = auth?.permissions || [];

  const getInitials = useInitials();

  // State
  const [activeTab, setActiveTab] = useState<'logs' | 'enrollment'>('logs');
  const [searchTerm, setSearchTerm] = useState(pageFilters.search || '');
  const [selectedEmployeeCode, setSelectedEmployeeCode] = useState(pageFilters.employee_code || '_empty_');
  const [showFilters, setShowFilters] = useState(false);
  const [showDetailsModal, setShowDetailsModal] = useState(false);
  const [detailEntries, setDetailEntries] = useState([]);
  const [selectedEmployee, setSelectedEmployee] = useState(null);

  // Enrollment tab state
  const [enrollmentFilter, setEnrollmentFilter] = useState<'all' | 'not_enrolled' | 'enrolled'>('all');
  const [enrollmentSearch, setEnrollmentSearch] = useState('');
  const [customCodes, setCustomCodes] = useState<Record<number, string>>({});
  const [enrollingId, setEnrollingId] = useState<number | null>(null);
  const [isEnrollingAll, setIsEnrollingAll] = useState(false);

  // Build a shared params object so ALL navigations preserve ALL filters
  const buildParams = (overrides: Record<string, any> = {}) => ({
    search: searchTerm || undefined,
    employee_code: selectedEmployeeCode !== '_empty_' ? selectedEmployeeCode : undefined,
    per_page: pageFilters.per_page,
    selected_date: selectedDate || undefined,
    page: 1,
    ...overrides,
  });

  const navigateWeek = (direction: 'prev' | 'next') => {
    const current = new Date(selectedDate);
    current.setDate(current.getDate() + (direction === 'next' ? 7 : -7));
    router.get(route('hr.biometric-attendance.index'), buildParams({ selected_date: current.toISOString().slice(0, 10) }), { preserveState: true, preserveScroll: true });
  };

  const navigateMonth = (direction: 'prev' | 'next') => {
    const current = new Date(selectedDate);
    current.setDate(1);
    current.setMonth(current.getMonth() + (direction === 'next' ? 1 : -1));
    router.get(route('hr.biometric-attendance.index'), buildParams({ selected_date: current.toISOString().slice(0, 10) }), { preserveState: true, preserveScroll: true });
  };

  const handleDayClick = (date: string) => {
    router.get(route('hr.biometric-attendance.index'), buildParams({ selected_date: date }), { preserveState: true, preserveScroll: true });
  };

  const hasActiveFilters = () => searchTerm !== '' || selectedEmployeeCode !== '_empty_';
  const activeFilterCount = () => (searchTerm ? 1 : 0) + (selectedEmployeeCode !== '_empty_' ? 1 : 0);

  const handleSearch = (e: React.FormEvent) => { e.preventDefault(); applyFilters(); };

  const applyFilters = () => {
    router.get(route('hr.biometric-attendance.index'), buildParams(), { preserveState: true, preserveScroll: true });
  };

  const handlePageChange = (url: string) => {
    const page = new URL(url).searchParams.get('page');
    router.get(route('hr.biometric-attendance.index'), buildParams({ page }), { preserveState: true, preserveScroll: true });
  };

  const handleAction = (action: string, row: any) => {
    switch (action) {
      case 'view':
        handleShowDetails(row);
        break;
      case 'sync':
        handleSync(row);
        break;
    }
  };

  const handleShowDetails = async (row: any) => {
    try {
      const response = await fetch(route('hr.biometric-attendance.show', {
        employeeCode: row.employee_code,
        date: row.date
      }));

      const result = await response.json();

      if (result.success) {
        setDetailEntries(result.data.entries);
        setSelectedEmployee({
          code: result.data.employee_code,
          date: result.data.date,
          name: row.name
        });
        setShowDetailsModal(true);
      } else {
        toast.error(t(result.message || 'Failed to fetch details'));
      }
    } catch (error) {
      toast.error(t('Error fetching details'));
    }
  };

  const handleSync = (row: any) => {
    if (!globalSettings?.is_demo) {
      toast.loading(t('Syncing biometric data...'));
    }

    const syncData = {
      biometric_emp_id: row.employee_code,
      biometric_id: row.id,
      date: row.date,
      clock_in: row.clock_in,
      clock_out: row.clock_out
    };

    router.post(route('hr.biometric-attendance.sync', row.id), syncData, {
      onSuccess: (page) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (page.props.flash.success) {
          toast.success(t(page.props.flash.success));
        } else if (page.props.flash.error) {
          toast.error(t(page.props.flash.error));
        }
      },
      onError: (errors) => {
        if (!globalSettings?.is_demo) {
          toast.dismiss();
        }
        if (typeof errors === 'string') {
          toast.error(errors);
        } else {
          toast.error(`Failed to sync: ${Object.values(errors).join(', ')}`);
        }
      }
    });
  };

  const handleEnrollEmployee = (emp: any) => {
    const customCode = customCodes[emp.employee_id] ?? emp.employee_code ?? '';
    setEnrollingId(emp.employee_id);
    if (!globalSettings?.is_demo) {
      toast.loading(t('Enrolling employee on biometric device...'));
    }

    router.post(
      route('hr.biometric-attendance.enroll'),
      {
        employee_id: emp.employee_id || emp.db_employee_id,
        employee_code: emp.employee_code,
        biometric_emp_id: customCode || undefined,
        device_sn: emp.biometric_device_sn || enrollmentStats.device_sn || 'RSS20220619704',
      },
      {
        preserveScroll: true,
        onSuccess: (page: any) => {
          setEnrollingId(null);
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          if (page.props.flash?.success) {
            toast.success(page.props.flash.success);
          } else if (page.props.flash?.error) {
            toast.error(page.props.flash.error);
          }
        },
        onError: (errors: any) => {
          setEnrollingId(null);
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          toast.error(Object.values(errors).join(', ') || t('Failed to enroll employee on device'));
        }
      }
    );
  };

  const handleEnrollAll = () => {
    setIsEnrollingAll(true);
    if (!globalSettings?.is_demo) {
      toast.loading(t('Enrolling all unenrolled employees on biometric device...'));
    }

    router.post(
      route('hr.biometric-attendance.enroll-all'),
      {
        device_sn: enrollmentStats.device_sn || 'RSS20220619704',
      },
      {
        preserveScroll: true,
        onSuccess: (page: any) => {
          setIsEnrollingAll(false);
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          if (page.props.flash?.success) {
            toast.success(page.props.flash.success);
          } else if (page.props.flash?.error) {
            toast.error(page.props.flash.error);
          }
        },
        onError: (errors: any) => {
          setIsEnrollingAll(false);
          if (!globalSettings?.is_demo) {
            toast.dismiss();
          }
          toast.error(Object.values(errors).join(', ') || t('Failed to enroll employees'));
        }
      }
    );
  };

  const handleResetFilters = () => {
    router.get(route('hr.biometric-attendance.index'));
  };

  const [pageInitialState, setPageInitialState] = useState(true);
  useEffect(() => {
    if (!pageInitialState) applyFilters();
    setPageInitialState(false);
  }, [searchTerm, selectedEmployeeCode]);

  const breadcrumbs = [
    { title: t('Dashboard'), href: route('dashboard') },
    { title: t('Attendance') },
    { title: t('Biometric Attendance') }
  ];

  // Define table actions
  const actions = [
    {
      label: t('Details'),
      icon: 'Eye',
      action: 'view',
      className: 'text-blue-500',
      requiredPermission: 'view-biometric-attendance'
    },
    {
      label: t('Sync'),
      icon: 'RefreshCw',
      action: 'sync',
      className: 'text-green-500',
      requiredPermission: 'sync-biometric-attendance',
      condition: (row: any) => row.sync_status !== 'synced'
    }
  ];

  // Convert HH:MM:SS to total minutes
  const timeToMinutes = (time: string) => {
    const [h, m] = time.split(':').map(Number);
    return h * 60 + m;
  };

  // Define table columns
  const columns = [
    {
      key: 'name',
      label: t('Employee'),
      sortable: false,
      render: (_: any, row: any) => (
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary text-white overflow-hidden shrink-0">
            {row.avatar
              ? <img src={row.avatar} alt={row.name} className="h-full w-full object-cover" />
              : getInitials(row.name || '')}
          </div>
          <div>
            <div className="font-medium text-sm">{row.name}</div>
            <div className="text-xs text-muted-foreground font-mono"><span className="font-medium">{t('Employee Code')}:</span> {row.employee_code}</div>
          </div>
        </div>
      )
    },
    {
      key: 'designation',
      label: t('Designation'),
      sortable: false,
      render: (_: any, row: any) => (
        <span className="text-sm">{row.designation || '-'}</span>
      )
    },
    {
      key: 'is_biometric_enrolled',
      label: t('Device Enrollment'),
      sortable: false,
      render: (_: any, row: any) => {
        if (row.is_biometric_enrolled) {
          return (
            <div className="flex flex-col gap-0.5">
              <span className="inline-flex items-center gap-1 w-fit px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                <CheckCircle2 className="h-3.5 w-3.5" />
                {t('Enrolled')}
              </span>
              <span className="text-[11px] text-muted-foreground font-mono">
                {row.biometric_device_sn || enrollmentStats.device_sn}
              </span>
            </div>
          );
        }

        return (
          <div className="flex items-center gap-2">
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
              <XCircle className="h-3.5 w-3.5" />
              {t('Not Enrolled')}
            </span>
            {row.db_employee_id && (
              <Button
                size="sm"
                variant="outline"
                className="h-7 px-2 text-xs border-primary text-primary hover:bg-primary hover:text-white"
                onClick={() => handleEnrollEmployee({ employee_id: row.db_employee_id, employee_code: row.employee_code, biometric_device_sn: row.biometric_device_sn })}
                disabled={enrollingId === row.db_employee_id}
              >
                <UserPlus className="h-3 w-3 mr-1" />
                {t('Enroll')}
              </Button>
            )}
          </div>
        );
      }
    },
    {
      key: 'clock_in',
      label: t('Clock In & Out'),
      sortable: false,
      render: (_: any, row: any) => {
        const clockIn = row.clock_in;
        const clockOut = row.clock_out;
        const punches: { time: string; punch_state_display: string }[] = row.punches || [];

        if (!clockIn) return <span className="text-sm text-gray-400">-</span>;

        const startMin = timeToMinutes(clockIn);
        const endMin = clockOut ? timeToMinutes(clockOut) : startMin + 1;
        const range = endMin - startMin || 1;

        return (
          <div className="relative flex items-center gap-1.5 sm:gap-2 group/cell">

            {/* Clock In */}
            <div className="flex items-center gap-1">
              <span className="w-2 h-2 rounded-full bg-green-500 shrink-0" />
              <span className="text-xs sm:text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{clockIn}</span>
            </div>

            {/* Timeline */}
            <div className="relative flex items-center w-12 sm:w-24">
              <div className="w-full h-[2px] bg-gray-300 dark:bg-gray-600 rounded-full" />
              {punches.map((punch, idx) => {
                const pMin = timeToMinutes(punch.time);
                const position = Math.min(Math.max(((pMin - startMin) / range) * 100, 0), 100);
                const isIn = punch.punch_state_display?.toLowerCase().includes('in');
                return (
                  <div key={idx} className="absolute" style={{ left: `${position}%`, transform: 'translateX(-50%)' }}>
                    <div className={`w-2 h-2 sm:w-2.5 sm:h-2.5 rounded-full border-2 border-white dark:border-gray-900 shadow-sm ${isIn ? 'bg-green-500' : 'bg-red-500'
                      }`} />
                  </div>
                );
              })}
            </div>

            {/* Clock Out */}
            <div className="flex items-center gap-1">
              <span className="w-2 h-2 rounded-full bg-red-500 shrink-0" />
              <span className="text-xs sm:text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{clockOut || '-'}</span>
            </div>

            {/* Punch count badge */}
            {punches.length > 0 && (
              <span className="hidden sm:inline-flex items-center px-1.5 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
                {punches.length}x
              </span>
            )}

            {/* Hover Tooltip — all punch entries */}
            <div className="absolute bottom-full left-0 mb-2 z-50 hidden group-hover/cell:block">
              <div className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-xl p-3 min-w-[180px]">
                <div className="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2 border-b border-gray-100 dark:border-gray-700 pb-1.5">
                  {t('Clock In & Out Entries')}
                </div>
                <div className="space-y-1.5">
                  {punches.map((punch, idx) => {
                    const isIn = punch.punch_state_display?.toLowerCase().includes('in');
                    return (
                      <div key={idx} className="flex items-center justify-between gap-4">
                        <div className="flex items-center gap-1.5">
                          <span className={`w-2 h-2 rounded-full shrink-0 ${isIn ? 'bg-green-500' : 'bg-red-500'}`} />
                          <span className={`text-xs ${isIn ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                            {punch.punch_state_display}
                          </span>
                        </div>
                        <span className="text-xs font-medium text-gray-700 dark:text-gray-300">{punch.time}</span>
                      </div>
                    );
                  })}
                </div>
              </div>
              {/* Arrow */}
              <div className="w-2.5 h-2.5 bg-white dark:bg-gray-800 border-r border-b border-gray-200 dark:border-gray-600 rotate-45 -mt-1.5 ml-4" />
            </div>

          </div>
        );
      }
    },
  ];

  // Filtered employees for the Device Enrollment tab
  const filteredEnrollments = (employeeEnrollments || []).filter((emp: any) => {
    if (enrollmentFilter === 'enrolled' && !emp.is_biometric_enrolled) return false;
    if (enrollmentFilter === 'not_enrolled' && emp.is_biometric_enrolled) return false;
    if (enrollmentSearch.trim() !== '') {
      const q = enrollmentSearch.toLowerCase();
      const matchName = emp.name?.toLowerCase().includes(q);
      const matchCode = String(emp.employee_code || '').toLowerCase().includes(q);
      const matchSysId = String(emp.system_employee_id || '').toLowerCase().includes(q);
      const matchDept = emp.department?.toLowerCase().includes(q);
      return matchName || matchCode || matchSysId || matchDept;
    }
    return true;
  });

  return (
    <PageTemplate
      title={t("Biometric Attendance")}
      description={t("View biometric attendance logs and manage employee biometric device enrollment.")}
      url="/hr/biometric-attendance"
      breadcrumbs={breadcrumbs}
      noPadding
    >
      {/* ── Top Bar: Device Info + View Switcher (Attendance Logs vs Employee Enrollment) ── */}
      <div className="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm p-4 mb-4 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={() => setActiveTab('logs')}
            className={`inline-flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-colors cursor-pointer ${
              activeTab === 'logs'
                ? 'bg-primary text-white shadow-sm'
                : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'
            }`}
          >
            <Fingerprint className="h-4 w-4" />
            {t('Attendance Punch Logs')}
          </button>

          <button
            type="button"
            onClick={() => setActiveTab('enrollment')}
            className={`inline-flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-colors cursor-pointer ${
              activeTab === 'enrollment'
                ? 'bg-primary text-white shadow-sm'
                : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'
            }`}
          >
            <Users className="h-4 w-4" />
            {t('Employee Device Enrollment')}
            <span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${
              activeTab === 'enrollment'
                ? 'bg-white/20 text-white'
                : 'bg-primary/10 text-primary'
            }`}>
              {enrollmentStats.enrolled}/{enrollmentStats.total} {t('Enrolled')}
            </span>
            {enrollmentStats.not_enrolled > 0 && (
              <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500 text-white">
                {enrollmentStats.not_enrolled} {t('Not Enrolled')}
              </span>
            )}
          </button>
        </div>

        <div className="flex flex-wrap items-center gap-3 w-full lg:w-auto justify-between lg:justify-end">
          <div className="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-2">
            <span className="inline-block w-2 h-2 rounded-full bg-green-500" />
            <span>{t('Device')}: <strong className="font-mono text-gray-800 dark:text-gray-200">RS-9W ({enrollmentStats.device_sn || 'RSS20220619704'})</strong></span>
          </div>

          {enrollmentStats.not_enrolled > 0 && (
            <Button
              size="sm"
              onClick={handleEnrollAll}
              disabled={isEnrollingAll}
              className="bg-green-600 hover:bg-green-700 text-white"
            >
              <UserPlus className="h-4 w-4 mr-1.5" />
              {isEnrollingAll
                ? t('Enrolling...')
                : `${t('Enroll All Unenrolled')} (${enrollmentStats.not_enrolled})`}
            </Button>
          )}
        </div>
      </div>

      {/* ══════════════════════════════════════════════════════════════════════════
          TAB 1: ATTENDANCE PUNCH LOGS
         ══════════════════════════════════════════════════════════════════════════ */}
      {activeTab === 'logs' && (
        <>
          {/* ── Month + Week Card ── */}
          {!configurationMissing && (
            <div className="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm mb-4">

              {/* Month navigation */}
              <div className="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <button onClick={() => navigateMonth('prev')} className="p-1.5 rounded-md border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors cursor-pointer">
                  <ChevronLeft className="h-4 w-4 text-gray-500 dark:text-gray-400" />
                </button>
                <div className="flex flex-wrap items-center justify-center gap-2">
                  <h2 className="text-sm font-semibold text-gray-900 dark:text-gray-100">{monthLabel}</h2>
                  {selectedDate && (
                    <span className="flex flex-wrap items-center gap-1 text-xs text-gray-400 dark:text-gray-500">
                      <span className="hidden sm:inline">{t('Showing')}:</span>
                      <span className="font-medium text-gray-600 dark:text-gray-300">{window.appSettings.formatDateTimeSimple(selectedDate, false)}</span>
                      {biometricData?.total > 0 && (
                        <span className="inline-flex items-center px-1.5 py-0.5 rounded-full bg-primary/10 text-primary text-[11px] font-medium">
                          {biometricData.total} {t('records')}
                        </span>
                      )}
                    </span>
                  )}
                </div>
                <button onClick={() => navigateMonth('next')} className="p-1.5 rounded-md border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors cursor-pointer">
                  <ChevronRight className="h-4 w-4 text-gray-500 dark:text-gray-400" />
                </button>
              </div>

              {/* Week strip */}
              <div className="flex items-stretch">
                <button onClick={() => navigateWeek('prev')} className="px-2 sm:px-3 flex items-center justify-center border-r border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors cursor-pointer shrink-0">
                  <ChevronLeft className="h-4 w-4 text-gray-500 dark:text-gray-400" />
                </button>
                <div className="grid grid-cols-7 flex-1 min-w-0">
                  {(weekDays || []).map((day: any) => {
                    const isSelected = day.date === selectedDate;
                    return (
                      <button
                        key={day.date}
                        onClick={() => handleDayClick(day.date)}
                        className={`relative flex flex-col items-center justify-center py-2 sm:py-3 cursor-pointer transition-all border-r last:border-r-0 border-gray-100 dark:border-gray-800 ${isSelected ? 'bg-primary text-white'
                            : day.is_today ? 'bg-primary/10 dark:bg-primary/20 text-primary'
                              : 'hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400'
                          }`}
                      >
                        <span className="text-[10px] sm:text-[11px] font-medium uppercase tracking-wide">{day.day_name}</span>
                        <span className={`mt-0.5 sm:mt-1 text-xs sm:text-sm font-bold w-6 h-6 sm:w-8 sm:h-8 flex items-center justify-center rounded-full ${isSelected ? 'bg-white/20 text-white' : day.is_today ? 'bg-primary text-white' : ''
                          }`}>{day.day}</span>
                        {isSelected && <span className="absolute bottom-0 left-1/2 -translate-x-1/2 w-1 h-1 rounded-full bg-white" />}
                      </button>
                    );
                  })}
                </div>
                <button onClick={() => navigateWeek('next')} className="px-2 sm:px-3 flex items-center justify-center border-l border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors cursor-pointer shrink-0">
                  <ChevronRight className="h-4 w-4 text-gray-500 dark:text-gray-400" />
                </button>
              </div>
            </div>
          )}

          {/* ── Search bar — separate card ── */}
          {!configurationMissing && (
            <div className="bg-white dark:bg-gray-900 rounded-lg shadow mb-4 border">
              <SearchAndFilterBar
                searchTerm={searchTerm}
                onSearchChange={setSearchTerm}
                onSearch={handleSearch}
                searchPlaceholder={t('Search by employee name or code...')}
                filters={[
                  {
                    name: 'employee_code',
                    label: t('Employee'),
                    type: 'select',
                    value: selectedEmployeeCode,
                    onChange: setSelectedEmployeeCode,
                    searchable: true,
                    options: [
                      { value: '_empty_', label: t('All Employees'), disabled: true },
                      ...(employees || []).map((emp: any) => ({ value: emp.emp_code, label: emp.name }))
                    ]
                  }
                ]}
                showFilters={showFilters}
                setShowFilters={setShowFilters}
                hasActiveFilters={hasActiveFilters}
                activeFilterCount={activeFilterCount}
                onResetFilters={handleResetFilters}
              />
            </div>
          )}

          {/* ── Table card ── */}
          <div className="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
            <CrudTable
              columns={columns}
              actions={actions}
              data={biometricData?.data || []}
              from={biometricData?.from || 1}
              onAction={handleAction}
              permissions={permissions}
              entityPermissions={{
                view: 'view-biometric-attendance',
                create: 'manage-biometric-attendance',
                edit: 'manage-biometric-attendance',
                delete: 'manage-biometric-attendance'
              }}
            />
            <Pagination
              from={biometricData?.from || 0}
              to={biometricData?.to || 0}
              total={biometricData?.total || 0}
              links={biometricData?.links}
              entityName={t('biometric records')}
              onPageChange={handlePageChange}
              currentPerPage={pageFilters.per_page?.toString() || '10'}
              onPerPageChange={(value) => {
                router.get(route('hr.biometric-attendance.index'), buildParams({ per_page: value, page: 1 }), { preserveState: true, preserveScroll: true });
              }}
            />
          </div>
        </>
      )}

      {/* ══════════════════════════════════════════════════════════════════════════
          TAB 2: EMPLOYEE BIOMETRIC DEVICE ENROLLMENT STATUS & ENROLL ACTION
         ══════════════════════════════════════════════════════════════════════════ */}
      {activeTab === 'enrollment' && (
        <div className="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
          {/* Filter & Search Bar */}
          <div className="p-4 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-2">
              <button
                type="button"
                onClick={() => setEnrollmentFilter('all')}
                className={`px-3 py-1.5 rounded-md text-xs font-medium cursor-pointer transition-colors ${
                  enrollmentFilter === 'all'
                    ? 'bg-primary text-white'
                    : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200'
                }`}
              >
                {t('All Employees')} ({enrollmentStats.total})
              </button>
              <button
                type="button"
                onClick={() => setEnrollmentFilter('not_enrolled')}
                className={`px-3 py-1.5 rounded-md text-xs font-medium cursor-pointer transition-colors ${
                  enrollmentFilter === 'not_enrolled'
                    ? 'bg-amber-600 text-white'
                    : 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 hover:bg-amber-100'
                }`}
              >
                {t('Not Enrolled')} ({enrollmentStats.not_enrolled})
              </button>
              <button
                type="button"
                onClick={() => setEnrollmentFilter('enrolled')}
                className={`px-3 py-1.5 rounded-md text-xs font-medium cursor-pointer transition-colors ${
                  enrollmentFilter === 'enrolled'
                    ? 'bg-green-600 text-white'
                    : 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 hover:bg-green-100'
                }`}
              >
                {t('Enrolled on Device')} ({enrollmentStats.enrolled})
              </button>
            </div>

            <div className="relative w-full sm:w-72">
              <Search className="h-4 w-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <Input
                type="text"
                value={enrollmentSearch}
                onChange={(e) => setEnrollmentSearch(e.target.value)}
                placeholder={t('Search employee name, code, dept...')}
                className="pl-9 h-9 text-sm"
              />
            </div>
          </div>

          {/* Employees Enrollment Table */}
          <div className="overflow-x-auto">
            <table className="w-full text-sm text-left">
              <thead className="text-xs uppercase bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                <tr>
                  <th className="px-4 py-3">{t('Employee')}</th>
                  <th className="px-4 py-3">{t('Department / Designation')}</th>
                  <th className="px-4 py-3">{t('Biometric Employee Code')}</th>
                  <th className="px-4 py-3">{t('Device Serial No.')}</th>
                  <th className="px-4 py-3">{t('Biometric Status')}</th>
                  <th className="px-4 py-3 text-right">{t('Action')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {filteredEnrollments.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                      {t('No employees found matching the selected filter.')}
                    </td>
                  </tr>
                ) : (
                  filteredEnrollments.map((emp: any) => {
                    const isEnrolled = emp.is_biometric_enrolled;
                    const codeValue = customCodes[emp.employee_id] !== undefined
                      ? customCodes[emp.employee_id]
                      : (emp.employee_code || '');

                    return (
                      <tr key={emp.employee_id} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-primary text-white overflow-hidden shrink-0">
                              {emp.avatar
                                ? <img src={emp.avatar} alt={emp.name} className="h-full w-full object-cover" />
                                : getInitials(emp.name || '')}
                            </div>
                            <div>
                              <div className="font-medium text-gray-900 dark:text-gray-100">{emp.name}</div>
                              <div className="text-xs text-muted-foreground">{emp.system_employee_id} {emp.email ? `• ${emp.email}` : ''}</div>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <div className="text-sm text-gray-800 dark:text-gray-200">{emp.designation || '-'}</div>
                          <div className="text-xs text-muted-foreground">{emp.department || '-'}</div>
                        </td>
                        <td className="px-4 py-3">
                          {isEnrolled && emp.employee_code ? (
                            <span className="font-mono font-semibold text-sm px-2.5 py-1 rounded bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200">
                              {emp.employee_code}
                            </span>
                          ) : (
                            <Input
                              type="text"
                              value={codeValue}
                              onChange={(e) => {
                                const cleaned = e.target.value.replace(/\D/g, '').slice(0, 9);
                                setCustomCodes(prev => ({ ...prev, [emp.employee_id]: cleaned }));
                              }}
                              placeholder={t('Auto (4-9 digits)')}
                              className="w-36 h-8 font-mono text-xs"
                            />
                          )}
                        </td>
                        <td className="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">
                          {emp.biometric_device_sn || enrollmentStats.device_sn || 'RSS20220619704'}
                        </td>
                        <td className="px-4 py-3">
                          {isEnrolled ? (
                            <div className="flex flex-col gap-0.5">
                              <span className="inline-flex items-center gap-1.5 w-fit px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                <CheckCircle2 className="h-3.5 w-3.5" />
                                {t('Enrolled on Device')}
                              </span>
                              {emp.biometric_enrolled_at && (
                                <span className="text-[11px] text-muted-foreground">
                                  {emp.biometric_enrolled_at}
                                </span>
                              )}
                            </div>
                          ) : (
                            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                              <XCircle className="h-3.5 w-3.5" />
                              {t('Not Enrolled')}
                            </span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-right">
                          {!isEnrolled ? (
                            <Button
                              size="sm"
                              onClick={() => handleEnrollEmployee(emp)}
                              disabled={enrollingId === emp.employee_id}
                              className="bg-primary hover:bg-primary/90 text-white"
                            >
                              <UserPlus className="h-3.5 w-3.5 mr-1.5" />
                              {enrollingId === emp.employee_id
                                ? t('Enrolling...')
                                : t('Enroll in Biometric Device')}
                            </Button>
                          ) : (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleEnrollEmployee(emp)}
                              disabled={enrollingId === emp.employee_id}
                              className="text-xs"
                            >
                              <RefreshCw className={`h-3.5 w-3.5 mr-1.5 ${enrollingId === emp.employee_id ? 'animate-spin' : ''}`} />
                              {t('Re-sync to Device')}
                            </Button>
                          )}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Details Modal */}
      <Dialog open={showDetailsModal} onOpenChange={setShowDetailsModal}>
        <View employee={selectedEmployee} entries={detailEntries} />
      </Dialog>
    </PageTemplate>
  );
}
