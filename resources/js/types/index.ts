export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export interface Organization {
  id: string;
  name: string;
  shortCode: string;
}

export interface AuthUser {
  id: string;
  name: string;
  email: string;
  jobTitle: string | null;
  initials: string;
  isStaff: boolean;
  roles: string[];
  roleLabels: string[];
  organization: Organization | null;
}

export interface NotificationItem {
  id: string;
  title: string;
  body: string | null;
  at: string | null;
}

export interface PageProps {
  auth: { user: AuthUser | null; permissions: string[] };
  flash: { success?: string; error?: string; warning?: string; info?: string };
  app: { name: string };
  /** Avisos sin leer de la campana. Null sin sesión. */
  notifications: { unread: number; items: NotificationItem[] } | null;
  errors: Record<string, string>;
  [key: string]: unknown;
}

/** Forma de un paginador de Laravel pasado por Inertia. */
export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  from: number | null;
  to: number | null;
  total: number;
  links: { url: string | null; label: string; active: boolean }[];
}

export interface SelectOption {
  value: string;
  label: string;
  tone?: Tone;
}

export interface SortState {
  column: string;
  direction: 'asc' | 'desc';
}

export interface AssetRow {
  id: string;
  name: string;
  /** Empresa dueña del equipo. Null para un cliente: todo lo suyo ya es suyo. */
  organization: string | null;
  partNumber: string | null;
  serialNumber: string | null;
  code: string | null;
  clientReference: string | null;
  brand: string | null;
  model: string | null;
  identifier: string | null;
  reportNumber: string | null;
  reportId: string | null;
  expiresAt: string | null;
  status: string;
  statusLabel: string;
  statusTone: Tone;
}

export interface ReportRow {
  id: string;
  number: string;
  /** Empresa dueña del informe. Null para un cliente. */
  organization: string | null;
  equipment: string | null;
  inspectedAt: string | null;
  assetsCount: number;
  filesCount: number;
  status: string;
  statusLabel: string;
  statusTone: Tone;
}

export interface ReceptionRow {
  id: string;
  isUrgent: boolean;
  receivedAt: string | null;
  promisedAt: string | null;
  isOverdue: boolean;
  /** La etiqueta y el tono los resuelve el servidor: una sola fuente de verdad. */
  stage:
    | 'pending'
    | 'in_progress'
    | 'approved'
    | 'rejected'
    /* La reparación la decide el cliente: entre el rechazo y el arreglo hay
       una consulta, y de su respuesta salen dos finales distintos. */
    | 'awaiting_client'
    | 'repair_authorized'
    | 'repair_declined'
    | 'in_repair'
    | 'repaired'
    /* La reparación se intenta una vez: si falla, sólo queda "sin solución". */
    | 'repair_failed'
    | 'unrepairable'
    | 'dispatched'
    | 'returned_unrepaired';
  stageLabel: string;
  stageTone: Tone;
  startedAt: string | null;
  releasedAt: string | null;
  outcome: 'approved' | 'rejected' | null;
  outcomeNotes: string | null;
  repairRequestedAt: string | null;
  /** Qué se propuso reparar y con qué costo. Es lo que el cliente evalúa. */
  repairRequestNotes: string | null;
  repairDecision: 'approved' | 'declined' | null;
  repairDecidedAt: string | null;
  /** Por dónde llegó la respuesta, ya traducido a algo legible. */
  repairDecisionChannel: string | null;
  repairDecisionNotes: string | null;
  repairStartedAt: string | null;
  repairedAt: string | null;
  repairFailedAt: string | null;
  repairFailureNotes: string | null;
  unrepairableAt: string | null;
  unrepairableNotes: string | null;
  dispatchedAt: string | null;
  reportNumber: string | null;
  reportId: string | null;
  /** Operaciones que pidió el cliente para este equipo al mandarlo. */
  requested: string[];
  specialOperation: string | null;
  asset: {
    id: string;
    name: string;
    /** Empresa dueña. Null para un cliente. */
    organization: string | null;
    serialNumber: string | null;
    code: string | null;
    expiresAt: string | null;
    status: string;
    statusLabel: string;
    statusTone: Tone;
  };
  can: {
    start: boolean;
    resolve: boolean;
    /** Pedirle al cliente que autorice la reparación. */
    requestRepair: boolean;
    /** Contestar esa consulta. Es lo único que un cliente ejecuta acá. */
    decideRepair: boolean;
    /** Iniciar la reparación autorizada: la pieza vuelve a En proceso. */
    sendToRepair: boolean;
    /** Aprobar o rechazar la reparación en curso. */
    markRepaired: boolean;
    failRepair: boolean;
    /** Salida de Rechazadas sin consultar al cliente. */
    markUnrepairable: boolean;
    dispatch: boolean;
  };
}
