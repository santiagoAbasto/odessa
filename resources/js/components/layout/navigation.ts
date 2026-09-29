import {
  BarChart3,
  Building2,
  ClipboardList,
  FileSearch,
  Layers,
  LayoutDashboard,
  ScrollText,
  Settings2,
  ShieldCheck,
  Truck,
  UserPlus,
  Users,
  Warehouse,
  type LucideIcon,
} from 'lucide-react';

export interface NavItem {
  label: string;
  href: string;
  icon: LucideIcon;
  /** Permiso requerido; si falta, el ítem no se pinta. */
  permission?: string;
  /** Prefijos de ruta que marcan este ítem como activo. */
  match: string[];
  badgeKey?: string;
}

export interface NavGroup {
  label: string;
  items: NavItem[];
}

/**
 * Arquitectura de información.
 *
 * El sistema auditado mezclaba "Área del cliente" y "Taller" en dos dominios
 * separados con menús distintos. Aquí es una sola aplicación y el menú se
 * compone según los permisos: un usuario de cliente sencillamente no ve el
 * grupo Taller ni el de Administración.
 */
export const navigation: NavGroup[] = [
  {
    label: 'Operación',
    items: [
      {
        label: 'Panel',
        href: '/panel',
        icon: LayoutDashboard,
        match: ['/panel'],
      },
      {
        label: 'Activos',
        href: '/activos',
        icon: ClipboardList,
        permission: 'assets.view',
        match: ['/activos'],
      },
      {
        label: 'Sets de equipo',
        href: '/sets',
        icon: Layers,
        permission: 'sets.view',
        match: ['/sets'],
      },
      {
        label: 'Informes',
        href: '/informes',
        icon: FileSearch,
        permission: 'reports.view',
        match: ['/informes'],
      },
      {
        label: 'Envíos al taller',
        href: '/envios',
        icon: Truck,
        permission: 'shipments.view',
        match: ['/envios'],
      },
      {
        label: 'Reportes',
        href: '/reportes',
        icon: BarChart3,
        permission: 'reports.view',
        match: ['/reportes'],
      },
    ],
  },
  {
    label: 'Taller',
    items: [
      {
        label: 'Gestión de equipos',
        href: '/taller/recepciones',
        icon: Warehouse,
        permission: 'receptions.view',
        match: ['/taller/recepciones'],
      },
    ],
  },
  {
    label: 'Administración',
    items: [
      {
        label: 'Organizaciones',
        href: '/administracion/organizaciones',
        icon: Building2,
        permission: 'organizations.manage',
        match: ['/administracion/organizaciones'],
      },
      {
        label: 'Usuarios',
        href: '/administracion/usuarios',
        icon: Users,
        permission: 'users.view',
        match: ['/administracion/usuarios'],
      },
      {
        label: 'Solicitudes de acceso',
        href: '/administracion/solicitudes',
        icon: UserPlus,
        permission: 'account_requests.manage',
        match: ['/administracion/solicitudes'],
      },
      {
        label: 'Operaciones',
        href: '/administracion/operaciones',
        icon: Settings2,
        permission: 'catalogs.manage',
        match: ['/administracion/operaciones'],
      },
      {
        label: 'Auditoría',
        href: '/administracion/auditoria',
        icon: ScrollText,
        permission: 'audit.view',
        match: ['/administracion/auditoria'],
      },
    ],
  },
];

export const securityIcon = ShieldCheck;
