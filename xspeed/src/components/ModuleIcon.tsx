import {
  Activity,
  BarChart3,
  CalendarClock,
  Clock,
  Cloud,
  Database,
  Eye,
  FileType,
  FileX,
  Filter,
  Gauge,
  Globe,
  HardDrive,
  HeartPulse,
  Image,
  Import,
  KeyRound,
  Layers,
  LayoutDashboard,
  LifeBuoy,
  Lightbulb,
  Network,
  Replace,
  Rss,
  Scissors,
  Search,
  Server,
  Settings as SettingsIcon,
  Shield,
  Shrink,
  Sliders,
  Sparkles,
  Square,
  Tag,
  Trash2,
  TrendingUp,
  Type,
  Wand2,
  Webhook,
  Wrench,
  Zap,
  type LucideIcon,
} from 'lucide-react';

/**
 * Whitelist of lucide-react icons modules can request via ui_metadata.
 *
 * Why a whitelist and not a dynamic require: bundle weight. Lucide
 * ships ~1500 icons; pulling them all is ~200 KB unnecessarily. The
 * whitelist forces us to think about which icons the dashboard
 * actually uses + keeps tree-shaking honest.
 *
 * Modules that ask for an unknown name fall back to Square so the UI
 * never crashes from a PHP-side typo.
 */
const ICONS: Record<string, LucideIcon> = {
  Activity,
  BarChart3,
  CalendarClock,
  Clock,
  Cloud,
  Database,
  Eye,
  FileType,
  FileX,
  Filter,
  Gauge,
  Globe,
  HardDrive,
  HeartPulse,
  Image,
  Import,
  KeyRound,
  Layers,
  LayoutDashboard,
  LifeBuoy,
  Lightbulb,
  Network,
  Replace,
  Rss,
  Scissors,
  Search,
  Server,
  Settings: SettingsIcon,
  Shield,
  Shrink,
  Sliders,
  Sparkles,
  Square,
  Tag,
  Trash2,
  TrendingUp,
  Type,
  Wand2,
  Webhook,
  Wrench,
  Zap,
};

export function ModuleIcon({
  name,
  size = 15,
  className,
}: {
  name: string;
  size?: number;
  className?: string;
}) {
  const Icon = ICONS[name] ?? Square;
  return <Icon size={size} className={className} />;
}
