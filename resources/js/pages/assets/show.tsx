import { Deferred, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { EditAssetDialog, type EditCatalogs } from '@/components/assets/edit-asset-dialog';
import {
  ExternalCertificationDialog,
  type ExternalServiceOption,
} from '@/components/assets/external-certification-dialog';
import { AddComponentsDialog, RemoveComponentButton } from '@/components/assets/set-components';
import { TraceabilityThread, type TimelineEvent } from '@/components/assets/traceability-thread';
import { DocumentPanel, type DocumentPayload } from '@/components/documents/document-panel';
import {
  CalendarClock,
  Download,
  FileText,
  History,
  Info,
  Pencil,
  Plus,
  Puzzle,
  ShieldCheck,
  Trash2,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmButton } from '@/components/ui/confirm-button';
import { EmptyState } from '@/components/ui/empty-state';
import { Dialog, DialogTrigger } from '@/components/ui/dialog';
import { Panel, PanelHeader } from '@/components/ui/panel';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/cn';
import { expiryLabel, formatDate } from '@/lib/format';
import type { Tone } from '@/types';

interface HistoryEntry {
  id: number;
  result: string;
  resultLabel: string;
  resultTone: Tone;
  determinesFitness: boolean;
  expiresAt: string | null;
  seal: string | null;
  findings: string | null;
  service: string | null;
  report: {
    id: string;
    number: string;
    inspectedAt: string | null;
    status: string;
    statusLabel: string;
  };
  files: { id: string; name: string; size: string; category: string }[];
}

interface Certification {
  code: string;
  name: string;
  result: string;
  isApt: boolean;
  expiresAt: string | null;
  inspectedAt: string | null;
  /** Cargada a mano en la ficha: la certificó otra empresa, no Odessa. */
  external: boolean;
  /** Sólo en las cargadas a mano. */
  externalId?: number;
  serviceId?: number;
  certifiedBy?: string;
  certificateNumber?: string | null;
}

interface ComponentRow {
  id: string;
  name: string | null;
  serial: string | null;
  status: string;
  statusLabel: string;
  statusTone: Tone;
  expiresAt: string | null;
  /** Está impidiendo que el conjunto se declare apto. */
  isBlocking: boolean;
}

interface AssetDetail {
  id: string;
  kind: string;
  name: string;
  /** Empresa dueña del equipo. */
  organization: string | null;
  catalogItemId: number | null;
  manufacturerId: number | null;
  testPressureId: number | null;
  workingPressureId: number | null;
  connectionTopId: number | null;
  connectionBottomId: number | null;
  connectionSide1Id: number | null;
  connectionSide2Id: number | null;
  /** Conjunto y componentes. */
  isSet: boolean;
  isComposite: boolean;
  effectiveStatus: string;
  effectiveStatusLabel: string;
  effectiveStatusTone: Tone;
  effectiveExpiresAt: string | null;
  /** Las piezas lo dejan peor que su propio dictamen. */
  blockedByComponents: boolean;
  parent: { id: string; name: string | null; serial: string | null } | null;
  components: ComponentRow[];
  description: string | null;
  workshopDescription: string | null;
  serialNumber: string | null;
  code: string | null;
  clientReference: string | null;
  manufacturerReference: string | null;
  partNumber: string | null;
  catalogDescription: string | null;
  brand: string | null;
  model: string | null;
  identifier: string | null;
  manufacturer: string | null;
  testPressure: string | null;
  workingPressure: string | null;
  connections: Record<string, string>;
  status: string;
  statusLabel: string;
  statusTone: Tone;
  expiresAt: string | null;
  daysToExpiry: number | null;
  currentReport: { id: string; number: string } | null;
}

export default function AssetShow({
  asset,
  history,
  certifications,
  externalServices,
  documents,
  categories,
  can,
  timeline,
  catalogs,
}: {
  asset: AssetDetail;
  history: HistoryEntry[];
  certifications: Certification[];
  /** Operaciones donde se puede cargar a mano la certificación de otra empresa. Vacío si no puede. */
  externalServices: ExternalServiceOption[];
  documents: DocumentPayload[];
  categories: { value: string; label: string }[];
  can: { upload: boolean; deleteDocuments: boolean; edit: boolean; externalCertification: boolean };
  timeline?: TimelineEvent[];
  catalogs?: EditCatalogs;
}) {
  const [editing, setEditing] = useState(false);
  const isGeneric = asset.kind === 'generic';

  /*
    Un set con piezas muestra su estado EFECTIVO —el que sale de sus piezas—, no
    el propio: no se inspecciona como tal, y su «sin inspección» no dice nada.
  */
  const usaPiezas = asset.isSet && asset.components.length > 0;
  const shown = usaPiezas
    ? {
        status: asset.effectiveStatus,
        label: asset.effectiveStatusLabel,
        tone: asset.effectiveStatusTone,
        expiresAt: asset.effectiveExpiresAt,
      }
    : { status: asset.status, label: asset.statusLabel, tone: asset.statusTone, expiresAt: asset.expiresAt };

  const identity: [string, string | null][] = [
    ['Número de serie', asset.serialNumber],
    ['Código', asset.code],
    ['Referencia del cliente', asset.clientReference],
    ['Referencia del fabricante', asset.manufacturerReference],
    ...(isGeneric
      ? ([
          ['Identificador', asset.identifier],
          ['Marca', asset.brand],
          ['Modelo', asset.model],
        ] as [string, string | null][])
      : ([
          ['Número de parte', asset.partNumber],
          ['Descripción de catálogo', asset.catalogDescription],
        ] as [string, string | null][])),
  ];

  const technical: [string, string | null][] = [
    ['Fabricante', asset.manufacturer],
    ['Presión de test', asset.testPressure],
    ['Presión de trabajo', asset.workingPressure],
    ...Object.entries(asset.connections).map(([label, value]) => [`Conexión ${label.toLowerCase()}`, value] as [string, string]),
  ];

  /*
    Un set va ARRIBA: lo primero que se hace al crearlo es tildarle piezas, y la
    ficha es larga. Una pieza o conjunto común conserva el lugar de siempre.
  */
  const compositePanel = (
    <>
        {/* ================= Set de equipos / equipo compuesto ================= */}
        {(asset.isSet || asset.isComposite || asset.parent) && (
          <Panel className="mb-5">
            <PanelHeader
              title={asset.isSet ? 'Set de equipos' : 'Equipo compuesto'}
              description={
                asset.isSet || asset.isComposite
                  ? 'El set no se despliega a medias: alcanza con que una pieza no esté apta para bloquearlo.'
                  : 'Esta pieza es componente de un set.'
              }
              actions={
                asset.isSet && can.edit ? (
                  <AddComponentsDialog setId={asset.id} hasComponents={asset.components.length > 0} />
                ) : undefined
              }
            />

            {asset.parent && (
              <div className="border-b border-[var(--border-subtle)] px-4 py-3">
                <p className="text-[10px] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase">
                  Forma parte de
                </p>
                <Link
                  href={`/activos/${asset.parent.id}`}
                  className="mt-1 inline-flex items-center gap-1.5 text-[13px] font-medium text-[var(--text-accent)] hover:underline"
                >
                  <Puzzle className="size-3.5" aria-hidden />
                  {asset.parent.name ?? 'Set'}
                  {asset.parent.serial && (
                    <span className="font-mono text-[11px] text-[var(--text-muted)]">
                      {asset.parent.serial}
                    </span>
                  )}
                </Link>
              </div>
            )}

            {asset.isSet && asset.components.length === 0 && (
              <EmptyState
                icon={Puzzle}
                title="Este set todavía no tiene piezas"
                description={
                  can.edit
                    ? 'Use «Agregar piezas» para buscar y tildar las que lo componen.'
                    : 'Odessa le va a cargar las piezas que lo componen.'
                }
              />
            )}

            {asset.components.length > 0 && (
              <>
                {/*
                  Cuando el set está bloqueado, lo primero es decir por
                  cuál de sus piezas: sin eso el taller no sabe qué reparar.
                */}
                {asset.blockedByComponents && (
                  <div className="border-b border-[var(--tone-danger-fg)]/25 bg-[var(--tone-danger-bg)] px-4 py-3">
                    <p className="text-[12.5px] font-medium text-[var(--tone-danger-fg)]">
                      El set está {asset.effectiveStatusLabel.toLowerCase()} por sus piezas,
                      aunque su propia certificación figure como {asset.statusLabel.toLowerCase()}.
                    </p>
                  </div>
                )}

                <div className="overflow-x-auto">
                  <table className="w-full min-w-[560px] text-[12.5px]">
                    <thead>
                      <tr>
                        {['Pieza', 'Serie', 'Estado', 'Vence'].map((header) => (
                          <th
                            key={header}
                            scope="col"
                            className="h-9 border-b border-[var(--border-default)] bg-[var(--surface-sunken)] px-3 text-left text-[10px] font-semibold tracking-[0.08em] text-[var(--text-secondary)] uppercase"
                          >
                            {header}
                          </th>
                        ))}
                        {asset.isSet && can.edit && (
                          <th
                            scope="col"
                            className="h-9 w-10 border-b border-[var(--border-default)] bg-[var(--surface-sunken)]"
                          >
                            <span className="sr-only">Acciones</span>
                          </th>
                        )}
                      </tr>
                    </thead>
                    <tbody>
                      {asset.components.map((component) => (
                        <tr
                          key={component.id}
                          className={cn(
                            'border-b border-[var(--border-subtle)] last:border-b-0',
                            component.isBlocking && 'bg-[var(--tone-danger-bg)]/40',
                          )}
                        >
                          <td className="px-3 py-2.5">
                            <Link
                              href={`/activos/${component.id}`}
                              className="font-medium text-[var(--text-accent)] hover:underline"
                            >
                              {component.name ?? 'Sin descripción'}
                            </Link>
                          </td>
                          <td className="px-3 py-2.5 font-mono text-[11.5px] text-[var(--text-muted)]">
                            {component.serial ?? '—'}
                          </td>
                          <td className="px-3 py-2.5">
                            <Badge tone={component.statusTone}>{component.statusLabel}</Badge>
                          </td>
                          <td className="px-3 py-2.5 tabular">{formatDate(component.expiresAt)}</td>
                          {asset.isSet && can.edit && (
                            <td className="px-2 py-1.5 text-right">
                              <RemoveComponentButton
                                setId={asset.id}
                                pieceId={component.id}
                                name={component.name ?? 'la pieza'}
                              />
                            </td>
                          )}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </Panel>
        )}
    </>
  );

  return (
    <AppLayout
      title={asset.name}
      description={
        [asset.organization, asset.workshopDescription].filter(Boolean).join(' · ') || undefined
      }
      breadcrumbs={[
        { label: 'Activos', href: `/activos?kind=${asset.kind}` },
        { label: asset.serialNumber ?? asset.name },
      ]}
      actions={
        <div className="flex items-center gap-2">
          {asset.currentReport && (
            <Button asChild variant="secondary" size="sm">
              <Link href={`/informes/${asset.currentReport.id}`}>
                <FileText aria-hidden />
                Informe N° {asset.currentReport.number}
              </Link>
            </Button>
          )}

          {can.edit && (
            <Dialog open={editing} onOpenChange={setEditing}>
              <DialogTrigger asChild>
                <Button variant="primary" size="sm">
                  <Pencil aria-hidden />
                  Editar ficha
                </Button>
              </DialogTrigger>
              {/*
                Los catálogos llegan diferidos: son seis consultas que sólo
                hacen falta si alguien abre el formulario.
              */}
              <Deferred data="catalogs" fallback={<></>}>
                {catalogs && (
                  <EditAssetDialog
                    asset={asset}
                    catalogs={catalogs}
                    onDone={() => setEditing(false)}
                  />
                )}
              </Deferred>
            </Dialog>
          )}
        </div>
      }
    >
      <Head title={asset.name} />

      {/* ================= Dictamen vigente ================= */}
      <Panel className="mb-5">
        <div className="grid gap-px bg-[var(--border-subtle)] sm:grid-cols-3">
          <div className="bg-[var(--surface-raised)] p-4">
            <p className="text-[11px] font-medium text-[var(--text-secondary)]">Estado de aptitud</p>
            <div className="mt-2">
              <Badge tone={shown.tone} className="px-2.5 py-1.5 text-[12.5px]">
                {shown.label}
              </Badge>
            </div>
          </div>

          <div className="bg-[var(--surface-raised)] p-4">
            <p className="text-[11px] font-medium text-[var(--text-secondary)]">Vencimiento</p>
            <p className="mt-1.5 text-[20px] leading-none font-semibold tabular">
              {formatDate(shown.expiresAt)}
            </p>
            <p
              className={cn(
                'mt-1.5 text-[11.5px]',
                shown.status === 'unfit'
                  ? 'text-[var(--tone-danger-fg)]'
                  : shown.status === 'expiring'
                    ? 'text-[var(--tone-warning-fg)]'
                    : 'text-[var(--text-muted)]',
              )}
            >
              {expiryLabel(shown.expiresAt)}
            </p>
          </div>

          <div className="bg-[var(--surface-raised)] p-4">
            <p className="text-[11px] font-medium text-[var(--text-secondary)]">
              Inspección determinante
            </p>
            {asset.currentReport ? (
              <>
                <Link
                  href={`/informes/${asset.currentReport.id}`}
                  className="mt-1.5 inline-block font-mono text-[18px] leading-none font-semibold text-[var(--text-accent)] hover:underline"
                >
                  {asset.currentReport.number}
                </Link>
                <p className="mt-1.5 text-[11.5px] text-[var(--text-muted)]">
                  Último informe con dictamen apto o no apto
                </p>
              </>
            ) : (
              <p className="mt-1.5 text-[13px] text-[var(--text-muted)]">Sin inspección determinante</p>
            )}
          </div>
        </div>

        {/*
          Vencimiento por tipo de certificación: la pieza puede estar al día en
          la anual (COS) y en la de conformidad (COC) a la vez, cada una con su
          fecha. El "Vencimiento" de arriba es el de la inspección más reciente;
          acá se ven los dos.
        */}
        {(certifications.length > 0 || externalServices.length > 0) && (
          <div className="border-t border-[var(--border-subtle)] px-4 py-3">
            <div className="mb-2 flex items-center justify-between gap-3">
              <p className="text-[11px] font-medium text-[var(--text-secondary)]">
                Vencimientos por certificación
              </p>
              {/*
                Primera carga: el equipo ya viene certificado por otra empresa.
                No se crea un informe; se registra acá y queda en la auditoría.
              */}
              {can.externalCertification && externalServices.length > 0 && (
                <ExternalCertificationDialog assetId={asset.id} services={externalServices}>
                  <Button variant="secondary" size="sm">
                    <Plus aria-hidden />
                    Cargar certificación de otra empresa
                  </Button>
                </ExternalCertificationDialog>
              )}
            </div>
            <ul className="flex flex-wrap gap-2">
              {certifications.map((cert) => (
                <li
                  key={cert.code}
                  className="inline-flex flex-wrap items-center gap-2 rounded-xs border border-[var(--border-subtle)] bg-[var(--surface-sunken)] px-2.5 py-1.5"
                  title={
                    cert.external
                      ? `${cert.name} · certificada por ${cert.certifiedBy}${cert.certificateNumber ? ` (N° ${cert.certificateNumber})` : ''}`
                      : cert.name
                  }
                >
                  <span className="font-mono text-[11px] font-semibold text-[var(--text-primary)]">
                    {cert.code}
                  </span>
                  {cert.isApt ? (
                    <span className="text-[11.5px] text-[var(--text-secondary)]">
                      vence{' '}
                      <span className="tabular font-medium text-[var(--text-primary)]">
                        {formatDate(cert.expiresAt)}
                      </span>
                      {cert.expiresAt && (
                        <span className="ml-1 text-[var(--text-muted)]">· {expiryLabel(cert.expiresAt)}</span>
                      )}
                    </span>
                  ) : (
                    <Badge tone="danger">{cert.result}</Badge>
                  )}
                  {cert.external && (
                    <>
                      <Badge tone="info">Otra empresa · {cert.certifiedBy}</Badge>
                      {can.externalCertification && cert.externalId !== undefined && (
                        <span className="inline-flex items-center gap-1">
                          <ExternalCertificationDialog
                            assetId={asset.id}
                            services={externalServices}
                            current={{
                              serviceId: cert.serviceId ?? 0,
                              code: cert.code,
                              name: cert.name,
                              certifiedBy: cert.certifiedBy ?? '',
                              certificateNumber: cert.certificateNumber ?? null,
                              inspectedAt: cert.inspectedAt,
                              expiresAt: cert.expiresAt,
                            }}
                          >
                            <Button variant="ghost" size="sm" aria-label={`Editar la certificación ${cert.code}`}>
                              <Pencil aria-hidden />
                            </Button>
                          </ExternalCertificationDialog>
                          <ConfirmButton
                            variant="ghost"
                            size="sm"
                            destructive
                            aria-label={`Quitar la certificación ${cert.code}`}
                            title={`Quitar la certificación ${cert.code}`}
                            description="Se quita de la ficha la certificación cargada a mano. El estado del equipo se recalcula."
                            confirmLabel="Quitar"
                            onConfirm={() =>
                              router.delete(`/activos/${asset.id}/certificaciones-externas/${cert.externalId}`, {
                                preserveScroll: true,
                              })
                            }
                          >
                            <Trash2 aria-hidden />
                          </ConfirmButton>
                        </span>
                      )}
                    </>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
      </Panel>

      {asset.isSet && compositePanel}

      <div className="grid gap-5 lg:grid-cols-[1fr_1.4fr]">
        {/* ================= Ficha ================= */}
        <div className="flex flex-col gap-5">
          <Panel>
            <PanelHeader title="Identificación" />
            <DefinitionList entries={identity} />
          </Panel>

          {!isGeneric && technical.some(([, value]) => value) && (
            <Panel>
              <PanelHeader title="Ficha técnica" />
              <DefinitionList entries={technical} />
            </Panel>
          )}

          {/* ================= Bitácora ================= */}
          <Panel>
            <PanelHeader
              title="Bitácora de inspecciones"
              description={`${history.length} ${history.length === 1 ? 'intervención registrada' : 'intervenciones registradas'}.`}
            />

            {history.length === 0 ? (
              <EmptyState
                icon={History}
                title="Sin historial"
                description="Esta pieza aún no registra inspecciones."
              />
            ) : (
              <ol className="divide-y divide-[var(--border-subtle)]">
                {history.map((entry) => (
                  <li key={entry.id} className="px-4 py-3.5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <Link
                            href={`/informes/${entry.report.id}`}
                            className="font-mono text-[13px] font-semibold text-[var(--text-accent)] hover:underline"
                          >
                            Informe N° {entry.report.number}
                          </Link>
                          <Badge tone={entry.resultTone}>{entry.resultLabel}</Badge>
                          {entry.determinesFitness && (
                            <span
                              className="inline-flex items-center gap-1 text-[10.5px] text-[var(--text-muted)]"
                              title="Este resultado fija el estado de aptitud de la pieza"
                            >
                              <ShieldCheck className="size-3" aria-hidden />
                              Determina aptitud
                            </span>
                          )}
                        </div>

                        <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11.5px] text-[var(--text-secondary)]">
                          <span className="inline-flex items-center gap-1">
                            <CalendarClock className="size-3" aria-hidden />
                            {formatDate(entry.report.inspectedAt)}
                          </span>
                          {entry.service && <span>{entry.service}</span>}
                          {entry.expiresAt && <span>Vigencia hasta {formatDate(entry.expiresAt)}</span>}
                          {entry.seal && <span>Precinto {entry.seal}</span>}
                        </p>

                        {entry.findings && (
                          <p className="mt-2 flex items-start gap-1.5 rounded-xs bg-[var(--surface-sunken)] px-2.5 py-2 text-[11.5px] text-[var(--text-secondary)]">
                            <Info className="mt-0.5 size-3 shrink-0" aria-hidden />
                            {entry.findings}
                          </p>
                        )}
                      </div>
                    </div>

                    {entry.files.length > 0 && (
                      <ul className="mt-2.5 flex flex-wrap gap-1.5">
                        {entry.files.map((file) => (
                          <li key={file.id}>
                            <a
                              href={`/documentos/${file.id}/descargar`}
                              className="inline-flex max-w-full items-center gap-1.5 rounded-xs border border-[var(--border-subtle)] bg-[var(--surface-sunken)] px-2 py-1 text-[11px] hover:border-[var(--border-default)] hover:bg-[var(--surface-hover)]"
                            >
                              <Download className="size-3 shrink-0 text-[var(--text-accent)]" aria-hidden />
                              <span className="truncate">{file.name}</span>
                              <span className="shrink-0 text-[var(--text-muted)]">{file.size}</span>
                            </a>
                          </li>
                        ))}
                      </ul>
                    )}
                  </li>
                ))}
              </ol>
            )}
          </Panel>

          {/*
            Documentos propios de la pieza, distintos de los del Informe: aquí van
            el certificado del fabricante, la ficha técnica o las fotos que
            acompañan a la pieza durante toda su vida, no a una inspección.
          */}
          <DocumentPanel
            title="Documentos de la pieza"
            description="Documentación propia del activo, independiente de cada inspección."
            documents={documents}
            categories={categories}
            uploadUrl={`/activos/${asset.id}/documentos`}
            canUpload={can.upload}
            canDelete={can.deleteDocuments}
            emptyHint="Adjunte la ficha del fabricante, certificados de origen o fotografías."
          />
        </div>

        {!asset.isSet && compositePanel}

        {/* ================= Hilo de trazabilidad ================= */}
        <Panel>
          <PanelHeader
            title="Trazabilidad del equipo"
            description="Todo lo que le pasó a esta pieza: quién, qué y cuándo. Envíos, taller, inspecciones, documentos y accesos."
          />
          <Deferred
            data="timeline"
            fallback={
              <div className="space-y-3 p-4">
                <Skeleton className="h-10 w-full" />
                <Skeleton className="h-10 w-full" />
                <Skeleton className="h-10 w-2/3" />
              </div>
            }
          >
            <TraceabilityThread events={timeline ?? []} />
          </Deferred>
        </Panel>
      </div>
    </AppLayout>
  );
}

function DefinitionList({ entries }: { entries: [string, string | null][] }) {
  const visible = entries.filter(([, value]) => value);

  if (visible.length === 0) {
    return <p className="px-4 py-6 text-[12.5px] text-[var(--text-muted)]">Sin datos registrados.</p>;
  }

  return (
    <dl className="divide-y divide-[var(--border-subtle)]">
      {visible.map(([label, value]) => (
        <div key={label} className="grid grid-cols-[minmax(0,140px)_1fr] gap-3 px-4 py-2.5">
          <dt className="text-[11.5px] text-[var(--text-secondary)]">{label}</dt>
          <dd className="min-w-0 text-[12.5px] break-words">{value}</dd>
        </div>
      ))}
    </dl>
  );
}
