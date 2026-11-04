import { Head, Link, router } from '@inertiajs/react';
import { CheckCheck, History, Layers, Package, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DocumentPanel, type DocumentPayload } from '@/components/documents/document-panel';
import { AddAssetsDialog, ResolveLinesDialog } from '@/components/reports/line-editor';
import { DeleteReportDialog, EditReportDialog } from '@/components/reports/report-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmButton } from '@/components/ui/confirm-button';
import { EmptyState } from '@/components/ui/empty-state';
import { Panel, PanelHeader } from '@/components/ui/panel';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Tone } from '@/types';

interface ReportLine {
  id: number;
  asset: {
    id: string;
    name: string;
    serialNumber: string | null;
    code: string | null;
    clientReference: string | null;
    partNumber: string | null;
    kind: string;
  };
  result: string;
  resultLabel: string;
  resultTone: Tone;
  service: string | null;
  expiresAt: string | null;
  seal: string | null;
  findings: string | null;
}

interface HistoryEntry {
  id: number;
  label: string;
  tone: Tone;
  description: string | null;
  user: string | null;
  at: string | null;
  changes: { old?: Record<string, unknown>; new?: Record<string, unknown> } | null;
}

interface ReportDetail {
  id: string;
  number: string;
  equipment: string | null;
  setId: string | null;
  inspectedAt: string | null;
  status: string;
  statusLabel: string;
  statusTone: Tone;
  service: string | null;
  serviceId: string;
  equipmentLabel: string | null;
  notes: string | null;
  createdBy: string | null;
  finalizedBy: string | null;
  finalizedAt: string | null;
}



export default function ReportShow({
  report,
  lines,
  files,
  categories,
  serviceOptions,
  history,
  can,
}: {
  report: ReportDetail;
  lines: ReportLine[];
  files: DocumentPayload[];
  categories: { value: string; label: string }[];
  serviceOptions: { value: string; label: string }[];
  /** Null cuando el usuario no puede ver la auditoría. */
  history: HistoryEntry[] | null;
  can: {
    finalize: boolean;
    update: boolean;
    cancel: boolean;
    delete: boolean;
    upload: boolean;
    deleteDocuments: boolean;
  };
}) {
  /*
    Selección para dictaminar en tanda. Se limpia cuando cambian las líneas
    —después de guardar un dictamen o de agregar equipos— para que no queden
    marcados ids que ya no están en pantalla.
  */
  const [seleccion, setSeleccion] = useState<number[]>([]);
  useEffect(() => setSeleccion([]), [lines]);

  // Un informe final también se corrige (taller y superadministrador): el servidor
  // lo autoriza con can.update y recalcula las piezas al guardar.
  const editable = can.update;
  const finalizado = report.status === 'final';
  const todasMarcadas = lines.length > 0 && seleccion.length === lines.length;

  return (
    <AppLayout
      title={`Informe N° ${report.number}`}
      description={report.equipment ?? undefined}
      breadcrumbs={[{ label: 'Informes', href: '/informes' }, { label: report.number }]}
      actions={
        <>
          {can.update && <EditReportDialog report={report} serviceOptions={serviceOptions} />}

          {editable && <AddAssetsDialog reportId={report.id} />}

          {editable && (
            <ResolveLinesDialog
              reportId={report.id}
              lineIds={seleccion}
              finalized={finalizado}
              onDone={() => setSeleccion([])}
            />
          )}

          {can.delete && <DeleteReportDialog report={report} />}

          {can.finalize && (
          <ConfirmButton
            variant="primary"
            size="sm"
            title={`Finalizar el informe N° ${report.number}`}
            description="Al finalizar se calculan los vencimientos y se actualiza el estado de todas las piezas incluidas. El informe deja de ser editable."
            confirmLabel="Finalizar informe"
            onConfirm={() => router.post(`/informes/${report.id}/finalizar`)}
          >
            <CheckCheck aria-hidden />
            Finalizar
          </ConfirmButton>
          )}
        </>
      }
    >
      <Head title={`Informe N° ${report.number}`} />

      {finalizado && editable && (
        <div
          role="note"
          className="mb-5 flex items-start gap-2.5 rounded-xs border border-[var(--tone-warning-border,var(--border-default))] bg-[var(--tone-warning-bg,var(--surface-sunken))] px-3.5 py-2.5 text-[12px] text-[var(--text-secondary)]"
        >
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-[var(--tone-warning-fg,currentColor)]" aria-hidden />
          <p>
            Este informe ya está <strong>finalizado</strong>. Cualquier corrección recalcula el estado de sus
            piezas y queda registrada en la auditoría.
          </p>
        </div>
      )}

      {/* ================= Cabecera ================= */}
      <Panel className="mb-5">
        <div className="grid gap-px bg-[var(--border-subtle)] sm:grid-cols-2 lg:grid-cols-4">
          <Metric label="Estado">
            <Badge tone={report.statusTone} className="px-2.5 py-1.5 text-[12.5px]">
              {report.statusLabel}
            </Badge>
          </Metric>
          <Metric label="Fecha de inspección">
            <p className="text-[18px] leading-none font-semibold tabular">
              {formatDate(report.inspectedAt)}
            </p>
          </Metric>
          <Metric label="Piezas incluidas">
            <p className="text-[18px] leading-none font-semibold tabular">{lines.length}</p>
          </Metric>
          <Metric label="Equipo / set">
            {report.setId ? (
              <Link
                href={`/sets/${report.setId}`}
                className="inline-flex items-center gap-1.5 text-[13px] text-[var(--text-accent)] hover:underline"
              >
                <Layers className="size-3.5" aria-hidden />
                {report.equipment}
              </Link>
            ) : (
              <p className="text-[13px]">{report.equipment ?? '—'}</p>
            )}
          </Metric>
        </div>

        {(report.service || report.createdBy || report.finalizedAt || report.notes) && (
          <div className="border-t border-[var(--border-subtle)] px-4 py-3">
            <dl className="flex flex-wrap gap-x-8 gap-y-2 text-[11.5px]">
              {report.service && (
                <div>
                  <dt className="text-[var(--text-secondary)]">Servicio</dt>
                  <dd className="mt-0.5">{report.service}</dd>
                </div>
              )}
              {report.createdBy && (
                <div>
                  <dt className="text-[var(--text-secondary)]">Registrado por</dt>
                  <dd className="mt-0.5">{report.createdBy}</dd>
                </div>
              )}
              {report.finalizedAt && (
                <div>
                  <dt className="text-[var(--text-secondary)]">Finalizado</dt>
                  <dd className="mt-0.5">
                    {formatDateTime(report.finalizedAt)}
                    {report.finalizedBy && ` · ${report.finalizedBy}`}
                  </dd>
                </div>
              )}
            </dl>
            {report.notes && (
              <p className="mt-3 rounded-xs bg-[var(--surface-sunken)] px-3 py-2 text-[12px] text-[var(--text-secondary)]">
                {report.notes}
              </p>
            )}
          </div>
        )}
      </Panel>

      <div className="grid gap-5 lg:grid-cols-[1.6fr_1fr]">
        {/* ================= Piezas del informe ================= */}
        <Panel>
          <PanelHeader
            title="Piezas inspeccionadas"
            description="Cada pieza lleva su propio dictamen y vigencia."
          />

          {lines.length === 0 ? (
            <EmptyState
              icon={Package}
              title="Sin piezas asociadas"
              description={
                editable
                  ? 'Use «Agregar equipos» para cargar lo que se inspeccionó.'
                  : undefined
              }
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-[12.5px]">
                <thead>
                  <tr>
                    {editable && (
                      <th
                        scope="col"
                        className="h-9 w-9 border-b border-[var(--border-default)] bg-[var(--surface-sunken)] px-3"
                      >
                        <input
                          type="checkbox"
                          checked={todasMarcadas}
                          onChange={(event) =>
                            setSeleccion(event.target.checked ? lines.map((line) => line.id) : [])
                          }
                          aria-label="Seleccionar todas las piezas"
                          className="size-3.5 rounded-xs accent-[var(--accent)]"
                        />
                      </th>
                    )}
                    {['Pieza', 'Serie', 'Servicio', 'Vigencia', 'Resultado'].map((header) => (
                      <th
                        key={header}
                        scope="col"
                        className="h-9 border-b border-[var(--border-default)] bg-[var(--surface-sunken)] px-3 text-left text-[10px] font-semibold tracking-[0.08em] text-[var(--text-secondary)] uppercase"
                      >
                        {header}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {lines.map((line) => (
                    <tr
                      key={line.id}
                      className="border-b border-[var(--border-subtle)] last:border-b-0 hover:bg-[var(--surface-hover)]"
                    >
                      {editable && (
                        <td className="px-3 py-2.5">
                          <input
                            type="checkbox"
                            checked={seleccion.includes(line.id)}
                            onChange={(event) =>
                              setSeleccion((previa) =>
                                event.target.checked
                                  ? [...previa, line.id]
                                  : previa.filter((id) => id !== line.id),
                              )
                            }
                            aria-label={`Seleccionar ${line.asset.name}`}
                            className="size-3.5 rounded-xs accent-[var(--accent)]"
                          />
                        </td>
                      )}
                      <td className="px-3 py-2.5">
                        <Link
                          href={`/activos/${line.asset.id}`}
                          className="font-medium text-[var(--text-accent)] hover:underline"
                        >
                          {line.asset.name}
                        </Link>
                        {line.asset.partNumber && (
                          <p className="mt-0.5 font-mono text-[10.5px] text-[var(--text-muted)]">
                            {line.asset.partNumber}
                          </p>
                        )}
                        {line.findings && (
                          <p className="mt-1 text-[11px] text-[var(--tone-danger-fg)]">{line.findings}</p>
                        )}
                      </td>
                      <td className="px-3 py-2.5 font-mono text-[11.5px]">
                        {line.asset.serialNumber ?? '—'}
                      </td>
                      <td className="px-3 py-2.5 text-[11.5px] text-[var(--text-secondary)]">
                        {line.service ?? '—'}
                      </td>
                      <td className="px-3 py-2.5 tabular">
                        {line.expiresAt ? formatDate(line.expiresAt) : '—'}
                        {line.seal && (
                          <span className="mt-0.5 block text-[10.5px] text-[var(--text-muted)]">
                            Precinto {line.seal}
                          </span>
                        )}
                      </td>
                      <td className="px-3 py-2.5">
                        <Badge tone={line.resultTone}>{line.resultLabel}</Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        {/* ================= Documentos ================= */}
        <DocumentPanel
          title="Documentos del informe"
          description="Certificados e informes que cubren todas las piezas del informe."
          documents={files}
          categories={categories}
          uploadUrl={`/informes/${report.id}/documentos`}
          canUpload={can.upload}
          canDelete={can.deleteDocuments}
          emptyHint="Adjunte aquí el informe firmado o el certificado del informe."
        />
      </div>

      {/* ================= Historial de cambios (auditoría) ================= */}
      {history && (
        <Panel className="mt-5">
          <PanelHeader
            title="Historial de cambios"
            description="Quién hizo qué sobre este informe, con lo que había antes."
          />
          {history.length === 0 ? (
            <EmptyState icon={History} title="Sin cambios registrados" />
          ) : (
            <ul className="divide-y divide-[var(--border-subtle)]">
              {history.map((entry) => (
                <li key={entry.id} className="px-4 py-3 text-[12px]">
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <Badge tone={entry.tone}>{entry.label}</Badge>
                    <span className="text-[var(--text-muted)] tabular">
                      {entry.at ? formatDateTime(entry.at) : '—'}
                      {entry.user && ` · ${entry.user}`}
                    </span>
                  </div>
                  {entry.description && <p className="mt-1.5">{entry.description}</p>}
                  <ChangeDiff changes={entry.changes} />
                </li>
              ))}
            </ul>
          )}
        </Panel>
      )}
    </AppLayout>
  );
}

const CAMPOS: Record<string, string> = {
  number: 'Número',
  inspected_at: 'Fecha de inspección',
  service_id: 'Operación',
  equipment_label: 'Equipo',
  notes: 'Notas',
  result: 'Resultado',
  expires_at: 'Vencimiento',
};

const RESULTADOS: Record<string, string> = {
  apto: 'Apto',
  no_apto: 'No apto',
  no_aplica: 'No aplica',
  sin_procesar: 'Sin procesar',
};

interface LineaAntes {
  equipo: string | null;
  result: string;
  expires_at: string | null;
}

/** Antes → después de lo que cambió, tal como lo guardó la auditoría. */
function ChangeDiff({ changes }: { changes: HistoryEntry['changes'] }) {
  const antes = changes?.old;
  const despues = changes?.new;
  if (!antes || !despues) return null;

  const texto = (clave: string, valor: unknown) => {
    if (valor === null || valor === '' || valor === undefined) return '—';
    if (clave === 'result') return RESULTADOS[String(valor)] ?? String(valor);
    if (clave === 'inspected_at' || clave === 'expires_at') return formatDate(String(valor));
    return String(valor);
  };

  // Corrección de un dictamen: lo que había en cada equipo y lo que quedó.
  const lineas = Array.isArray(antes.lines) ? (antes.lines as LineaAntes[]) : null;
  if (lineas) {
    return (
      <div className="mt-2 rounded-xs bg-[var(--surface-sunken)] px-3 py-2 text-[11.5px]">
        <ul className="space-y-1">
          {lineas.map((linea, indice) => (
            <li key={indice}>
              <span className="font-medium">{linea.equipo ?? 'Equipo'}</span>{': '}
              <span className="text-[var(--text-muted)] line-through">
                {texto('result', linea.result)}
                {linea.expires_at ? ` · vence ${formatDate(linea.expires_at)}` : ''}
              </span>
              {' → '}
              <span className="font-medium">
                {texto('result', despues.result)}
                {despues.expires_at ? ` · vence ${formatDate(String(despues.expires_at))}` : ''}
              </span>
            </li>
          ))}
        </ul>
      </div>
    );
  }

  const claves = Object.keys(despues).filter((clave) => clave in antes);
  if (claves.length === 0) return null;

  return (
    <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-xs bg-[var(--surface-sunken)] px-3 py-2 text-[11.5px]">
      {claves.map((clave) => (
        <div key={clave} className="contents">
          <dt className="text-[var(--text-secondary)]">{CAMPOS[clave] ?? clave}</dt>
          <dd>
            <span className="text-[var(--text-muted)] line-through">{texto(clave, antes[clave])}</span>
            {' → '}
            <span className="font-medium">{texto(clave, despues[clave])}</span>
          </dd>
        </div>
      ))}
    </dl>
  );
}

function Metric({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="bg-[var(--surface-raised)] p-4">
      <p className="text-[11px] font-medium text-[var(--text-secondary)]">{label}</p>
      <div className="mt-2">{children}</div>
    </div>
  );
}
