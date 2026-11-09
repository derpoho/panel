import { Bug, ChartLine, MailCheck, MousePointerClick } from '@lucide/vue';
import type { Component } from 'vue';
import {
    SolarBadgePercent,
    SolarBookOpen,
    SolarBriefcaseBusiness,
    SolarBuilding2,
    SolarChartNoAxesColumn,
    SolarCircleArrowUp,
    SolarFileText,
    SolarHammer,
    SolarHandshake,
    SolarHeartPulse,
    SolarLayoutDashboard,
    SolarMail,
    SolarMegaphone,
    SolarMessageSquareText,
    SolarNotebookPen,
    SolarPenLine,
    SolarPlay,
    SolarPulse,
    SolarRocket,
    SolarScrollText,
    SolarServer,
    SolarSupport,
    SolarUsers,
    SolarZap,
} from '@/lib/solarIcons';

/**
 * The icons the staff admin's sidebar items can have
 * (App\Support\Extensions\AdminNavItem names one of these). Add one here
 * when a page needs it.
 */
const icons: Record<string, Component> = {
    activity: SolarPulse,
    'badge-percent': SolarBadgePercent,
    'book-open': SolarBookOpen,
    briefcase: SolarBriefcaseBusiness,
    bug: Bug,
    building: SolarBuilding2,
    chart: SolarChartNoAxesColumn,
    'chart-line': ChartLine,
    'circle-arrow-up': SolarCircleArrowUp,
    dashboard: SolarLayoutDashboard,
    'file-text': SolarFileText,
    hammer: SolarHammer,
    handshake: SolarHandshake,
    'heart-pulse': SolarHeartPulse,
    mail: SolarMail,
    'mail-check': MailCheck,
    megaphone: SolarMegaphone,
    'message-square': SolarMessageSquareText,
    'mouse-pointer-click': MousePointerClick,
    'notebook-pen': SolarNotebookPen,
    'pen-line': SolarPenLine,
    play: SolarPlay,
    rocket: SolarRocket,
    'scroll-text': SolarScrollText,
    server: SolarServer,
    support: SolarSupport,
    users: SolarUsers,
    zap: SolarZap,
};

/** The icon called name, or the dashboard's for one we don't know. */
export function adminIcon(name: string): Component {
    return icons[name] ?? SolarLayoutDashboard;
}
